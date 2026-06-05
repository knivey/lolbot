# Natural Language Duration Parser Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the compact-only `string2Seconds()` duration parser with a custom two-phase natural language parser that handles duration expressions (`1 hour 15 min`, `1h15m`, `2 days`) and relative date expressions (`next tuesday`, `tomorrow`, `next week 3pm`), while extracting the remaining text as the reminder message.

**Architecture:** Pure custom parser in `library/Duration.inc` — no Carbon for parsing (Carbon is only kept for display formatting in the command). Three phases tried sequentially:
- **Phase 1 (`parseDurationRegex`):** Regex matches number+unit tokens from the start of input. Handles compact (`1h15m`), full words (`1 hour 15 minutes`), abbreviations (`30 mins`, `2 hrs`), and flexible whitespace.
- **Phase 2 (`parseDurationDate`):** Tries a sequence of regex patterns for relative date expressions. Handles: `tomorrow`, `next <dayname>`, `next <week|month|year>`, `<month> <day>` (named/abbreviated months), `<ordinal> week of <month>` (e.g. "second week of aug"). All optionally followed by a time-of-day (`3pm`, `3:30 am`, `at 3pm`). No ambiguous colon-only times. Day names and ordinals mapped manually; date arithmetic uses `mktime()` and `strtotime()`.

Both phases return a `ParseResult` with `seconds` (relative offset), `targetTime` (absolute timestamp, only for date expressions), and `remainder` (the message text). The old `string2Seconds()` is preserved for backward compatibility (used by other scripts).

**Tech Stack:** PHP 8.1+, regex, native `date()` functions, PHPUnit 10.

---

### Task 1: Write tests for the duration regex parser (Phase 1)

**Files:**
- Create: `tests/Duration/ParseDurationTest.php`

- [ ] **Step 1: Create the test file with Phase 1 duration regex tests**

