# Merge `.on`/`.at` into `.in`/`.remindme`

## Problem

The reminder commands are split across two handlers in `scripts/remindme/remindme.php`:

- `in()` (commands `.in`, `.remindme`) — uses `parseDuration()` (durations + natural-language dates), and honors the per-user timezone set via `.settz`.
- `at()` (commands `.at`, `.on`) — uses `makeArgs()` + `Carbon`, and **ignores** the saved timezone entirely (it falls back to the PHP default, which is UTC on the server).

`.in` recently gained natural-language date support and timezone awareness (see `2026-06-05-user-timezones` and `2026-06-05-natural-language-duration-parser`), so the two commands now overlap heavily in intent. The split is the reason `.on 10am tomorrow dog` reports `10:00:00 UTC` even after `.settz America/Los_Angeles`.

## Goal

Consolidate `.on`/`.at` as true aliases of `.in`/`.remindme`, all routed through `parseDuration()`, so there is a single timezone-aware reminder path. The merge must be **lossless**: the two forms `.on`/`.at` could express that `parseDuration()` currently cannot must be added to the parser first.

## Scope of changes

Two gaps in `parseDuration()` block a clean merge. Both are closed in `library/Duration.inc` before the command consolidation.

### Gap 1 — "time before today/tomorrow"

`parseDuration()` understands `tomorrow 10am` but returns `null` for `10am tomorrow`. Returning `null` here is currently **deliberate**: `parseDurationDateMatch()` has a guard (Duration.inc ~line 543-548) that bails when a bare time is followed by `today`/`tomorrow`, with a comment telling users to rephrase to `tomorrow 8pm`.

**Fix:** support the form instead of rejecting it.

- Add a pattern in `parseDurationDate()` matching `timePattern + (today|tomorrow)`, placed **before** the bare-time pattern (currently the last pattern, ~line 328) so it wins for this form.
- Handle it in `parseDurationDateMatch()`: the time components are parsed from the leading `timePattern`; the base date is shifted by the today/tomorrow offset (`+1 day` for tomorrow) before the time-of-day is applied, reusing the existing TZ-aware `DateTime`/`baseDt` machinery.
- **Remove** the bail guard at ~line 543-548. It exists only to reject `<time> today/tomorrow`; once the new pattern handles that form ahead of the bare-time branch, the guard is fully unreachable. (The bare-time branch itself stays, for genuine bare-time input like `10am feed the dog`.)

### Gap 2 — inline timezone abbreviation

`parseDuration()` does not consume a timezone abbreviation attached to a time. `11pm EDT eat ice cream` parses `11pm` but leaves `EDT` in the remainder (so "EDT" becomes part of the reminder message), and resolves the time in UTC rather than EDT. Old `.at` handled this because it handed the whole token to `Carbon`.

**Fix:** a preprocessing step that recognizes a timezone abbreviation **adjacent to the am/pm time** and uses it as the timezone for that single parse.

- Implemented at the top of `parseDurationDate()` (a single, central location so every pattern benefits).
- Detect the token **immediately following the `am`/`pm`**. If it is an all-caps abbreviation, validate it with `new \DateTimeZone($candidate)` inside a try/catch.
  - If valid: strip it from the input string and set the effective `$tzName` to it (overriding the saved `.settz` timezone for this parse), then proceed with the existing patterns on the cleaned input.
  - If invalid (or not all-caps, or absent): leave the input untouched (the token stays in the remainder as part of the message).
- **Inline TZ overrides `.settz`.** This matches old `.at` behavior (where the explicit abbreviation won) and is the intuitive reading of `11pm EDT`.

**Why preprocessing instead of editing `timePattern`:** adding a capture group to `timePattern` would shift the numeric `$m[]` indices and the `$timeOffset` arithmetic used throughout `parseDurationDateMatch()`, which is high off-by-one risk. Preprocessing is adjacency-faithful (the abbreviation must follow the time, exactly as old `.at` required), touches none of the group math, and benefits every date pattern at once.

**Ambiguity note:** timezone abbreviations are inherently ambiguous globally (e.g. `CST`). Resolution follows PHP's `DateTimeZone`, identical to what the old `Carbon`-based path did, so no new ambiguity is introduced.

