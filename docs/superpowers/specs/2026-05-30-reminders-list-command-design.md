# `!reminders` Command Design

## Overview

Add a `!reminders` command to the `remindme` script that lets users list their pending or recently sent reminders. Supports filtering, sorting, pagination, and viewing other users' reminders.

## Command Definition

**File:** `scripts/remindme/remindme.php` — new method on the `remindme` class

**Attributes:**

```php
#[Cmd("reminders")]
#[Syntax("[filter]...")]
#[Desc("Show your pending reminders on this channel")]
#[Option("--all", "Show all users' reminders")]
#[Option("--sort", "Sort by due or created (default: due)")]
#[Option("--page", "Results per page (default: 10)")]
#[Option("--sent", "Show sent reminders instead of pending")]
```

## Behavior

### Query

1. Query `remindme_reminders` where `network` = current network, `chan` = current channel.
   - **Without `--sent`:** filter `sent` = false (pending reminders).
   - **With `--sent`:** filter `sent` = true (sent reminders).
2. Default: also filter by `nick` (current user's nick). With `--all`: skip nick filter.
3. If `[filter]` is provided:
   - Replace `*` with `%` in the filter string for wildcard support.
   - Case-insensitive `LIKE` match on `msg`.
4. Sort:
   - **Without `--sent`:** ascending by `due` (the `at` column) or `created`, per `--sort` value. Default: `due`.
   - **With `--sent`:** descending by `due` (most recently fired first). `--sort` applies the same way (`due` or `created`, descending).

### Output

Each reminder line:

**Without `--sent` (pending):**

```
[#ID] due in <duration> (created <ago>) <msg>
```

**With `--sent` (already fired):**

```
[#ID] due <time-ago> (created <ago>) <msg>
```

- `created <ago>` is shown only if the reminder has a creation time. Older reminders created before the field was added will omit it silently.
- `msg` is truncated to 80 characters with `...` appended if longer.

### Pagination

- Default page size: 10. Configurable via `--page=N`.
- If total results fit on one page: output all lines, no pagination footer.
- If multiple pages: show all lines for the current page, then a footer:
  ```
  Page 1/N — use --page=20 to see more
  ```

### Empty Results

- Without `--all`: `"You have no pending reminders"` (or `"You have no sent reminders"` with `--sent`)
- With `--all`: `"No pending reminders found"` (or `"No sent reminders found"` with `--sent`)

## Example Usage

```
!reminders
!reminders --all
!reminders oven*
!reminders --all --sort=created --page=20
!reminders check the*
!reminders --sent
!reminders --sent --all
!reminders --sent --sort=created
```

## Example Output

```
<bot> [#3] due in 2h30m (created 5m ago) remember to check the oven
<bot> [#7] due in 1d2h (created 1h ago) dentist appointment at 3pm on tuesday and ...
<bot> Page 1/2 — use --page=20 to see more
```

```
<bot> [#3] due 5m ago (created 2h35m ago) remember to check the oven
<bot> [#12] due 1h ago dentist appointment
```
