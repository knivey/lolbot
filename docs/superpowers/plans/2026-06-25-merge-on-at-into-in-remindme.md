# Merge `.on`/`.at` into `.in`/`.remindme` (TZ-aware) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Consolidate the duplicate reminder handlers (`.on`/`.at` and `.in`/`.remindme`) into a single timezone-aware path through `parseDuration()`, after closing two parser gaps that block a lossless merge.

**Architecture:** All four trigger names route to one handler (`in()`). Two `parseDuration()` gaps are closed first in `library/Duration.inc`: (1) "time before today/tomorrow" ordering (`10am tomorrow`), and (2) inline timezone abbreviations adjacent to the am/pm time (`11pm EDT`). An effective-timezone field on `ParseResult` lets the confirmation message render in the resolved zone (saved `.settz` or inline abbrev).

**Tech Stack:** PHP 8.1+, procedural `library/Duration.inc`, Doctrine/Amp `scripts/remindme/remindme.php`, PHPUnit 10.

**Spec:** `docs/superpowers/specs/2026-06-25-merge-on-at-into-in-remindme-design.md`

---

## File Structure

| File | Responsibility | Change |
|------|----------------|--------|
| `library/Duration.inc` | Duration + natural-language date parsing | Gap 1 pattern/branch + remove bail guard; Gap 2 inline-TZ preprocessing; `ParseResult::timezone` field + `withTimezone()` |
| `tests/Duration/ParseDurationTest.php` | Parser tests | Flip 2 null-tests to positive; add Gap 1, Gap 2, and regression cases |
| `scripts/remindme/remindme.php` | Reminder command handler | Route `.at`/`.on` into `in()`; update attrs; render confirmation in resolved TZ; delete `at()`; drop `makeArgs` import |

Run tests with: `vendor/bin/phpunit tests/Duration/ParseDurationTest.php` (fast, scoped) or `composer test` (full suite).

---

## Task 1: Gap 1 — support "time before today/tomorrow" (`10am tomorrow`)

Currently `parseDuration('10am tomorrow ...')` returns `null` *on purpose* (a bail guard rejects it). This task reverses that so the form parses identically to `tomorrow 10am`.

**Files:**
- Modify: `tests/Duration/ParseDurationTest.php:371-401` (flip the two null-tests)
- Modify: `library/Duration.inc:325-329` (add pattern), `:535-548` (add branch + remove bail guard)

- [ ] **Step 1: Flip the two existing null-tests to positive comparison tests**

In `tests/Duration/ParseDurationTest.php`, replace `test_bare_time_followed_by_today_returns_null` (lines 371-385) with:

```php
    public function test_time_before_today_matches_today_time(): void
    {
        $hour = (int) date('G');
        if ($hour + 2 >= 24) {
            $this->markTestSkipped('test requires a future time today');
        }
        $testHour = $hour + 2;
        $ampm = $testHour >= 12 ? 'pm' : 'am';
        $displayHour = $testHour > 12 ? $testHour - 12 : ($testHour === 0 ? 12 : $testHour);

        // "10am today" must parse the same as "today 10am" (time-before-day form).
        $canonical = \parseDuration("today {$displayHour}{$ampm} recycling");
        $reordered = \parseDuration("{$displayHour}{$ampm} today recycling");
        $this->assertNotNull($canonical);
        $this->assertNotNull($reordered);
        $this->assertNotNull($canonical->targetTime);
        $this->assertNotNull($reordered->targetTime);
        $this->assertSame($canonical->targetTime, $reordered->targetTime);
        $this->assertSame('recycling', $reordered->remainder);
    }
```

And replace `test_bare_time_followed_by_tomorrow_returns_null` (lines 387-401) with:

```php
    public function test_time_before_tomorrow_matches_tomorrow_time(): void
    {
        // "3pm tomorrow" must parse the same as "tomorrow 3pm" (time-before-day form).
        $canonical = \parseDuration('tomorrow 3pm feed the dog');
        $reordered = \parseDuration('3pm tomorrow feed the dog');
        $this->assertNotNull($canonical);
        $this->assertNotNull($reordered);
        $this->assertNotNull($canonical->targetTime);
        $this->assertNotNull($reordered->targetTime);
        $this->assertSame($canonical->targetTime, $reordered->targetTime);
        $this->assertSame('feed the dog', $reordered->remainder);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Duration/ParseDurationTest.php`