## Command consolidation — `scripts/remindme/remindme.php`

- Add `"at"`, `"on"` to `in()`'s command attribute:
  ```php
  #[Cmd("in", "remindme", "at", "on")]
  ```
- Update `in()`'s `#[Syntax]` and `#[Desc]` to describe the unified, free-form behavior: accepts a duration (`1h30m`, `2 days`) or a date/time (`tomorrow 3pm`, `next tuesday`, `aug 15th`, `10am tomorrow`); honors the saved `.settz` timezone or an inline abbreviation (`11pm EDT`); the reminder message is the trailing remainder — **no quotes required**.
- **Delete** the `at()` method (currently ~lines 141-181).
- Remove the now-unused `use function knivey\tools\makeArgs;` import, after confirming `makeArgs` is not referenced elsewhere in the file.
- Help text needs no separate edit — it is auto-generated from the `#[Cmd]`/`#[Desc]`/`#[Syntax]` attributes (see `scripts/help/help.php`).

## Behavior after the change

All four triggers (`.in`, `.remindme`, `.at`, `.on`) behave identically:

| Input | Result |
|------|--------|
| `.in 1h30m feed cat` | duration, TZ-independent |
| `.on tomorrow 10am dog` | date, uses `.settz` TZ |
| `.on 10am tomorrow dog` | date, uses `.settz` TZ (Gap 1) |
| `.at 11pm EDT eat ice cream` | date, inline EDT overrides `.settz` (Gap 2) |
| `.on next friday 10am thing` | date, uses `.settz` TZ |

Confirmation messages render the target time in the resolved timezone (already done in `in()` via `Carbon::createFromTimestamp($targetTime, $tzName)`).

## Out of scope / non-goals

- No changes to reminder storage, delivery, the `reminder` entity, or the `UserTimezone` entity.
- No backward-compatibility shim for the old quoted `.on`/`.at` syntax (decided "go clean"). Users simply drop the quotes.
- No support for full Olson names inline (e.g. `10am America/New_York`); users set those via `.settz`.
- Not adding the `remindme` entities directory as a Doctrine annotation path (unrelated to this work).

## Tests — `tests/Duration/ParseDurationTest.php`

Add cases in the existing style (call `parseDuration()`, assert `seconds`/`remainder`/`targetTime`):

- `10am tomorrow <msg>` — correct target day (tomorrow) and time, clean remainder (Gap 1).
- `10am today <msg>` — resolves to today at that time (Gap 1).
- `11pm EDT <msg>` — abbreviation consumed, `remainder` does **not** contain `EDT`, `targetTime` is the EDT instant (Gap 2).
- `10am tomorrow <msg>` combined with a saved TZ — target in the saved TZ (Gap 1 + TZ together).
- Adjacency: `tomorrow 10am EDT <msg>` peels `EDT`; `10am tomorrow NASA launch` does **not** peel `NASA` (not adjacent to `am`/`pm` — `tomorrow` sits between), so `NASA launch` stays in the remainder.
- Regression: `tomorrow 10am <msg>` and existing duration/date cases still pass unchanged.

No new tests are needed for the deleted `at()` method; its behavior is now covered by `parseDuration()` plus the existing `tests/Remindme/DeliverTest.php` for delivery.

## Verification

- `composer test` (full PHPUnit suite).
- `vendor/bin/phpstan analyse library/Duration.inc scripts/remindme/ --no-progress` — scoped to touched paths (the repo carries a large pre-existing PHPStan baseline).
- Manual spot-check via a `parseDuration()` harness for the tabled inputs above.

## Files changed

| File | Change |
|------|--------|
| `library/Duration.inc` | Gap 1 (time-before-today/tomorrow pattern + handling, relax bail guard); Gap 2 (TZ-abbreviation preprocessing in `parseDurationDate`) |
| `scripts/remindme/remindme.php` | Route `.at`/`.on` into `in()`; update `#[Cmd]`/`#[Syntax]`/`#[Desc]`; delete `at()`; drop unused `makeArgs` import |
| `tests/Duration/ParseDurationTest.php` | New cases for Gap 1, Gap 2, and regressions |