```php
<?php

namespace Tests\Duration;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../library/Duration.inc';

class ParseDurationTest extends TestCase
{
    // --- Compact formats (backward compat) ---

    public function test_compact_hms(): void
    {
        $result = \parseDuration('1h30m15s go shopping');
        $this->assertNotNull($result);
        $this->assertSame(1 * 3600 + 30 * 60 + 15, $result->seconds);
        $this->assertSame('go shopping', $result->remainder);
        $this->assertNull($result->targetTime);
    }

    public function test_compact_mixed_case(): void
    {
        $result = \parseDuration('2H15M');
        $this->assertNotNull($result);
        $this->assertSame(2 * 3600 + 15 * 60, $result->seconds);
        $this->assertSame('', $result->remainder);
    }

    public function test_compact_days(): void
    {
        $result = \parseDuration('3d12h go to bed');
        $this->assertNotNull($result);
        $this->assertSame(3 * 86400 + 12 * 3600, $result->seconds);
        $this->assertSame('go to bed', $result->remainder);
    }

    public function test_compact_weeks_months_years(): void
    {
        $result = \parseDuration('1w2d3h4m5s');
        $this->assertNotNull($result);
        $this->assertSame(604800 + 2 * 86400 + 3 * 3600 + 4 * 60 + 5, $result->seconds);
        $this->assertSame('', $result->remainder);
    }

    // --- Natural language: full words ---

    public function test_full_words_hours_minutes(): void
    {
        $result = \parseDuration('1 hour 15 minutes go shopping');
        $this->assertNotNull($result);
        $this->assertSame(3600 + 15 * 60, $result->seconds);
        $this->assertSame('go shopping', $result->remainder);
    }

    public function test_full_words_singular(): void
    {
        $result = \parseDuration('1 second');
        $this->assertNotNull($result);
        $this->assertSame(1, $result->seconds);
        $this->assertSame('', $result->remainder);
    }

    public function test_full_words_plural(): void
    {
        $result = \parseDuration('5 minutes');
        $this->assertNotNull($result);
        $this->assertSame(300, $result->seconds);
        $this->assertSame('', $result->remainder);
    }

    public function test_full_words_days(): void
    {
        $result = \parseDuration('1 day do stuff');
        $this->assertNotNull($result);
        $this->assertSame(86400, $result->seconds);
        $this->assertSame('do stuff', $result->remainder);
    }

    public function test_full_words_weeks(): void
    {
        $result = \parseDuration('2 weeks party time');
        $this->assertNotNull($result);
        $this->assertSame(2 * 604800, $result->seconds);
        $this->assertSame('party time', $result->remainder);
    }

    public function test_full_words_years(): void
    {
        $result = \parseDuration('1 year');
        $this->assertNotNull($result);
        $this->assertSame(31533336, $result->seconds);
        $this->assertSame('', $result->remainder);
    }

    public function test_full_words_months(): void
    {
        $result = \parseDuration('3 months check back');
        $this->assertNotNull($result);
        $this->assertSame(3 * 2627778, $result->seconds);
        $this->assertSame('check back', $result->remainder);
    }

    // --- Abbreviated forms ---

    public function test_abbrev_sec(): void
    {
        $result = \parseDuration('30 secs');
        $this->assertNotNull($result);
        $this->assertSame(30, $result->seconds);
        $this->assertSame('', $result->remainder);
    }

    public function test_abbrev_min(): void
    {
        $result = \parseDuration('45 mins call mom');
        $this->assertNotNull($result);
        $this->assertSame(45 * 60, $result->seconds);
        $this->assertSame('call mom', $result->remainder);
    }

    public function test_abbrev_hr(): void
    {
        $result = \parseDuration('2 hrs');
        $this->assertNotNull($result);
        $this->assertSame(2 * 3600, $result->seconds);
        $this->assertSame('', $result->remainder);
    }

    // --- Flexible whitespace ---

    public function test_extra_whitespace(): void
    {
        $result = \parseDuration("1  hour   15  min  go shopping");
        $this->assertNotNull($result);
        $this->assertSame(3600 + 15 * 60, $result->seconds);
        $this->assertSame('go shopping', $result->remainder);
    }

    public function test_whitespace_between_number_and_unit(): void
    {
        $result = \parseDuration('15 s test');
        $this->assertNotNull($result);
        $this->assertSame(15, $result->seconds);
        $this->assertSame('test', $result->remainder);
    }

    // --- No duration matched returns null ---

    public function test_no_duration_returns_null(): void
    {
        $result = \parseDuration('hello world');
        $this->assertNull($result);
    }

    public function test_empty_string_returns_null(): void
    {
        $result = \parseDuration('');
        $this->assertNull($result);
    }

    // --- Mixed compact and word ---

    public function test_mixed_compact_and_word(): void
    {
        $result = \parseDuration('1h 30 minutes do the thing');
        $this->assertNotNull($result);
        $this->assertSame(3600 + 30 * 60, $result->seconds);
        $this->assertSame('do the thing', $result->remainder);
    }

    // --- Case sensitivity: M (month) vs m (minute) ---

    public function test_compact_M_is_month_not_minute(): void
    {
        $result = \parseDuration('1M');
        $this->assertNotNull($result);
        $this->assertSame(2627778, $result->seconds);
        $this->assertSame('', $result->remainder);
    }

    public function test_compact_m_is_minute(): void
    {
        $result = \parseDuration('1m');
        $this->assertNotNull($result);
        $this->assertSame(60, $result->seconds);
        $this->assertSame('', $result->remainder);
    }

    public function test_compact_M_and_m_together(): void
    {
        $result = \parseDuration('1M30m do stuff');
        $this->assertNotNull($result);
        $this->assertSame(2627778 + 30 * 60, $result->seconds);
        $this->assertSame('do stuff', $result->remainder);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Duration/ParseDurationTest.php`
Expected: FATAL ERROR — function `parseDuration` not defined

---

### Task 2: Implement the full parser (Phase 1 + Phase 2)

**Files:**
- Modify: `library/Duration.inc` (add `ParseResult` class, `parseDuration()`, `parseDurationRegex()`, and `parseDurationDate()`)

- [ ] **Step 1: Add the `ParseResult` class and all parser functions**

Append to the end of `library/Duration.inc`, after the existing `Duration_array2string()` function:

```php
class ParseResult
{
    public function __construct(
        public readonly int $seconds,
        public readonly string $remainder,
        public readonly ?int $targetTime = null,
    ) {}
}

function parseDuration(string $input): ?ParseResult
{
    $input = trim($input);
    if ($input === '') {
        return null;
    }

    $seconds = parseDurationRegex($input, $remainder);
    if ($seconds !== null) {
        return new ParseResult($seconds, trim($remainder));
    }

    $result = parseDurationDate($input);
    if ($result !== null) {
        return $result;
    }

    return null;
}

function parseDurationRegex(string $input, string &$remainder = ''): ?int
{
    global $Duration_periods;

    $wordUnits = [
        'years'   => $Duration_periods['y'],
        'year'    => $Duration_periods['y'],
        'yrs'     => $Duration_periods['y'],
        'yr'      => $Duration_periods['y'],
        'months'  => $Duration_periods['M'],
        'month'   => $Duration_periods['M'],
        'weeks'   => $Duration_periods['w'],
        'week'    => $Duration_periods['w'],
        'wks'     => $Duration_periods['w'],
        'wk'      => $Duration_periods['w'],
        'days'    => $Duration_periods['d'],
        'day'     => $Duration_periods['d'],
        'hours'   => $Duration_periods['h'],
        'hour'    => $Duration_periods['h'],
        'hrs'     => $Duration_periods['h'],
        'hr'      => $Duration_periods['h'],
        'minutes' => $Duration_periods['m'],
        'minute'  => $Duration_periods['m'],
        'mins'    => $Duration_periods['m'],
        'min'     => $Duration_periods['m'],
        'seconds' => $Duration_periods['s'],
        'second'  => $Duration_periods['s'],
        'secs'    => $Duration_periods['s'],
        'sec'     => $Duration_periods['s'],
    ];

    $singleLetter = implode('', array_keys($Duration_periods));
    $wordAlternation = implode('|', array_keys($wordUnits));

    $pattern = '/^(\d+\s*[' . preg_quote($singleLetter, '/') . ']\s*|(\d+)\s*(' . $wordAlternation . ')\s*)+/i';

    if (!preg_match($pattern, $input, $matches, PREG_OFFSET_CAPTURE)) {
        return null;
    }

    $matched = $matches[0][0];
    $matchEnd = $matches[0][1] + strlen($matched);
    $remainder = substr($input, $matchEnd);

    $total = 0;
    $tokenPattern = '/(\d+)\s*([' . preg_quote($singleLetter, '/') . '])|(\d+)\s*(' . $wordAlternation . ')/i';
    if (!preg_match_all($tokenPattern, $matched, $tokens, PREG_SET_ORDER)) {
        return null;
    }

    foreach ($tokens as $token) {
        if (!empty($token[2])) {
            $num = (int) $token[1];
            $unit = $token[2];
            if (!isset($Duration_periods[$unit])) {
                $unitLower = strtolower($unit);
                if (!isset($Duration_periods[$unitLower])) {
                    return null;
                }
                $unit = $unitLower;
            }
            $total += $num * $Duration_periods[$unit];
        } elseif (!empty($token[4])) {
            $num = (int) $token[3];
            $unit = strtolower($token[4]);
            if (!isset($wordUnits[$unit])) {
                return null;
            }
            $total += $num * $wordUnits[$unit];
        }
    }

    if ($total <= 0) {
        return null;
    }

    if ($total > PHP_INT_MAX) {
        return null;
    }

    return (int) $total;
}

function parseDurationDate(string $input): ?ParseResult
{
    $dayNames = [
        'monday'    => 1, 'mon'  => 1,
        'tuesday'   => 2, 'tues' => 2, 'tue' => 2,
        'wednesday' => 3, 'wed'  => 3,
        'thursday'  => 4, 'thurs'=> 4, 'thu' => 4,
        'friday'    => 5, 'fri'  => 5,
        'saturday'  => 6, 'sat'  => 6,
        'sunday'    => 7, 'sun'  => 7,
    ];
    $monthNames = [
        'january'  => 1,  'jan' => 1,
        'february' => 2,  'feb' => 2,
        'march'    => 3,  'mar' => 3,
        'april'    => 4,  'apr' => 4,
        'may'      => 5,
        'june'     => 6,  'jun' => 6,
        'july'     => 7,  'jul' => 7,
        'august'   => 8,  'aug' => 8,
        'september'=> 9,  'sep' => 9,  'sept' => 9,
        'october'  => 10, 'oct' => 10,
        'november' => 11, 'nov' => 11,
        'december' => 12, 'dec' => 12,
    ];
    $ordinals = [
        'first'  => 1, '1st' => 1,
        'second' => 2, '2nd' => 2,
        'third'  => 3, '3rd' => 3,
        'fourth' => 4, '4th' => 4,
        'fifth'  => 5, '5th' => 5,
    ];

    $dayPattern = implode('|', array_keys($dayNames));
    $monthPattern = implode('|', array_keys($monthNames));
    $ordinalPattern = implode('|', array_keys($ordinals));
    $timePattern = '(\d{1,2})(?::(\d{2}))?(?::(\d{2}))?\s*(am|pm)';
    $timeGroup = '(?:' . $timePattern . ')?\s*(?:at\s+(' . $timePattern . '))?';

    $patterns = [
        // "today [at] <time>"
        '/^(today)\s*' . $timeGroup . '\s*(.*)$/i',
        // "tomorrow [at] <time>"
        '/^(tomorrow)\s*' . $timeGroup . '\s*(.*)$/i',
        // "next <dayname> [at] <time>"
        '/^next\s+(' . $dayPattern . ')\s*' . $timeGroup . '\s*(.*)$/i',
        // "next <week|month|year> [at] <time>"
        '/^next\s+(week|month|year|yr|mo|wks?)\s*' . $timeGroup . '\s*(.*)$/i',
        // "<ordinal> week of <month> [at] <time>"
        '/^(' . $ordinalPattern . ')\s+week\s+of\s+(' . $monthPattern . ')\s*' . $timeGroup . '\s*(.*)$/i',
        // "<month> <day> [at] <time>" — day can have optional ordinal suffix (1st, 2nd, 3rd, 4th, etc.)
        '/^(' . $monthPattern . ')\s+(\d{1,2})(?:st|nd|rd|th)?\s*' . $timeGroup . '\s*(.*)$/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $input, $m)) {
            $result = parseDurationDateMatch($m, $dayNames, $monthNames, $ordinals);
            if ($result !== null) {
                return $result;
            }
        }
    }

    return null;
}

function parseDurationDateMatch(
    array $m,
    array $dayNames,
    array $monthNames,
    array $ordinals,
): ?ParseResult {
    $baseTimestamp = strtotime('today');

    // Determine which pattern matched by checking the first capture group
    if (strtolower($m[1]) === 'today') {
        // "today" — base is midnight today, only valid with a future time
        $timeOffset = 2;
    } elseif (strtolower($m[1]) === 'tomorrow') {
        $baseTimestamp += 86400;
        $timeOffset = 2;
    } elseif (isset($dayNames[strtolower($m[1])])) {
        $target = $dayNames[strtolower($m[1])];
        $today = (int) date('N');
        $diff = $target - $today;
        if ($diff <= 0) {
            $diff += 7;
        }
        $baseTimestamp += $diff * 86400;
        $timeOffset = 2;
    } elseif (in_array(strtolower($m[1]), ['week', 'month', 'year', 'yr', 'mo', 'wk', 'wks'])) {
        $unit = strtolower($m[1]);
        switch ($unit) {
            case 'week':
            case 'wk':
            case 'wks':
                $today = (int) date('N');
                $daysUntilMon = (8 - $today) % 7;
                if ($daysUntilMon === 0) {
                    $daysUntilMon = 7;
                }
                $baseTimestamp += $daysUntilMon * 86400;
                break;
            case 'month':
            case 'mo':
                $baseTimestamp = strtotime('+1 month', $baseTimestamp);
                break;
            case 'year':
            case 'yr':
            case 'yrs':
                $baseTimestamp = strtotime('+1 year', $baseTimestamp);
                break;
            default:
                return null;
        }
        $timeOffset = 2;
    } elseif (isset($ordinals[strtolower($m[1])])) {
        // "<ordinal> week of <month>"
        $n = $ordinals[strtolower($m[1])];
        $month = $monthNames[strtolower($m[2])];
        $year = (int) date('Y');
        $ts = parseDurationDateNthMonday($n, $month, $year);
        if ($ts <= time() + 15) {
            $ts = parseDurationDateNthMonday($n, $month, $year + 1);
        }
        $baseTimestamp = $ts;
        $timeOffset = 3;
    } elseif (isset($monthNames[strtolower($m[1])])) {
        // "<month> <day>"
        $month = $monthNames[strtolower($m[1])];
        $day = (int) $m[2];
        $year = (int) date('Y');
        $ts = mktime(0, 0, 0, $month, $day, $year);
        if ($ts === false || $ts <= time() + 15) {
            $ts = mktime(0, 0, 0, $month, $day, $year + 1);
        }
        if ($ts === false) {
            return null;
        }
        $baseTimestamp = $ts;
        $timeOffset = 3;
    } else {
        return null;
    }

    // Parse optional time groups
    $hours = 0;
    $minutes = 0;
    $seconds = 0;
    // Time group 1: m[timeOffset..timeOffset+3]
    // Time group 2 (after "at"): m[timeOffset+4..timeOffset+7]
    for ($i = $timeOffset; $i <= $timeOffset + 4; $i += 4) {
        if (!empty($m[$i])) {
            $hours = (int) $m[$i];
            $minutes = isset($m[$i + 1]) && $m[$i + 1] !== '' ? (int) $m[$i + 1] : 0;
            $seconds = isset($m[$i + 2]) && $m[$i + 2] !== '' ? (int) $m[$i + 2] : 0;
            $ampm = strtolower($m[$i + 3]);
            if ($ampm === 'pm' && $hours < 12) {
                $hours += 12;
            }
            if ($ampm === 'am' && $hours === 12) {
                $hours = 0;
            }
            break;
        }
    }

    $targetTime = $baseTimestamp + $hours * 3600 + $minutes * 60 + $seconds;
    $remainder = isset($m[$timeOffset + 8]) ? trim($m[$timeOffset + 8]) : '';

    if ($targetTime <= time() + 15) {
        return null;
    }

    return new ParseResult(
        seconds: $targetTime - time(),
        remainder: $remainder,
        targetTime: $targetTime,
    );
}

function parseDurationDateNthMonday(int $n, int $month, int $year): int
{
    $firstDay = mktime(0, 0, 0, $month, 1, $year);
    $dow = (int) date('N', $firstDay);
    $daysToFirstMon = (8 - $dow) % 7;
    $firstMonday = 1 + $daysToFirstMon;
    $targetDay = $firstMonday + ($n - 1) * 7;
    return mktime(0, 0, 0, $month, $targetDay, $year);
}
```