Expected: FAIL — `test_time_before_today_matches_today_time` and `test_time_before_tomorrow_matches_tomorrow_time` fail because `parseDuration` still returns `null` for the time-before-day form. (All other tests still pass.)

- [ ] **Step 3: Add the "time + today/tomorrow" pattern**

In `library/Duration.inc`, the `$patterns` array (around line 317-329) currently has, near its end:

```php
        '/^' . $timePattern . '\s+(?:next\s+|this\s+)?(' . $dayPattern . ')\b\s*(.*)$/i',
        '/^(\d{4})\s*' . $timeGroup . '\s*(.*)$/i',
        // Bare time (no day given) — caller wants "today" assumed
        '/^(?:at\s+)?' . $timePattern . '\s*(.*)$/i',
    ];
```

Insert one new pattern (for `10am tomorrow` / `10am today`) immediately after the `time + next/this day` line, before the `\d{4}` line:

```php
        '/^' . $timePattern . '\s+(?:next\s+|this\s+)?(' . $dayPattern . ')\b\s*(.*)$/i',
        '/^' . $timePattern . '\s+(today|tomorrow)\b\s*(.*)$/i',
        '/^(\d{4})\s*' . $timeGroup . '\s*(.*)$/i',
        // Bare time (no day given) — caller wants "today" assumed
        '/^(?:at\s+)?' . $timePattern . '\s*(.*)$/i',
    ];
```

- [ ] **Step 4: Add the handling branch and remove the now-dead bail guard**

In `parseDurationDateMatch()` (same file), the time-before-dayname branch ends and the bare-time branch begins around line 535-536:

```php
        return new ParseResult(
            seconds: $targetTime - $now,
            remainder: $remainder,
            targetTime: $targetTime,
        );
    } elseif (!empty($m[1]) && preg_match('/^\d{1,2}$/', $m[1])) {
        // Bare time (no day name) — assume today, but if the time has
        // already passed today, roll to tomorrow so the user gets the
        // next occurrence (e.g. at 10pm, "3am" → tomorrow 3am). Also
        // bail if the user put a day keyword in the wrong spot (e.g.
        // "8pm tomorrow ...") so they get the error instead of a
        // wrong-day reminder; they can rephrase to "tomorrow 8pm".
        if (isset($m[5]) && preg_match('/^(\S+)/', $m[5], $w)) {
            $firstWord = strtolower($w[1]);
            if ($firstWord === 'today' || $firstWord === 'tomorrow') {
                return null;
            }
        }
```

Replace that whole block with: a NEW elseif for time-before-today/tomorrow, followed by the bare-time branch with the bail guard and its "Also bail..." comment sentences removed (the roll-to-tomorrow comment is preserved):

```php
        return new ParseResult(
            seconds: $targetTime - $now,
            remainder: $remainder,
            targetTime: $targetTime,
        );
    } elseif (!empty($m[1]) && preg_match('/^\d{1,2}/', $m[1]) && isset($m[5]) && in_array(strtolower($m[5]), ['today', 'tomorrow'], true)) {
        // Time before today/tomorrow, e.g. "10am tomorrow feed the dog".
        $hours = (int) $m[1];
        $minutes = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
        $seconds = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 0;
        $ampm = strtolower($m[4]);
        if ($ampm === 'pm' && $hours < 12) {
            $hours += 12;
        }
        if ($ampm === 'am' && $hours === 12) {
            $hours = 0;
        }
        if ($tzName !== null) {
            $targetDt = clone $baseDt;
            if (strtolower($m[5]) === 'tomorrow') {
                $targetDt->modify('+1 day');
            }
            $targetDt->setTime($hours, $minutes, $seconds);
            $targetTime = $targetDt->getTimestamp();
        } else {
            $targetTs = $baseTimestamp;
            if (strtolower($m[5]) === 'tomorrow') {
                $targetTs += 86400;
            }
            $targetTime = $targetTs + $hours * 3600 + $minutes * 60 + $seconds;
        }
        $remainder = isset($m[6]) ? trim($m[6]) : '';
        if ($targetTime <= $now + 15) {
            return null;
        }
        return new ParseResult(
            seconds: $targetTime - $now,
            remainder: $remainder,
            targetTime: $targetTime,
        );
    } elseif (!empty($m[1]) && preg_match('/^\d{1,2}$/', $m[1])) {
        // Bare time (no day name) — assume today, but if the time has
        // already passed today, roll to tomorrow so the user gets the
        // next occurrence (e.g. at 10pm, "3am" → tomorrow 3am).
```

