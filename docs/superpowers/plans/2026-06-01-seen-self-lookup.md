# Seen Self-Lookup Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop `.seen <own_nick>` from returning the invoker's own just-typed `.seen` line; instead return the previous sighting of that nick.

**Architecture:** Capture each nick's prior in-memory queue entry into a parallel `$previousUpdates` map before `updateSeen()` overwrites it. In the `seen()` command method, short-circuit self-lookups to read from `$previousUpdates` (falling back to DB without flushing when absent). Extract the existing reply formatting into a private helper to avoid duplication. `saveSeens()` must **not** clear `$previousUpdates` so it survives the event-loop race where the periodic flush fires between the synchronous chat listener and the deferred command body.

**Tech Stack:** PHP 8.1+, Doctrine ORM, Carbon, Symfony String. No new dependencies.

**Spec:** `docs/superpowers/specs/2026-06-01-seen-self-lookup-design.md`

---

### Task 1: Add `$previousUpdates` property and capture prior state in `updateSeen()`

**Files:**
- Modify: `scripts/seen/seen.php` (property declaration near line 67, `updateSeen()` method at lines 69-84)

- [ ] **Step 1: Add the `$previousUpdates` property**

In `scripts/seen/seen.php`, immediately after the existing `$updates` property declaration (around line 67), add:

```php
    /**
     * Prior in-memory update per nick, captured before being overwritten by
     * the next updateSeen() for that nick. Used by self-lookup to avoid
     * returning the invoker's own current line.
     *
     * @var array<string, entities\seen>
     */
    private array $previousUpdates = [];
```

- [ ] **Step 2: Modify `updateSeen()` to capture the prior entry before overwriting**

Replace the entire `updateSeen()` method (currently lines 69-84):

```php
    function updateSeen(string $action, string $chan, string $nick, string $text): void
    {
        $orig_nick = $nick;
        $nick = strtolower($nick);
        $chan = strtolower($chan);

        $ent = new entities\seen();
        $ent->nick = $nick;
        $ent->orig_nick = $orig_nick;
        $ent->chan = $chan;
        $ent->text = $text;
        $ent->action = $action;
        $ent->network = $this->network;
        //Don't save yet, massive floods will destroy us with so many writes
        $this->updates[$nick] = $ent;
    }
```

with:

```php
    function updateSeen(string $action, string $chan, string $nick, string $text): void
    {
        $orig_nick = $nick;
        $nick = strtolower($nick);
        $chan = strtolower($chan);

        // Stash the prior in-memory entry before overwriting, for self-lookup.
        // Not cleared in saveSeens() so it survives the event-loop race where
        // the periodic flush fires between the synchronous chat listener and
        // the deferred command body.
        if (isset($this->updates[$nick])) {
            $this->previousUpdates[$nick] = $this->updates[$nick];
        }

        $ent = new entities\seen();
        $ent->nick = $nick;
        $ent->orig_nick = $orig_nick;
        $ent->chan = $chan;
        $ent->text = $text;
        $ent->action = $action;
        $ent->network = $this->network;
        //Don't save yet, massive floods will destroy us with so many writes
        $this->updates[$nick] = $ent;
    }
```

- [ ] **Step 3: Verify `saveSeens()` does not touch `$previousUpdates`**

`saveSeens()` (lines 86-108) currently ends with `$this->updates = [];`. Confirm it does **not** also reset `$this->previousUpdates`. The existing code already satisfies this invariant; no edit is needed, but add a brief inline comment to make the invariant explicit.

Replace:

```php
        $entityManager->flush();
        $this->updates = [];
    }
```

with:

```php
        $entityManager->flush();
        $this->updates = [];
        // Intentionally do NOT clear $this->previousUpdates here. Doing so
        // would re-introduce the self-lookup bug via the event-loop race
        // where this periodic flush fires between the chat listener and the
        // deferred .seen command body.
    }
```

- [ ] **Step 4: Run static analysis**

Run: `composer phpstan`
Expected: PASS (no errors)

Run: `vendor/bin/psalm`
Expected: PASS (no errors)

- [ ] **Step 5: Commit**