- [ ] **Step 2: Run Phase 1 tests to verify they pass**

Run: `vendor/bin/phpunit tests/Duration/ParseDurationTest.php`
Expected: All Phase 1 tests PASS

---

### Task 3: Add tests for the date parser (Phase 2)

**Files:**
- Modify: `tests/Duration/ParseDurationTest.php` (append new test methods)

- [ ] **Step 1: Add date parser tests to the test class**

Append these test methods inside the `ParseDurationTest` class, after the existing tests:

```php
    // --- Date expressions: today ---

    public function test_today_with_time(): void
    {
        $result = \parseDuration('today 11:59pm late snack');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertGreaterThan(time() + 15, $result->targetTime);
        $this->assertSame('late snack', $result->remainder);
    }

    public function test_today_at_time(): void
    {
        $result = \parseDuration('today at 3pm meeting');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertSame('meeting', $result->remainder);
    }

    public function test_today_without_time_returns_null(): void
    {
        // "today" without a time gives midnight which is in the past or < 15s away
        $result = \parseDuration('today do something');
        // Only passes if midnight today is still > 15s in the future (unlikely but possible)
        // In practice almost always returns null
        if (strtotime('today') > time() + 15) {
            $this->assertNotNull($result);
        } else {
            $this->assertNull($result);
        }
    }

    // --- Date expressions: tomorrow ---

    public function test_tomorrow(): void
    {
        $result = \parseDuration('tomorrow feed the cat');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertGreaterThan(time() + 15, $result->targetTime);
        $this->assertSame('feed the cat', $result->remainder);
    }

    public function test_tomorrow_with_time(): void
    {
        $result = \parseDuration('tomorrow 3pm eat ice cream');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertGreaterThan(time() + 15, $result->targetTime);
        $this->assertSame('eat ice cream', $result->remainder);
    }

    public function test_tomorrow_at_time(): void
    {
        $result = \parseDuration('tomorrow at 3pm eat ice cream');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertSame('eat ice cream', $result->remainder);
    }

    // --- Date expressions: next <dayname> ---

    public function test_next_tuesday(): void
    {
        $result = \parseDuration('next tuesday go buy groceries');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertGreaterThan(time() + 15, $result->targetTime);
        $this->assertSame('go buy groceries', $result->remainder);
    }

    public function test_next_tues(): void
    {
        $result = \parseDuration('next tues go buy groceries');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertGreaterThan(time() + 15, $result->targetTime);
        $this->assertSame('go buy groceries', $result->remainder);
    }

    public function test_next_mon(): void
    {
        $result = \parseDuration('next mon do laundry');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertSame('do laundry', $result->remainder);
    }

    public function test_next_friday_with_time(): void
    {
        $result = \parseDuration('next friday 3pm do the thing');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertGreaterThan(time() + 15, $result->targetTime);
        $this->assertSame('do the thing', $result->remainder);
    }

    public function test_next_tues_with_time_and_at(): void
    {
        $result = \parseDuration('next tues at 3:30pm meeting');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertSame('meeting', $result->remainder);
    }

    // --- Date expressions: next week ---

    public function test_next_week(): void
    {
        $result = \parseDuration('next week do something');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertGreaterThan(time() + 15, $result->targetTime);
        $this->assertSame('do something', $result->remainder);
    }

    public function test_next_week_with_time(): void
    {
        $result = \parseDuration('next week 9am meeting');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertSame('meeting', $result->remainder);
    }

    // --- Date expressions: next month ---

    public function test_next_month(): void
    {
        $result = \parseDuration('next month check subscriptions');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertGreaterThan(time() + 15, $result->targetTime);
        $this->assertSame('check subscriptions', $result->remainder);
    }

    public function test_next_month_with_time(): void
    {
        $result = \parseDuration('next month 3pm pay rent');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertSame('pay rent', $result->remainder);
    }

    public function test_next_month_at_time(): void
    {
        $result = \parseDuration('next month at 9am review budget');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertSame('review budget', $result->remainder);
    }

    // --- Date expressions: next year ---

    public function test_next_year(): void
    {
        $result = \parseDuration('next year happy new year');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertGreaterThan(time() + 15, $result->targetTime);
        $this->assertSame('happy new year', $result->remainder);
    }

    public function test_next_year_with_time(): void
    {
        $result = \parseDuration('next year 12am celebrate');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertSame('celebrate', $result->remainder);
    }

    // --- Date expressions: named months ---

    public function test_named_month_day(): void
    {
        $result = \parseDuration('aug 15 pay rent');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertGreaterThan(time() + 15, $result->targetTime);
        $this->assertSame('pay rent', $result->remainder);
    }

    public function test_named_month_day_full(): void
    {
        $result = \parseDuration('august 15 pay rent');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertSame('pay rent', $result->remainder);
    }

    public function test_named_month_day_with_time(): void
    {
        $result = \parseDuration('january 3 9am new year tasks');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertSame('new year tasks', $result->remainder);
    }

    public function test_named_month_day_at_time(): void
    {
        $result = \parseDuration('dec 25 at 8am open presents');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertSame('open presents', $result->remainder);
    }

    public function test_named_month_day_no_remainder(): void
    {
        $result = \parseDuration('aug 15');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertSame('', $result->remainder);
    }

    // --- Ordinal day of month ---

    public function test_month_ordinal_day_1st(): void
    {
        $result = \parseDuration('january 1st new year');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertGreaterThan(time() + 15, $result->targetTime);
        $this->assertSame('new year', $result->remainder);
    }

    public function test_month_ordinal_day_2nd(): void
    {
        $result = \parseDuration('august 2nd');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertSame('', $result->remainder);
    }

    public function test_month_ordinal_day_3rd(): void
    {
        $result = \parseDuration('march 3rd spring cleaning');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertSame('spring cleaning', $result->remainder);
    }

    public function test_month_ordinal_day_15th_with_time(): void
    {
        $result = \parseDuration('dec 25th 8am open presents');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertSame('open presents', $result->remainder);
    }

    public function test_month_ordinal_day_31st(): void
    {
        $result = \parseDuration('october 31st halloween');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertSame('halloween', $result->remainder);
    }

    public function test_abbrev_month_ordinal_day(): void
    {
        $result = \parseDuration('sept 21st at 3pm birthday');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertSame('birthday', $result->remainder);
    }

    // --- Ordinal day without month should not match date parser ---

    public function test_bare_ordinal_returns_null(): void
    {
        $result = \parseDuration('2nd do something');
        $this->assertNull($result);
    }

    // --- Date expressions: ordinal week of month ---

    public function test_second_week_of_aug(): void
    {
        $result = \parseDuration('second week of aug vacation');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertGreaterThan(time() + 15, $result->targetTime);
        $this->assertSame('vacation', $result->remainder);
    }

    public function test_ordinal_week_full_month(): void
    {
        $result = \parseDuration('first week of january new year');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertSame('new year', $result->remainder);
    }

    public function test_ordinal_week_with_time(): void
    {
        $result = \parseDuration('3rd week of sept 3pm conference');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertSame('conference', $result->remainder);
    }

    public function test_ordinal_week_at_time(): void
    {
        $result = \parseDuration('2nd week of december at 9am review');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertSame('review', $result->remainder);
    }

    public function test_ordinal_week_no_remainder(): void
    {
        $result = \parseDuration('first week of march');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertSame('', $result->remainder);
    }

    // --- Duration regex takes priority ---

    public function test_duration_regex_takes_priority(): void
    {
        $result = \parseDuration('1 hour 15 minutes buy milk');
        $this->assertNotNull($result);
        $this->assertNull($result->targetTime);
        $this->assertSame(3600 + 15 * 60, $result->seconds);
        $this->assertSame('buy milk', $result->remainder);
    }

    // --- Time format edge cases ---

    public function test_time_12am(): void
    {
        $result = \parseDuration('tomorrow 12am');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertSame('', $result->remainder);
    }

    public function test_time_with_minutes_and_space(): void
    {
        $result = \parseDuration('tomorrow 3:30 am wake up');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertSame('wake up', $result->remainder);
    }

    // --- No match returns null ---

    public function test_gibberish_returns_null(): void
    {
        $result = \parseDuration('hello world foo bar');
        $this->assertNull($result);
    }
```