> **Note for reviewer (AGENTS.md "never remove comments"):** The removed "Also bail if the user put a day keyword in the wrong spot..." comment described exactly the behavior being deleted here (the `<time> today/tomorrow` rejection). With this task that behavior is intentionally reversed, so the comment would be misleading if kept. The unrelated roll-to-tomorrow comment above the bare-time branch is preserved. The TODO comment on the `at()` method is addressed in Task 3.

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Duration/ParseDurationTest.php`
Expected: PASS — all tests green, including the two flipped comparison tests.

- [ ] **Step 6: Commit**

```bash
git add tests/Duration/ParseDurationTest.php library/Duration.inc
git commit -m "feat(remindme): parse \"10am tomorrow\" time-before-day form"
```

---

## Task 2: Gap 2 — inline timezone abbreviation (`11pm EDT`) + expose effective TZ

`parseDuration` currently lets an inline abbreviation leak into the message and ignores it for the timestamp. This task adds a preprocessing step that recognizes an all-caps zone adjacent to the am/pm time and uses it as the timezone for that parse (overriding the saved `.settz`), and exposes the effective zone on `ParseResult`.

**Files:**
- Modify: `library/Duration.inc:165-172` (`ParseResult`), `:278-341` (`parseDurationDate`), `:331-338` (single return wrap)
- Modify: `tests/Duration/ParseDurationTest.php` (append new tests)

- [ ] **Step 1: Write failing tests**

Append these tests to `tests/Duration/ParseDurationTest.php` (inside the `ParseDurationTest` class, before the closing `}`):

```php
    // --- Inline timezone abbreviation (Gap 2) ---

    public function test_inline_timezone_abbreviation_is_consumed(): void
    {
        $result = \parseDuration('11pm EDT eat ice cream');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertSame('eat ice cream', $result->remainder);
        $this->assertStringNotContainsString('EDT', $result->remainder);
    }

    public function test_inline_timezone_overrides_saved_timezone(): void
    {
        // EDT always differs from America/Los_Angeles by hours, year-round.
        $inline = \parseDuration('11pm EDT eat ice cream', 'America/Los_Angeles');
        $saved  = \parseDuration('11pm eat ice cream', 'America/Los_Angeles');
        $this->assertNotNull($inline);
        $this->assertNotNull($saved);
        $this->assertNotNull($inline->targetTime);
        $this->assertNotNull($saved->targetTime);
        $this->assertNotSame($inline->targetTime, $saved->targetTime);
    }

    public function test_inline_timezone_exposed_on_result(): void
    {
        $result = \parseDuration('11pm EDT eat ice cream');
        $this->assertSame('EDT', $result->timezone);
    }

    public function test_saved_timezone_exposed_on_result(): void
    {
        $result = \parseDuration('tomorrow 10am feed cat', 'America/Los_Angeles');
        $this->assertNotNull($result);
        $this->assertSame('America/Los_Angeles', $result->timezone);
    }

    public function test_non_adjacent_caps_word_not_treated_as_timezone(): void
    {
        // "NASA" is not adjacent to am/pm (tomorrow sits between) and isn't a zone.
        $result = \parseDuration('10am tomorrow NASA launch');
        $this->assertNotNull($result);
        $this->assertSame('NASA launch', $result->remainder);
    }

    public function test_valid_zone_word_in_message_not_peeled(): void
    {
        // "UTC" is a real zone but not adjacent to the am/pm time here.
        $result = \parseDuration('tomorrow 10am meet about UTC stuff');
        $this->assertNotNull($result);
        $this->assertSame('meet about UTC stuff', $result->remainder);
    }

    public function test_inline_zone_with_date_word(): void
    {
        $result = \parseDuration('tomorrow 10am UTC launch');
        $this->assertNotNull($result);
        $this->assertSame('launch', $result->remainder);
        $this->assertSame('UTC', $result->timezone);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Duration/ParseDurationTest.php`
Expected: FAIL — the new tests fail. The `..._exposed_on_result` tests fail because `ParseResult` has no `timezone` property; the peel/override tests fail because `EDT` currently stays in the remainder and the time resolves in the wrong zone.

- [ ] **Step 3: Add a `timezone` field and `withTimezone()` to `ParseResult`**

In `library/Duration.inc`, replace the `ParseResult` class (lines 165-172):

```php
class ParseResult
{
    public function __construct(
        public readonly int $seconds,
        public readonly string $remainder,
        public readonly ?int $targetTime = null,
    ) {}
}
```

with:

```php
class ParseResult
{
    public function __construct(
        public readonly int $seconds,
        public readonly string $remainder,
        public readonly ?int $targetTime = null,
        public readonly ?string $timezone = null,
    ) {}

    public function withTimezone(?string $timezone): self
    {
        return new self($this->seconds, $this->remainder, $this->targetTime, $timezone);
    }
}
```

- [ ] **Step 4: Add the inline-TZ preprocessing to `parseDurationDate`**

In `library/Duration.inc`, `parseDurationDate` currently begins (line 278):

```php
function parseDurationDate(string $input, ?string $tzName = null): ?ParseResult
{
    $dayNames = [
```

Insert the preprocessing block immediately after the opening brace, before `$dayNames`:

```php
function parseDurationDate(string $input, ?string $tzName = null): ?ParseResult
{
    // Inline timezone abbreviation adjacent to the am/pm time, e.g. "11pm EDT".
    // A recognized zone overrides the caller-supplied (saved) timezone for this parse.
    if (preg_match('/(?<=\d)(am|pm)\s+([A-Za-z]{2,5})\b/i', $input, $tzm, PREG_OFFSET_CAPTURE)) {
        $abbr = $tzm[2][0];
        if (ctype_upper($abbr)) {
            try {
                new \DateTimeZone($abbr);
                $start = $tzm[2][1];
                $input = substr($input, 0, $start) . substr($input, $start + strlen($abbr));
                $input = preg_replace('/\s{2,}/', ' ', $input) ?? $input;
                $tzName = $abbr;
            } catch (\Throwable) {
                // not a real zone — leave input and tzName untouched
            }
        }
    }

    $dayNames = [
```

Rationale for the regex `(?<=\d)(am|pm)\s+([A-Za-z]{2,5})\b`: the lookbehind asserts the am/pm follows a digit (the time, e.g. `3pm`, `10:30am`), so it won't fire on words like `spam`; the abbreviation must be 2-5 letters with a word boundary after (so `tomorrow` is never mistaken for a zone — it's too long to land a boundary). `ctype_upper` + `new \DateTimeZone($abbr)` together ensure only real, conventionally-cased zones are honored.

- [ ] **Step 5: Capture the effective timezone at the single return site**

In the same `parseDurationDate`, the pattern loop returns the match result (around lines 331-338):

```php
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $input, $m)) {
            $result = parseDurationDateMatch($m, $dayNames, $monthNames, $ordinals, $tzName);
            if ($result !== null) {
                return $result;
            }
        }
    }
```

Change the return to stamp the effective zone (which includes any inline-abbrev override applied above):

```php
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $input, $m)) {
            $result = parseDurationDateMatch($m, $dayNames, $monthNames, $ordinals, $tzName);
            if ($result !== null) {
                return $result->withTimezone($tzName);
            }
        }
    }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Duration/ParseDurationTest.php`
Expected: PASS — all tests green, including the seven new Gap 2 tests and every existing test (the preprocessing is a no-op for inputs without an am/pm-adjacent zone).

- [ ] **Step 7: Commit**

```bash
git add library/Duration.inc tests/Duration/ParseDurationTest.php
git commit -m "feat(remindme): honor inline TZ abbreviations in date parsing"
```

---

## Task 3: Consolidate `.on`/`.at` into `.in`/`.remindme`

Route the four trigger names to one handler, render the confirmation in the resolved timezone, and delete the now-redundant `at()` method and its `makeArgs` import.

**Files:**
- Modify: `scripts/remindme/remindme.php:13` (drop import), `:32-34` (attrs), `:87-90` (display), `:137-181` (delete `at()`)

- [ ] **Step 1: Confirm `makeArgs` is only used by `at()`**

Run: `rg -n "makeArgs" scripts/remindme/remindme.php`
Expected: exactly three matches — line 13 (the `use function` import), line 138 (inside the `/* */` comment belonging to `at()`), and line 147 (the `$r = makeArgs(...)` call inside `at()`). No other method uses it. (This confirms removing the import is safe once `at()` is deleted.)

- [ ] **Step 2: Update the `in()` command attributes**

In `scripts/remindme/remindme.php`, change the `in()` method's attributes (lines 32-34):

```php
    #[Cmd("in", "remindme")]
    #[Syntax("<timemsg>...")]
    #[Desc("sets a reminder. time can be a duration (1h30m, 1 hour 15 min, 2 days) or a relative date (next tuesday, tomorrow 3pm, next month, aug 15th, second week of jan)")]