```bash
git add scripts/seen/seen.php
git commit -m "Capture prior seen entry per nick before in-memory overwrite"
```

---

### Task 2: Extract reply formatting into `formatSeenReply()` helper

**Files:**
- Modify: `scripts/seen/seen.php` (the formatting block inside `seen()` at lines 47-59, plus a new private method)

This task only refactors the existing reply formatting into a reusable helper. No behavior change. Doing this before the self-lookup short-circuit keeps the diff small and lets the helper be reused by both the DB path and the in-memory path.

- [ ] **Step 1: Add the `formatSeenReply()` helper method**

In `scripts/seen/seen.php`, immediately before the `seen()` command method (around line 22), add:

```php
    /**
     * Format a seen entity as the reply line for a given target channel.
     */
    private function formatSeenReply(entities\seen $seen, string $targetChan): string
    {
        try {
            $ago = (new Carbon($seen->time))->diffForHumans(
                Carbon::now(),
                CarbonInterface::DIFF_RELATIVE_TO_NOW,
                true,
                3
            );
        } catch (\Exception $e) {
            echo $e->getMessage();
            $ago = "??? ago";
        }
        if ($targetChan != $seen->chan) {
            return "{$seen->orig_nick} was last active in another channel {$ago}";
        }
        $n = "<{$seen->orig_nick}>";
        if ($seen->action == "action") {
            $n = "* {$seen->orig_nick}";
        }
        if ($seen->action == "notice") {
            $n = "[{$seen->orig_nick}]";
        }
        $text = $seen->getText();
        return "seen {$ago}: $n {$text}";
    }
```

Note: `$ago` is computed once at the top and used in both the cross-channel and same-channel branches, matching the existing behavior at `seen.php:42-59`.

- [ ] **Step 2: Replace the inline formatting in `seen()` with a call to the helper**

Replace lines 41-59 of `scripts/seen/seen.php` (the try/catch + chan-mismatch + action prefix + final `pm`):

```php
        $entityManager->refresh($seen);
        try {
            $ago = (new Carbon($seen->time))->diffForHumans(Carbon::now(), CarbonInterface::DIFF_RELATIVE_TO_NOW, true, 3);
        } catch (\Exception $e) {
            echo $e->getMessage();
            $ago = "??? ago";
        }
        if ($args->chan != $seen->chan) {
            $bot->pm($args->chan, "{$seen->orig_nick} was last active in another channel $ago");
            return;
        }
        $n = "<{$seen->orig_nick}>";
        if ($seen->action == "action") {
            $n = "* {$seen->orig_nick}";
        }
        if ($seen->action == "notice") {
            $n = "[{$seen->orig_nick}]";
        }
        $text = $seen->getText();
        $bot->pm($args->chan, "seen {$ago}: $n {$text}");
```

with:

```php
        $entityManager->refresh($seen);
        $bot->pm($args->chan, $this->formatSeenReply($seen, $args->chan));
```

- [ ] **Step 3: Run static analysis**

Run: `composer phpstan`
Expected: PASS (no errors)

Run: `vendor/bin/psalm`
Expected: PASS (no errors)

- [ ] **Step 4: Commit**

```bash
git add scripts/seen/seen.php
git commit -m "Extract seen reply formatting into formatSeenReply helper"
```

---

### Task 3: Add self-lookup short-circuit in `seen()` command

**Files:**
- Modify: `scripts/seen/seen.php` (the `seen()` command method at lines 22-60)

- [ ] **Step 1: Add the self-lookup short-circuit at the top of `seen()`**

In `scripts/seen/seen.php`, replace the body of the `seen()` command method. The current body starts at line 23 with `$nick = u($cmdArgs['nick'])->lower();`. Replace from that line through the closing `}` of the method (line 60) with:

```php
    function seen(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
    {
        global $entityManager;
        $nick = u($cmdArgs['nick'])->lower();
        if ($nick == u($bot->getNick())->lower()) {
            $bot->pm($args->chan, "I'm here bb");
            return;
        }

        $invokerNick = u($args->nick)->lower();
        if ($nick === $invokerNick) {
            // Self-lookup: use the prior in-memory entry captured before
            // updateSeen() queued the invoker line, so we don't return the
            // invoker itself. Do NOT call saveSeens() here.
            if (isset($this->previousUpdates[$nick])) {
                $bot->pm($args->chan, $this->formatSeenReply($this->previousUpdates[$nick], $args->chan));
                return;
            }
            // No prior in-memory entry; fall through to DB without flushing,
            // so the invoker line (still in $this->updates) is not persisted
            // before the query.
            $seen = $entityManager->getRepository(entities\seen::class)->findOneBy([
                "network" => $this->network,
                "nick" => $nick
            ]);
            if (!$seen) {
                $bot->pm($args->chan, "I've never seen {$cmdArgs['nick']} in my whole life");
                return;
            }
            $entityManager->refresh($seen);
            $bot->pm($args->chan, $this->formatSeenReply($seen, $args->chan));
            return;
        }

        $this->saveSeens();

        $seen = $entityManager->getRepository(entities\seen::class)->findOneBy([
            "network" => $this->network,
            "nick" => $nick
        ]);
        if (!$seen) {
            $bot->pm($args->chan, "I've never seen {$cmdArgs['nick']} in my whole life");
            return;
        }
        $entityManager->refresh($seen);
        $bot->pm($args->chan, $this->formatSeenReply($seen, $args->chan));
    }
```

Key points:
- `$args->nick` is the invoking user's nick (from the IRC `ChatEvent`).
- `$cmdArgs['nick']` is the nick argument to `.seen <nick>`.
- Self-lookup path: read `$this->previousUpdates[$nick]` first; if absent, query DB without calling `saveSeens()`; otherwise reply and return.
- Non-self path: unchanged (`saveSeens()` + DB query).

- [ ] **Step 2: Run static analysis**

Run: `composer phpstan`
Expected: PASS (no errors)

Run: `vendor/bin/psalm`
Expected: PASS (no errors)

- [ ] **Step 3: Commit**

```bash
git add scripts/seen/seen.php
git commit -m "Short-circuit .seen self-lookup to skip the invoker line"
```

---

### Task 4: Manual verification

No test framework is configured. Verify against the bot in a channel.

- [ ] **Step 1: Basic self-lookup with prior chat in memory**

Action: Bob types "hello", then immediately ".seen bob".
Expected bot reply: `seen 0s ago: <bob> hello` (or similar; uses the prior in-memory entry).

- [ ] **Step 2: First-ever self-lookup**

Action: On a fresh DB (or with a nick that has never spoken), user types ".seen freshnick" where freshnick matches their own nick.
Expected bot reply: `I've never seen freshnick in my whole life`.

- [ ] **Step 3: Self-lookup after flush has run**

Action: Bob types "hello", waits ≥16 seconds (one periodic flush interval), then types ".seen bob".
Expected bot reply: `seen 16s ago: <bob> hello` (formatting may vary by Carbon's relative-time output).

- [ ] **Step 4: Cross-channel self-lookup**

Action: Bob speaks in #a, then in #b types ".seen bob".
Expected bot reply: `bob was last active in another channel <ago>`.

- [ ] **Step 5: Action and notice variants**

Action: Bob types `/me waves`, then immediately ".seen bob".
Expected bot reply: `seen 0s ago: * bob waves`.

Action: Bob sends a channel notice, then immediately ".seen bob".
Expected bot reply: `seen 0s ago: [bob] <text>`.

- [ ] **Step 6: Other-nick lookup unchanged (regression check)**

Action: Alice types "hi", then Bob types ".seen alice".
Expected bot reply: `seen 0s ago: <alice> hi` (existing behavior preserved).

- [ ] **Step 7: Rapid-fire self-lookup**

Action: Bob types "a", "b", "c", ".seen bob" in rapid succession.
Expected bot reply: `seen 0s ago: <bob> c` (the message immediately prior to the invoker, because each updateSeen overwrites updates[bob] and stashes the prior into previousUpdates[bob]).

- [ ] **Step 8: PHPStan still clean**

Run: `composer phpstan`
Expected: PASS (no errors).
