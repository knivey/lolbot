<?php

namespace Tests\Duration;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../library/Duration.inc';

$GLOBALS['Duration_periods'] = $Duration_periods;

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
        $result = \parseDuration('2H15m');
        $this->assertNotNull($result);
        $this->assertSame(2 * 3600 + 15 * 60, $result->seconds);
        $this->assertSame('', $result->remainder);
    }

    public function test_compact_H_and_M_case_sensitive(): void
    {
        $result = \parseDuration('2H15M');
        $this->assertNotNull($result);
        $this->assertSame(2 * 3600 + 15 * 2627778, $result->seconds);
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
        $hour = (int) date('G');
        $testHour = ($hour + 2) % 24;
        $ampm = $testHour >= 12 ? 'pm' : 'am';
        $displayHour = $testHour > 12 ? $testHour - 12 : ($testHour === 0 ? 12 : $testHour);
        $prefix = ($hour + 2) >= 24 ? 'tomorrow' : 'today';
        $result = \parseDuration("{$prefix} at {$displayHour}{$ampm} meeting");
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertSame('meeting', $result->remainder);
    }

    public function test_today_without_time_returns_null(): void
    {
        $result = \parseDuration('today do something');
        if (strtotime('today') > time() + 15) {
            $this->assertNotNull($result);
        } else {
            $this->assertNull($result);
        }
    }

    // --- Bare time defaults to today ---

    public function test_bare_time_defaults_to_today(): void
    {
        $hour = (int) date('G');
        if ($hour + 2 >= 24) {
            $this->markTestSkipped('test requires a future time today');
        }
        $testHour = $hour + 2;
        $ampm = $testHour >= 12 ? 'pm' : 'am';
        $displayHour = $testHour > 12 ? $testHour - 12 : ($testHour === 0 ? 12 : $testHour);

        $explicit = \parseDuration("today {$displayHour}{$ampm} recycling");
        $bare = \parseDuration("{$displayHour}{$ampm} recycling");
        $this->assertNotNull($explicit, 'sanity: explicit today form should parse');
        $this->assertNotNull($bare, 'bare time should default to today');
        $this->assertSame($explicit->targetTime, $bare->targetTime);
        $this->assertSame('recycling', $bare->remainder);
    }

    public function test_bare_time_with_minutes_defaults_to_today(): void
    {
        $hour = (int) date('G');
        if ($hour + 2 >= 24) {
            $this->markTestSkipped('test requires a future time today');
        }
        $testHour = $hour + 2;
        $ampm = $testHour >= 12 ? 'pm' : 'am';
        $displayHour = $testHour > 12 ? $testHour - 12 : ($testHour === 0 ? 12 : $testHour);

        $explicit = \parseDuration("today {$displayHour}:30{$ampm} meeting");
        $bare = \parseDuration("{$displayHour}:30{$ampm} meeting");
        $this->assertNotNull($explicit);
        $this->assertNotNull($bare);
        $this->assertSame($explicit->targetTime, $bare->targetTime);
        $this->assertSame('meeting', $bare->remainder);
    }

    public function test_bare_time_with_timezone(): void
    {
        $tzName = 'America/New_York';
        $tz = new \DateTimeZone($tzName);
        $nowInTz = new \DateTime('now', $tz);
        $hour = (int) $nowInTz->format('G');
        if ($hour + 2 >= 24) {
            $this->markTestSkipped('test requires a future time today in target timezone');
        }
        $testHour = $hour + 2;
        $ampm = $testHour >= 12 ? 'pm' : 'am';
        $displayHour = $testHour > 12 ? $testHour - 12 : ($testHour === 0 ? 12 : $testHour);

        $explicit = \parseDuration("today {$displayHour}{$ampm} recycling", $tzName);
        $bare = \parseDuration("{$displayHour}{$ampm} recycling", $tzName);
        $this->assertNotNull($explicit);
        $this->assertNotNull($bare);
        $this->assertSame($explicit->targetTime, $bare->targetTime);
    }

    public function test_bare_time_no_remainder(): void
    {
        $hour = (int) date('G');
        if ($hour + 2 >= 24) {
            $this->markTestSkipped('test requires a future time today');
        }
        $testHour = $hour + 2;
        $ampm = $testHour >= 12 ? 'pm' : 'am';
        $displayHour = $testHour > 12 ? $testHour - 12 : ($testHour === 0 ? 12 : $testHour);

        $result = \parseDuration("{$displayHour}{$ampm}");
        $this->assertNotNull($result);
        $this->assertSame('', $result->remainder);
    }

    public function test_bare_time_already_passed_today_rolls_to_tomorrow(): void
    {
        $hour = (int) date('G');
        $pastHour = $hour - 1;
        if ($pastHour < 0) {
            $this->markTestSkipped('cannot test past time at midnight hour');
        }
        $ampm = $pastHour >= 12 ? 'pm' : 'am';
        $displayHour = $pastHour > 12 ? $pastHour - 12 : ($pastHour === 0 ? 12 : $pastHour);

        // A bare time that already passed today should roll to tomorrow,
        // matching the explicit "tomorrow <time>" form.
        $explicit = \parseDuration("tomorrow {$displayHour}{$ampm} thing");
        $bare = \parseDuration("{$displayHour}{$ampm} thing");
        $this->assertNotNull($explicit, 'sanity: tomorrow form should parse');
        $this->assertNotNull($bare, 'bare past time should roll to tomorrow');
        $this->assertSame($explicit->targetTime, $bare->targetTime);
        $this->assertSame('thing', $bare->remainder);
    }

    public function test_bare_time_with_at_prefix_defaults_to_today(): void
    {
        $hour = (int) date('G');
        if ($hour + 2 >= 24) {
            $this->markTestSkipped('test requires a future time today');
        }
        $testHour = $hour + 2;
        $ampm = $testHour >= 12 ? 'pm' : 'am';
        $displayHour = $testHour > 12 ? $testHour - 12 : ($testHour === 0 ? 12 : $testHour);

        $explicit = \parseDuration("today {$displayHour}{$ampm} recycling");
        $bare = \parseDuration("at {$displayHour}{$ampm} recycling");
        $this->assertNotNull($explicit);
        $this->assertNotNull($bare);
        $this->assertSame($explicit->targetTime, $bare->targetTime);
        $this->assertSame('recycling', $bare->remainder);
    }

    public function test_bare_time_uppercase_am_pm(): void
    {
        $hour = (int) date('G');
        if ($hour + 2 >= 24) {
            $this->markTestSkipped('test requires a future time today');
        }
        $testHour = $hour + 2;
        $ampm = $testHour >= 12 ? 'PM' : 'AM';
        $displayHour = $testHour > 12 ? $testHour - 12 : ($testHour === 0 ? 12 : $testHour);

        $explicit = \parseDuration("today " . strtolower($displayHour . $ampm) . " recycling");
        $bare = \parseDuration("{$displayHour}{$ampm} recycling");
        $this->assertNotNull($explicit);
        $this->assertNotNull($bare);
        $this->assertSame($explicit->targetTime, $bare->targetTime);
        $this->assertSame('recycling', $bare->remainder);
    }

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

    public function test_time_before_today_past_returns_null(): void
    {
        $hour = (int) date('G');
        if ($hour - 2 < 0) {
            $this->markTestSkipped('test requires a past time today');
        }
        $testHour = ($hour - 2 + 24) % 24;
        $ampm = $testHour >= 12 ? 'pm' : 'am';
        $displayHour = $testHour > 12 ? $testHour - 12 : ($testHour === 0 ? 12 : $testHour);

        // A past "Hh today" must NOT silently roll to tomorrow with a polluted message.
        $canonical = \parseDuration("today {$displayHour}{$ampm} recycling");
        $reordered = \parseDuration("{$displayHour}{$ampm} today recycling");
        $this->assertNull($canonical);
        $this->assertNull($reordered);
    }

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

    public function test_time_before_tomorrow_honors_timezone(): void
    {
        $tz = 'America/Los_Angeles';
        $canonical = \parseDuration('tomorrow 3pm feed the dog', $tz);
        $reordered = \parseDuration('3pm tomorrow feed the dog', $tz);
        $this->assertNotNull($canonical);
        $this->assertNotNull($reordered);
        $this->assertNotNull($canonical->targetTime);
        $this->assertNotNull($reordered->targetTime);
        $this->assertSame($canonical->targetTime, $reordered->targetTime);
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

    public function test_bare_dayname_with_time(): void
    {
        $result = \parseDuration('sunday 11am ssl really');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertGreaterThan(time() + 15, $result->targetTime);
        $this->assertSame('ssl really', $result->remainder);
    }

    public function test_bare_dayname_no_time(): void
    {
        $result = \parseDuration('sunday ssl really');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertGreaterThan(time() + 15, $result->targetTime);
        $this->assertSame('ssl really', $result->remainder);
    }

    public function test_this_dayname(): void
    {
        $result = \parseDuration('this sunday ssl really');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertGreaterThan(time() + 15, $result->targetTime);
        $this->assertSame('ssl really', $result->remainder);
    }

    public function test_time_before_dayname(): void
    {
        $result = \parseDuration('11am sunday ssl really');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertGreaterThan(time() + 15, $result->targetTime);
        $this->assertSame('ssl really', $result->remainder);
    }

    // --- Decimal durations ---

    public function test_decimal_day(): void
    {
        $result = \parseDuration('2.5d ssl really');
        $this->assertNotNull($result);
        $this->assertSame((int) (2.5 * 86400), $result->seconds);
        $this->assertSame('ssl really', $result->remainder);
    }

    public function test_decimal_hours(): void
    {
        $result = \parseDuration('1.5 hours do stuff');
        $this->assertNotNull($result);
        $this->assertSame((int) (1.5 * 3600), $result->seconds);
        $this->assertSame('do stuff', $result->remainder);
    }

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

    // --- Timezone-aware parsing ---

    public function test_tomorrow_with_timezone(): void
    {
        $result = \parseDuration('tomorrow 3pm eat ice cream', 'America/New_York');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertGreaterThan(time() + 15, $result->targetTime);
        $this->assertSame('eat ice cream', $result->remainder);
    }

    public function test_tomorrow_timezone_differs_from_utc(): void
    {
        $resultUtc = \parseDuration('tomorrow 3pm test', 'UTC');
        $resultNy = \parseDuration('tomorrow 3pm test', 'America/New_York');
        $this->assertNotNull($resultUtc);
        $this->assertNotNull($resultNy);
        $this->assertNotNull($resultUtc->targetTime);
        $this->assertNotNull($resultNy->targetTime);
        $this->assertNotEquals($resultUtc->targetTime, $resultNy->targetTime);
    }

    public function test_next_tuesday_with_timezone(): void
    {
        $result = \parseDuration('next tuesday 11am meeting', 'Europe/London');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertGreaterThan(time() + 15, $result->targetTime);
        $this->assertSame('meeting', $result->remainder);
    }

    public function test_bare_dayname_with_timezone(): void
    {
        $result = \parseDuration('sunday 11am ssl really', 'America/Chicago');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertGreaterThan(time() + 15, $result->targetTime);
        $this->assertSame('ssl really', $result->remainder);
    }

    public function test_named_month_with_timezone(): void
    {
        $result = \parseDuration('aug 15 3pm pay rent', 'Asia/Tokyo');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertGreaterThan(time() + 15, $result->targetTime);
        $this->assertSame('pay rent', $result->remainder);
    }

    public function test_duration_not_affected_by_timezone(): void
    {
        $resultUtc = \parseDuration('1h30m do stuff', 'UTC');
        $resultNy = \parseDuration('1h30m do stuff', 'America/New_York');
        $this->assertNotNull($resultUtc);
        $this->assertNotNull($resultNy);
        $this->assertSame($resultUtc->seconds, $resultNy->seconds);
        $this->assertNull($resultUtc->targetTime);
        $this->assertNull($resultNy->targetTime);
    }

    public function test_invalid_timezone_throws(): void
    {
        $this->expectException(\Exception::class);
        \parseDuration('tomorrow 3pm test', 'Invalid/Timezone');
    }

    public function test_invalid_day_of_month_with_timezone_returns_null(): void
    {
        $result = \parseDuration('feb 41 test', 'America/New_York');
        $this->assertNull($result);
    }

    public function test_month_day_future_year(): void
    {
        $result = \parseDuration('aug 15 2027 do stuff');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertGreaterThan(time() + 15, $result->targetTime);
        $this->assertSame('do stuff', $result->remainder);
    }

    public function test_month_day_future_year_with_time(): void
    {
        $result = \parseDuration('aug 15 2027 3pm do stuff');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertGreaterThan(time() + 15, $result->targetTime);
        $this->assertSame('do stuff', $result->remainder);
    }

    public function test_month_day_past_year_returns_null(): void
    {
        $result = \parseDuration('aug 15 2024 test');
        $this->assertNull($result);
    }

    public function test_month_day_current_year_returns_null(): void
    {
        $result = \parseDuration('aug 15 ' . date('Y') . ' test');
        $this->assertNull($result);
    }

    public function test_bare_future_year(): void
    {
        $result = \parseDuration('2027 new year new me');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertGreaterThan(time() + 15, $result->targetTime);
        $this->assertSame('new year new me', $result->remainder);
    }

    public function test_bare_future_year_with_time(): void
    {
        $result = \parseDuration('2027 3pm party');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertGreaterThan(time() + 15, $result->targetTime);
        $this->assertSame('party', $result->remainder);
    }

    public function test_bare_current_year_returns_null(): void
    {
        $result = \parseDuration(date('Y') . ' test');
        $this->assertNull($result);
    }

    public function test_bare_past_year_returns_null(): void
    {
        $result = \parseDuration('2024 test');
        $this->assertNull($result);
    }

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
}