```

to:

```php
    #[Cmd("in", "remindme", "at", "on")]
    #[Syntax("<timemsg>...")]
    #[Desc("sets a reminder. time can be a duration (1h30m, 1 hour 15 min, 2 days) or a date/time (tomorrow 3pm, 10am tomorrow, next tuesday, next month, aug 15th, second week of jan). honors your .settz timezone, or an inline abbreviation like 11pm EDT. aliases: in, remindme, at, on")]
```

- [ ] **Step 3: Render the confirmation in the resolved timezone**

In the `in()` method, the date-path confirmation (lines 87-90) currently uses the saved zone:

```php
        if ($parsed->targetTime !== null) {
            $dt = Carbon::createFromTimestamp($parsed->targetTime, $tzName);
            $fromNow = $dt->shortRelativeToNowDiffForHumans(Carbon::now(), 10);
            $bot->pm($args->chan, "Ok, I'll remind you on " . $dt->toCookieString() . " ($fromNow)");
```

Change the `Carbon::createFromTimestamp` line to prefer the effective zone from the parse result (falls back to the saved zone):

```php
        if ($parsed->targetTime !== null) {
            $dt = Carbon::createFromTimestamp($parsed->targetTime, $parsed->timezone ?? $tzName);
            $fromNow = $dt->shortRelativeToNowDiffForHumans(Carbon::now(), 10);
            $bot->pm($args->chan, "Ok, I'll remind you on " . $dt->toCookieString() . " ($fromNow)");
```

- [ ] **Step 4: Delete the `at()` method and its comments**

Delete the entire block from line 137 through line 181 — the `/* ... */` block comment about `makeArgs`, the `// TODO let users save a timezone...` line, the three `#[Cmd]`/`#[Syntax]`/`#[Desc]` attributes, and the whole `at()` method body through its closing `}`. After deletion, keep single blank-line separation between the `settimezone()` method above and the `reminders` method below.

> **Note for reviewer (AGENTS.md "never remove comments"):** Two comments are removed here. The `/* Because cmdr doesnt yet support it, using makeArgs ... */` block documents `at()`'s parsing approach, which is being deleted with the method. The `// TODO let users save a timezone so they dont have always include it here` is now obsolete/misleading — that feature (`settz`) already exists. Both are removed as part of deleting the obsolete method, consistent with the user's explicit request to consolidate the commands.

The block to remove is (lines 137-181):

```php
    /*
     * Because cmdr doesnt yet support it, using \knivey\tools\makeArgs which makes args using "arg one" arg2 "arg\"3" etc
     */
    // TODO let users save a timezone so they dont have always include it here
    #[Cmd("at", "on")]
    #[Syntax("<timemsg>...")]
    #[Desc("Remind you at a certain date time, the date time must be in quotes")]
    public function at(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
    {
        global $entityManager;
        $r = makeArgs($cmdArgs['timemsg']);
        if (!is_array($r) || count($r) < 2) {
            $bot->pm($args->chan, "Syntax: <datetime> <msg>  If datetime is more than one word put it inside quotes, you should include your timezone");
            $bot->pm($args->chan, "Example: .on \"next Friday EDT\" watch new JRH  <- Will trigger at 00:00");
            $bot->pm($args->chan, "Example: .at \"11pm EDT\" eat ice cream");
            return;
        }
        $time = array_shift($r);
        $msg = implode(' ', $r);

        try {
            $dt = new Carbon($time);
        } catch (\Exception $e) {
            $bot->pm($args->chan, "That date time ($time) isn't understood");
            return;
        }
        if ($dt->getTimestamp() <= time() + 15) {
            $bot->pm($args->chan, "Give me a time at least 15 seconds in the future");
            return;
        }
        $in = $dt->getTimestamp() - time();
        $r = new reminder();
        $r->nick = $args->nick;
        $r->chan = $args->chan;
        $r->at = $dt->getTimestamp();
        $r->sent = false;
        $r->msg = $msg;
        $r->network = $this->network;
        $entityManager->persist($r);
        $entityManager->flush();

        $fromNow = $dt->shortRelativeToNowDiffForHumans(Carbon::now(), 10);
        $bot->pm($args->chan, "Ok, I'll remind you on " . $dt->toCookieString() . " ($fromNow)");
        $this->sendDelayed($bot, $r, $in);
    }
```

- [ ] **Step 5: Remove the now-unused `makeArgs` import**

Delete line 13:

```php
use function knivey\tools\makeArgs;
```

- [ ] **Step 6: Verify syntax, no dangling references, and types**

Run each and confirm:

```bash
php -l scripts/remindme/remindme.php
```
Expected: `No syntax errors detected ...`

```bash
rg -n "makeArgs|function at\(|->at\(" scripts/remindme/remindme.php
```
Expected: no matches.

```bash
vendor/bin/phpstan analyse scripts/remindme/ library/Duration.inc --no-progress
```
Expected: no new errors in the touched files (the repo carries a large pre-existing baseline; confirm there are none originating from `remindme.php` or `Duration.inc`).

- [ ] **Step 7: Run the full test suite**

Run: `composer test`
Expected: PASS — full PHPUnit suite green.

- [ ] **Step 8: Commit**

```bash
git add scripts/remindme/remindme.php
git commit -m "refactor(remindme): merge .on/.at into .in/.remindme"
```

---

## Task 4: Final verification

- [ ] **Step 1: Confirm the full suite still passes**

Run: `composer test`
Expected: PASS.

- [ ] **Step 2: Confirm phpstan is clean on touched files**

Run: `vendor/bin/phpstan analyse scripts/remindme/ library/Duration.inc --no-progress`
Expected: no errors originating from these files.

- [ ] **Step 3: Manual spot-check via a parseDuration harness**

Run this and confirm each line matches the expectation in the comment:

```bash
php -r '
require "vendor/autoload.php";
require "library/Duration.inc";
$cases = [
  ["tomorrow 10am dog", "America/Los_Angeles"],   // ok: date, LA tz
  ["10am tomorrow dog", "America/Los_Angeles"],   // Gap 1: == above instant
  ["11pm EDT eat ice cream", "America/Los_Angeles"], // Gap 2: EDT overrides LA, remainder clean
  ["1h30m feed cat", null],                       // duration, tz-independent
  ["next friday 10am thing", "America/Los_Angeles"], // ok: date, LA tz
];
foreach ($cases as [$c, $tz]) {
    $r = parseDuration($c, $tz);
    if ($r === null) { echo sprintf("%-26s tz=%-22s -> NULL\n", $c, $tz ?? "none"); continue; }
    $t = $r->targetTime ? "@".date("D d-M-Y H:i T", $r->targetTime) : "(duration)";
    echo sprintf("%-26s tz=%-22s -> %-34s rem=%s zone=%s\n", $c, $tz ?? "none", $t, json_encode($r->remainder), $r->timezone ?? "null");
}
'
```

Expected: `10am tomorrow dog` produces the **same** timestamp as `tomorrow 10am dog`; `11pm EDT eat ice cream` shows remainder `eat ice cream` (no `EDT`) with `zone=EDT` and an instant different from America/Los_Angeles.

- [ ] **Step 4: Verify no other code referenced the deleted command handler**

Run: `rg -n "->at\(|function at\(|\"at\".*\"on\"|Cmd\(\s*[\"']on" scripts/`
Expected: no references to a remindme `at()` method or separate `on`/`at` registration. (The web/migration `at` column matches are unrelated and expected to be absent from `scripts/`.)

---

## Self-Review (completed during planning)

- **Spec coverage:** Gap 1 → Task 1; Gap 2 → Task 2; command consolidation + resolved-TZ display → Task 3; the deliberate bail-guard removal → Task 1 Step 4; `makeArgs` import removal → Task 3 Step 5; tests for both gaps + adjacency regressions → Tasks 1 & 2. All spec sections covered.
- **Placeholder scan:** none — every step contains concrete code or exact commands.
- **Type consistency:** `ParseResult::timezone` (added Task 2) is consumed in Task 3 as `$parsed->timezone ?? $tzName`. `withTimezone(?string)` accepts the nullable `$tzName` from `parseDurationDate`. Method/property names are consistent across tasks.