- [ ] **Step 2: Run all tests**

Run: `vendor/bin/phpunit tests/Duration/ParseDurationTest.php`
Expected: All tests PASS

---

### Task 4: Update the `in`/`remindme` command to use `parseDuration`

**Files:**
- Modify: `scripts/remindme/remindme.php` (update the `in()` method)

- [ ] **Step 1: Update the `in()` method**

Replace the body of the `in()` method (the method starting at line 25) with the new implementation. Key changes:
- Change `#[Syntax]` from `<time> <msg>...` to `<timemsg>...` so we get the full raw input
- Use `parseDuration()` instead of `string2Seconds()`
- Support both duration mode (stores `time() + seconds`) and target-time mode (stores `targetTime` directly)

New method:

```php
    #[Cmd("in", "remindme")]
    #[Syntax("<timemsg>...")]
    #[Desc("sets a reminder. time can be a duration (1h30m, 1 hour 15 min, 2 days) or a relative date (next tuesday, tomorrow 3pm, next month, aug 15th, second week of jan)")]
    public function in(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
    {
        global $entityManager;
        $host = $args->host;
        if (isset($this->cmdLimit[$host]) && $this->cmdLimit[$host] > time()) {
            if (!isset($this->limitWarns[$host]) || $this->limitWarns[$host] < time() - 2) {
                $bot->pm($args->chan, "You're going too fast, wait awhile");
                $this->limitWarns[$host] = time();
            }
            return;
        }
        $this->cmdLimit[$host] = time() + 2;
        unset($this->limitWarns[$host]);

        $parsed = \parseDuration($cmdArgs['timemsg']);
        if ($parsed === null) {
            $bot->pm($args->chan, "Couldn't understand that time. Try: 1h30m, 1 hour 15 min, 2 days, next tuesday, tomorrow 3pm, next month, aug 15");
            return;
        }

        if ($parsed->remainder === '') {
            $bot->pm($args->chan, "You need to tell me what to remind you about!");
            return;
        }

        $in = $parsed->seconds;
        if ($in < 15) {
            $bot->pm($args->chan, "Give me a time at least 15 seconds in the future");
            return;
        }
        if ($in > \string2Seconds("69y")) {
            $bot->pm($args->chan, "Yeah sure I'll totally remind you in " . \Duration_toString($in) . " ;-)");
            return;
        }

        $r = new reminder();
        $r->nick = $args->nick;
        $r->chan = $args->chan;
        $r->at = $parsed->targetTime ?? time() + $in;
        $r->msg = $parsed->remainder;
        $r->network = $this->network;
        $entityManager->persist($r);
        $entityManager->flush();

        if ($parsed->targetTime !== null) {
            $dt = Carbon::createFromTimestamp($parsed->targetTime);
            $fromNow = $dt->shortRelativeToNowDiffForHumans(Carbon::now(), 10);
            $bot->pm($args->chan, "Ok, I'll remind you on " . $dt->toCookieString() . " ($fromNow)");
        } else {
            $bot->pm($args->chan, "Ok, I'll remind you in " . \Duration_toString($in));
        }
        $this->sendDelayed($bot, $r, $in);
    }
```

