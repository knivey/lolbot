# Seen: Fix `.seen <own_nick>` Returning the Invoking Line

## Problem

When a user runs `.seen <own_nick>`, the bot replies with the user's own
just-typed `.seen` command, formatted as "seen 1s ago: <nick> .seen bob".

Root cause (event order in `scripts/seen/seen.php` + `lolbot.php`):

1. IRC `chat` event fires.
2. `seen.php`'s chat listener (registered first via `init()` in the constructor
   at `lolbot.php:174`) runs and calls `updateSeen()`, which queues the user's
   current message into `$this->updates[$nick]`. Note that `updateSeen()`
   unconditionally overwrites the previous queue entry for that nick — the
   prior in-flight update is lost unless the periodic 15s flush has already
   persisted it to the DB.
3. `lolbot.php:267`'s chat listener runs and defers command dispatch via
   `async(...)` at `lolbot.php:325`.
4. The deferred `seen()` method calls `$this->saveSeens()` at `seen.php:30`,
   which flushes the just-queued message to the DB.
5. The DB lookup finds the user's own current message and returns it.

## Requirements

- `.seen <own_nick>` must return the **previous** sighting of that nick (the
  message immediately before the `.seen` invocation), formatted identically to
  a normal `.seen` response.
- If no previous sighting exists (first-ever chat, or only the invoker line in
  memory), `.seen <own_nick>` must reply with the existing "I've never seen X
  in my whole life" message.
- Behavior for `.seen <other_nick>` is unchanged.
- The fix must be robust against the 15-second periodic flush window: a nick's
  previous message may still be in `$this->updates` (not yet persisted) when
  `.seen` is invoked. The fix must return that previous message, not the
  last-flushed DB row.
- The fix must be robust against the Revolt event-loop race in which the
  periodic `saveSeens()` timer fires between the synchronous chat listener
  (which captures state) and the deferred command body (which reads it).

## Non-goals

- No general "never return the invoker's line" semantics for other-nick
  lookups. The bug only manifests for self-lookup.
- No schema changes. The seen table continues to store one row per `(network,
  nick)`.
- No changes to listener registration order in `lolbot.php`. Reordering alone
  would not fix the bug because command dispatch is wrapped in `async()` and
  therefore deferred past the synchronous event chain regardless.
- No changes to the `async()` wrapper around command dispatch in
  `lolbot.php:325`.

## Design

Add a parallel in-memory map `$previousUpdates` that holds, per nick, the
entity that was in `$this->updates[$nick]` immediately before the most recent
`updateSeen()` call for that nick.

```php
/**
 * Previous in-memory update per nick, captured before being overwritten by
 * the next updateSeen() for that nick. Used by self-lookup to avoid
 * returning the invoker's own current line.
 *
 * @var array<string, entities\seen>
 */
private array $previousUpdates = [];
```

### `updateSeen()` — capture prior state before overwriting

Before assigning the new entity to `$this->updates[$nick]`, stash the existing
entry (if any) into `$this->previousUpdates[$nick]`:

```php
function updateSeen(string $action, string $chan, string $nick, string $text): void
{
    $orig_nick = $nick;
    $nick = strtolower($nick);
    $chan = strtolower($chan);

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
    $this->updates[$nick] = $ent;
}
```

### `saveSeens()` — flush `updates` only, never clear `previousUpdates`

`saveSeens()` continues to clear `$this->updates` after flushing, but must
**not** clear `$this->previousUpdates`. Clearing `previousUpdates` would
re-introduce the bug via the event-loop race described under "Race" below.

`previousUpdates` is naturally bounded: each entry is overwritten on the next
`updateSeen()` for the same nick. Worst-case size equals the number of unique
nicks that have ever chatted, on the same order as `updates` ever held.

### `seen()` command — short-circuit self-lookup

At the top of the `seen()` method, before calling `saveSeens()`, detect
self-lookup by comparing the lowercased target nick against the lowercased
invoking `$args->nick`. On match:

1. Read `$this->previousUpdates[$nick]` (the snapshot from before the invoker
   was queued).
2. If present, format and reply with that entity using the same formatting
   rules as the existing DB path (chan-mismatch, action prefix, relative
   time). Return without calling `saveSeens()`.
