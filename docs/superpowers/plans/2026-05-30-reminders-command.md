# `!reminders` Command Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `!reminders` command to list pending or sent reminders with filtering, sorting, and pagination.

**Architecture:** Single new method `reminders()` on the existing `remindme` class in `scripts/remindme/remindme.php`. Uses Doctrine's `findBy` for simple queries and `\Duration_toString` for time formatting. No new files needed.

**Tech Stack:** PHP 8.1+, Doctrine ORM, knivey/cmdr attribute-based command routing, `\Duration_toString()` helper.

---

### Task 1: Add the `!reminders` command method

**Files:**
- Modify: `scripts/remindme/remindme.php` (add new method after the `at()` method, before `sendDelayed()`)

- [ ] **Step 1: Add the `use` import for the Option attribute**

Add this import at the top of the file alongside the other cmdr attribute imports:

```php
use knivey\cmdr\attributes\Option;
```

Insert after line 7 (`use knivey\cmdr\attributes\Syntax;`).

- [ ] **Step 2: Add the `reminders()` method**

Insert the following method between the `at()` method (ends around line 106) and `sendDelayed()` (starts around line 108). This is a single method — the entire implementation:

```php
    #[Cmd("reminders")]
    #[Syntax("[filter]...")]
    #[Desc("Show your pending reminders on this channel")]
    #[Option("--all", "Show all users' reminders")]
    #[Option("--sort", "Sort by due or created (default: due)")]
    #[Option("--page", "Results per page (default: 10)")]
    #[Option("--sent", "Show sent reminders instead of pending")]
    function reminders($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        global $entityManager;

        $showSent = $cmdArgs->optEnabled('--sent');
        $showAll = $cmdArgs->optEnabled('--all');
        $pageSize = 10;
        if ($cmdArgs->optEnabled('--page')) {
            $pageSize = max(1, (int)$cmdArgs->getOpt('--page'));
        }

        $sortBy = 'due';
        if ($cmdArgs->optEnabled('--sort')) {
            $sortBy = strtolower($cmdArgs->getOpt('--sort'));
            if (!in_array($sortBy, ['due', 'created'])) {
                $bot->pm($args->chan, "Invalid sort option, use due or created");
                return;
            }
        }

        $criteria = [
            "network" => $this->network,
            "sent" => $showSent,
            "chan" => $args->chan,
        ];
        if (!$showAll) {
            $criteria["nick"] = $args->nick;
        }

        $repo = $entityManager->getRepository(reminder::class);

        if (isset($cmdArgs['filter']) && $cmdArgs['filter'] !== '') {
            $filter = str_replace('*', '%', $cmdArgs['filter']);
            $qb = $repo->createQueryBuilder('r');
            $qb->where('r.network = :network')
               ->andWhere('r.sent = :sent')
               ->andWhere('r.chan = :chan')
               ->setParameter('network', $this->network)
               ->setParameter('sent', $showSent)
               ->setParameter('chan', $args->chan);

            if (!$showAll) {
                $qb->andWhere('r.nick = :nick')
                   ->setParameter('nick', $args->nick);
            }

            $qb->andWhere('r.msg LIKE :filter')
               ->setParameter('filter', $filter);

            $sortField = $sortBy === 'created' ? 'r.created' : 'r.at';
            $sortDir = $showSent ? 'DESC' : 'ASC';
            $qb->orderBy($sortField, $sortDir);

            $rs = $qb->getQuery()->getResult();
        } else {
            $orderByField = $sortBy === 'created' ? 'created' : 'at';
            $orderByDir = $showSent ? 'DESC' : 'ASC';
            $rs = $repo->findBy($criteria, [$orderByField => $orderByDir]);
        }

        if (count($rs) == 0) {
            $noun = $showSent ? "sent reminders" : "pending reminders";
            if ($showAll) {
                $bot->pm($args->chan, "No $noun found");
            } else {
                $bot->pm($args->chan, "You have no $noun");
            }
            return;
        }

        $total = count($rs);
        $pages = (int)ceil($total / $pageSize);
        $pageResults = array_slice($rs, 0, $pageSize);

        foreach ($pageResults as $r) {
            $msg = $r->msg;
            if (mb_strlen($msg) > 80) {
                $msg = mb_substr($msg, 0, 80) . '...';
            }

            if ($showSent) {
                $dueStr = "due " . \Duration_toString(time() - $r->at) . " ago";
            } else {
                $dueStr = "due in " . \Duration_toString($r->at - time());
            }

            $createdStr = "";
            if ($r->created !== null) {
                $createdStr = " (created " . \Duration_toString(time() - $r->created->getTimestamp()) . " ago)";
            }

            $bot->pm($args->chan, "[#{$r->id}] {$dueStr}{$createdStr} {$msg}");
        }

        if ($pages > 1) {
            $bot->pm($args->chan, "Page 1/{$pages} — use --page={$pageSize} to see more");
        }
    }
```

**Key implementation notes for the implementer:**
- The `cmdr` `#[Option]` attribute is used for all flags — `--all`, `--sort=due|created`, `--page=N`, `--sent`.
- `cmdArgs->optEnabled('--flag')` checks if a boolean flag was passed.
- `cmdArgs->getOpt('--flag')` gets the value of a `--flag=value` option (returns `true` if flag present without `=`, `false` if not present).
- `cmdArgs['filter']` accesses the optional `[filter]...` syntax arg — may be null/empty if not provided.
- Doctrine `findBy` is used for unfiltered queries (simple criteria + orderBy). When a filter is provided, `createQueryBuilder` is used to add the `LIKE` clause.
- The `*` to `%` wildcard replacement happens before the LIKE query.
- For `--sent` mode, sorting is DESC (most recent first). For pending mode, sorting is ASC (soonest first).
- The `created` field is a `\DateTimeImmutable` (may be null for old rows), so we check `$r->created !== null` before formatting.
- `\Duration_toString()` is a project helper (in `library/Duration.inc`) that formats seconds into a human-readable duration string.
- Message truncation uses `mb_strlen`/`mb_substr` for proper multi-byte handling.
- Pagination currently shows page 1 only. The footer suggests `--page=N` to see more (this is the page size, which effectively lets the user increase results shown).

- [ ] **Step 3: Run static analysis**

Run: `vendor/bin/phpstan analyse --memory-limit=512M 2>&1 | grep -E "remindme|reminder"`

Expected: No errors related to `remindme.php` or `reminder.php`. (Pre-existing errors in other files are acceptable.)

- [ ] **Step 4: Run formatter**

Run: `vendor/bin/php-cs-fixer fix scripts/remindme/remindme.php`

- [ ] **Step 5: Commit**

```bash
git add scripts/remindme/remindme.php
git commit -m "Add !reminders command to list pending/sent reminders"
```