- [ ] **Step 2: Run phpstan to check for type errors**

Run: `composer phpstan`
Expected: No new errors (or pre-existing errors only)

- [ ] **Step 3: Run all tests**

Run: `composer test`
Expected: All tests PASS

---

### Task 5: Update the `at`/`on` command to also use `parseDuration`

**Files:**
- Modify: `scripts/remindme/remindme.php` (update the `at()` method)

- [ ] **Step 1: Update the `at()` method to use `parseDuration`**

The `at`/`on` command currently requires users to quote the datetime. With `parseDuration` now handling date expressions natively, we can make `at`/`on` accept unquoted natural language too. Replace the body of the `at()` method:

```php
    #[Cmd("at", "on")]
    #[Syntax("<timemsg>...")]
    #[Desc("Remind you at a certain date time. Try: .on next friday watch new JRH  or  .at tomorrow 3pm eat ice cream  or  .in aug 15 pay rent")]
    public function at(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
    {
        global $entityManager;

        $parsed = \parseDuration($cmdArgs['timemsg']);
        if ($parsed === null || $parsed->remainder === '') {
            $bot->pm($args->chan, "Syntax: <datetime> <msg>  Try: .on next friday watch new JRH  or  .at tomorrow 3pm eat ice cream  or  .in aug 15 pay rent");
            return;
        }

        if ($parsed->targetTime !== null) {
            $dt = Carbon::createFromTimestamp($parsed->targetTime);
        } else {
            $dt = Carbon::createFromTimestamp(time() + $parsed->seconds);
        }

        $in = $parsed->seconds;
        if ($in < 15) {
            $bot->pm($args->chan, "Give me a time at least 15 seconds in the future");
            return;
        }

        $r = new reminder();
        $r->nick = $args->nick;
        $r->chan = $args->chan;
        $r->at = $dt->getTimestamp();
        $r->sent = false;
        $r->msg = $parsed->remainder;
        $r->network = $this->network;
        $entityManager->persist($r);
        $entityManager->flush();

        $fromNow = $dt->shortRelativeToNowDiffForHumans(Carbon::now(), 10);
        $bot->pm($args->chan, "Ok, I'll remind you on " . $dt->toCookieString() . " ($fromNow)");
        $this->sendDelayed($bot, $r, $in);
    }
```