3. If absent, fall through to a DB query **without** calling `saveSeens()`
   first (so the invoker's just-queued message is not persisted before the
   query). If the DB has no row, reply with the existing "never seen" message.

For non-self lookups, behavior is unchanged: `saveSeens()` then DB query.

### Race analysis

The periodic `EventLoop::repeat(15, $this->saveSeens(...))` timer registered in
`init()` can fire between the synchronous chat listener and the deferred
`async()` command body. If it fires, it flushes and clears `$this->updates`.

Because `saveSeens()` is changed to **not** clear `$this->previousUpdates`,
the captured previous-entry survives the race. The command body reads
`$this->previousUpdates[$nick]` and returns it correctly.

### Behavior matrix

| Scenario | `previousUpdates[$nick]` | `updates[$nick]` | DB row | Reply |
|---|---|---|---|---|
| First-ever chat is `.seen self` | absent | `.seen self` | none | "never seen" |
| Previous chat in same flush window | "hello" | `.seen self` | "older" | "hello" |
| Previous chat already flushed | "hello" | `.seen self` | "hello" | "hello" |
| Periodic flush fires between listener and command | "hello" | (cleared) | ".seen self" | "hello" |
| `.seen other` | n/a | n/a | anything | unchanged |

### Time formatting

The `previousUpdates` entity carries a `time` field set at entity construction
(in `entities\seen::__construct`, which uses `time()`/`new DateTime`). The
existing relative-time formatting via
`(new Carbon($seen->time))->diffForHumans(...)` works unchanged for in-memory
entities.

## Implementation

Single file change: `scripts/seen/seen.php`.

1. Add `private array $previousUpdates = [];` property with docblock.
2. In `updateSeen()`, capture `$this->updates[$nick]` into
   `$this->previousUpdates[$nick]` before overwriting (guarded with
   `isset`).
3. Confirm `saveSeens()` only clears `$this->updates`, not
   `$this->previousUpdates`. (No change needed if `saveSeens()` already only
   touches `$this->updates`; verify and document the invariant in a
   comment.)
4. In the `seen()` command method, add a self-lookup short-circuit at the
   top:
   - Compute `$invokerNick = u($args->nick)->lower()` once.
   - If `$nick === $invokerNick`:
     - If `isset($this->previousUpdates[$nick])`: format that entity using
       the existing chan-mismatch / action-prefix / Carbon time logic and
       reply. `return`.
     - Else: query DB **without** calling `saveSeens()`. Reply with the
       result or "never seen". `return`.
   - Else: fall through to the existing logic (`saveSeens()` + DB query).

Extract the formatting block (chan-mismatch message, action prefix, Carbon
`diffForHumans`, final `pm`) into a private helper `formatSeenReply()` to
avoid duplicating it between the DB path and the in-memory path. The helper
takes an `entities\seen` and the target channel, returns the reply string.

## Testing

No test framework is configured. Manual verification:

1. **Basic self-lookup, prior chat in memory:** Bob says "hello", then
   immediately `.seen bob`. Expected: "seen 0s ago: <bob> hello".
2. **First-ever self-lookup:** fresh DB, Bob says `.seen bob`. Expected: "I've
   never seen Bob in my whole life".
3. **Self-lookup after flush:** Bob says "hello", wait 16s, Bob says `.seen
   bob`. Expected: "seen 16s ago: <bob> hello".
4. **Cross-channel self-lookup:** Bob says "hello" in #a, then `.seen bob` in
   #b. Expected: "Bob was last active in another channel 0s ago".
5. **Action and notice variants:** Bob does `/me waves` then `.seen bob`.
   Expected: "seen 0s ago: * bob waves". Same for notices.
6. **Other-nick lookup unchanged:** Alice says "hi", Bob says `.seen alice`.
   Expected: "seen 0s ago: <alice> hi" (unchanged from current behavior).
7. **Rapid-fire self-lookup:** Bob says "a", "b", "c", `.seen bob` in quick
   succession. Expected: "seen 0s ago: <bob> c".

## Static analysis

Run `composer phpstan` after the change. The new property and helper must
satisfy the existing PHPStan level 9 configuration.