- [ ] **Step 2: Remove the now-unused `makeArgs` import if no other method uses it**

Check if `makeArgs` is used elsewhere in `remindme.php`. The `in()` method no longer uses it and the `at()` method no longer uses it. If no other method in the file uses it, remove this line from the imports at the top of the file:

```php
use function knivey\tools\makeArgs;
```

- [ ] **Step 3: Run phpstan**

Run: `composer phpstan`
Expected: No new errors

- [ ] **Step 4: Run all tests**

Run: `composer test`
Expected: All tests PASS

- [ ] **Step 5: Commit**

```bash
git add library/Duration.inc scripts/remindme/remindme.php tests/Duration/ParseDurationTest.php
git commit -m "feat: natural language duration parser for remindme

Replace compact-only duration parsing (1h30m) with a two-phase parser:
- Phase 1: regex matches number+unit with flexible whitespace and
  abbreviated/full-word units (1 hour 15 min, 2 days, 30 secs, 1h15m)
- Phase 2: custom date parser for relative dates (next tuesday,
  tomorrow 3pm, next week/month/year), named months (aug 15, january 3),
  and ordinal week of month (second week of aug) with am/pm time support

Both in/remindme and at/on commands now accept natural language input.
Extracts the time portion and leaves the remainder as the reminder message."
```
