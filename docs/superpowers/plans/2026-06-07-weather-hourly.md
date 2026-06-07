# Weather Hourly Forecast Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `--hourly`/`--hr` flag to the weather command that shows 12 hours of inline forecast, with optional `--detailed`/`--d` flag for wind/humidity/precip data.

**Architecture:** Single-file change to `scripts/weather/weather.php`. Add a pure `formatHourlyEntry()` helper method for testability, then wire it into the existing `weather()` command handler with flag detection, exclusivity checks, and conditional API exclude logic.

**Tech Stack:** PHP 8.1+, PHPUnit 10, OpenWeatherMap One Call API 3.0

---

### Task 1: Add `formatHourlyEntry` helper method

**Files:**
- Modify: `scripts/weather/weather.php` (add method after `windDir` at line 88)

This method formats a single hourly data entry from the OpenWeatherMap API response. It is a pure function for testability.

- [ ] **Step 1: Add the method to `weather.php`**

Insert after line 88 (after `windDir` method closing brace):

```php
    /**
     * @phpstan-pure
     * @param array $hour Single entry from OpenWeatherMap hourly array
     * @param \DateTimeZone $tz Timezone for time formatting
     * @param bool $si Metric units
     * @param bool $detailed Show wind/humidity/precip
     * @return string Formatted entry string
     */
    static function formatHourlyEntry(array $hour, \DateTimeZone $tz, bool $si, bool $detailed): string
    {
        $time = new \DateTime('@' . $hour['dt']);
        $time->setTimezone($tz);
        $timeStr = $time->format('ga');
        $condition = ucfirst($hour['weather'][0]['description']);
        $temp = self::displayTemp($hour['temp'], $si);
        $entry = "$timeStr: $condition $temp";
        if ($detailed) {
            $wind = self::windDir($hour['wind_deg']) . self::displayWindspeed($hour['wind_speed'], $si);
            $humidity = $hour['humidity'];
            $pop = round($hour['pop'] * 100);
            $entry .= " $wind {$humidity}%h {$pop}%p";
        }
        return $entry;
    }
```

- [ ] **Step 2: Verify syntax**

Run: `php -l scripts/weather/weather.php`
Expected: `No syntax errors`

- [ ] **Step 3: Commit**

```bash
git add scripts/weather/weather.php
git commit -m "weather: add formatHourlyEntry helper method"
```

---

### Task 2: Write tests for `formatHourlyEntry`

**Files:**
- Create: `tests/Weather/FormatHourlyEntryTest.php`

- [ ] **Step 1: Write the test file**

```php
<?php

namespace Tests\Weather;

use PHPUnit\Framework\TestCase;
use scripts\weather\weather;

require_once __DIR__ . '/../../scripts/weather/weather.php';

class FormatHourlyEntryTest extends TestCase
{
    private static function makeHour(array $overrides = []): array
    {
        return array_merge([
            'dt' => 1700000000,
            'temp' => 295.0,
            'weather' => [['description' => 'clear sky']],
            'wind_speed' => 5.0,
            'wind_deg' => 225,
            'humidity' => 45,
            'pop' => 0.1,
        ], $overrides);
    }

    public function test_basic_entry_imperial(): void
    {
        $hour = self::makeHour();
        $tz = new \DateTimeZone('UTC');
        $result = weather::formatHourlyEntry($hour, $tz, false, false);
        $time = (new \DateTime('@1700000000'))->setTimezone($tz)->format('ga');
        $this->assertStringStartsWith("$time: Clear sky", $result);
        $this->assertStringContainsString('°F', $result);
        $this->assertStringNotContainsString('%h', $result);
        $this->assertStringNotContainsString('%p', $result);
    }

    public function test_basic_entry_metric(): void
    {
        $hour = self::makeHour();
        $tz = new \DateTimeZone('UTC');
        $result = weather::formatHourlyEntry($hour, $tz, true, false);
        $this->assertStringContainsString('°C', $result);
        $this->assertStringNotContainsString('°F', $result);
    }

    public function test_detailed_entry_has_wind_humidity_pop(): void
    {
        $hour = self::makeHour();
        $tz = new \DateTimeZone('UTC');
        $result = weather::formatHourlyEntry($hour, $tz, false, true);
        $this->assertStringContainsString('mph', $result);
        $this->assertStringContainsString('45%h', $result);
        $this->assertStringContainsString('10%p', $result);
    }

    public function test_detailed_entry_metric_wind(): void
    {
        $hour = self::makeHour(['wind_speed' => 10.0]);
        $tz = new \DateTimeZone('UTC');
        $result = weather::formatHourlyEntry($hour, $tz, true, true);
        $this->assertStringContainsString('m/s', $result);
        $this->assertStringNotContainsString('mph', $result);
    }

    public function test_detailed_entry_zero_pop(): void
    {
        $hour = self::makeHour(['pop' => 0.0]);
        $tz = new \DateTimeZone('UTC');
        $result = weather::formatHourlyEntry($hour, $tz, false, true);
        $this->assertStringContainsString('0%p', $result);
    }

    public function test_detailed_entry_100_percent_pop(): void
    {
        $hour = self::makeHour(['pop' => 1.0]);
        $tz = new \DateTimeZone('UTC');
        $result = weather::formatHourlyEntry($hour, $tz, false, true);
        $this->assertStringContainsString('100%p', $result);
    }

    public function test_condition_capitalized(): void
    {
        $hour = self::makeHour(['weather' => [['description' => 'light rain']]]);
        $tz = new \DateTimeZone('UTC');
        $result = weather::formatHourlyEntry($hour, $tz, false, false);
        $this->assertStringContainsString('Light rain', $result);
    }

    public function test_timezone_applied(): void
    {
        $hour = self::makeHour(['dt' => 1700000000]);
        $tzUtc = new \DateTimeZone('UTC');
        $tzChicago = new \DateTimeZone('America/Chicago');
        $resultUtc = weather::formatHourlyEntry($hour, $tzUtc, false, false);
        $resultChicago = weather::formatHourlyEntry($hour, $tzChicago, false, false);
        $this->assertNotSame($resultUtc, $resultChicago);
    }

    public function test_wind_direction(): void
    {
        $hour = self::makeHour(['wind_deg' => 0]);
        $tz = new \DateTimeZone('UTC');
        $result = weather::formatHourlyEntry($hour, $tz, false, true);
        $this->assertStringContainsString('N', $result);
    }
}
```

- [ ] **Step 2: Run tests to verify they pass**

Run: `composer test -- tests/Weather/FormatHourlyEntryTest.php`
Expected: All tests PASS

- [ ] **Step 3: Commit**

```bash
git add tests/Weather/FormatHourlyEntryTest.php
git commit -m "weather: add tests for formatHourlyEntry"
```

---

### Task 3: Wire up `--hourly` flag, exclusivity, API, and output

**Files:**
- Modify: `scripts/weather/weather.php` lines 137-255

This task registers the new options, adds the exclusivity check, modifies the API call, and adds the hourly output branch.

- [ ] **Step 1: Update the `#[Options]` attribute on the weather command**

At line 139, change:
```php
    #[Options("--si", "--metric", "--us", "--imperial", "--fc", "--forecast")]
```
to:
```php
    #[Options("--si", "--metric", "--us", "--imperial", "--fc", "--forecast", "--hourly", "--hr", "--detailed", "--d")]
```

- [ ] **Step 2: Add `$hourly` flag variable after `$fc = false;` at line 156**

After line 156 (`$fc = false;`), add:
```php
        $hourly = false;
```

- [ ] **Step 3: Add exclusivity check for `--hourly` vs `--fc`**

After the existing si/imperial exclusivity block (after line 162), add:
```php
        if (($cmdArgs->optEnabled("--fc") || $cmdArgs->optEnabled("--forecast")) &&
            ($cmdArgs->optEnabled("--hourly") || $cmdArgs->optEnabled("--hr"))) {
            $bot->msg($args->chan, "Choose either --fc or --hourly not both");
            return;
        }
```

- [ ] **Step 4: Set `$hourly` flag (after the `$fc` flag setting at line 167)**

After line 167 (`$fc = true;`), add:
```php
        if ($cmdArgs->optEnabled("--hourly") || $cmdArgs->optEnabled("--hr")) {
            $hourly = true;
        }
```

- [ ] **Step 5: Make API `exclude` conditional on `$hourly`**

At line 215, change:
```php
            $url = "https://api.openweathermap.org/data/3.0/onecall?lat={$location->lat}&lon={$location->long}&appid=$config[openweatherKey]&exclude=minutely,hourly";
```
to:
```php
            $exclude = $hourly ? "minutely" : "minutely,hourly";
            $url = "https://api.openweathermap.org/data/3.0/onecall?lat={$location->lat}&lon={$location->long}&appid=$config[openweatherKey]&exclude=$exclude";
```

- [ ] **Step 6: Add the hourly output branch**

The output section (lines 236-255) currently has `if (!$fc) { ... } else { ... }`. Change it to handle three branches. Replace lines 236-255:

```php
            if ($hourly) {
                $detailed = $cmdArgs->optEnabled("--detailed") || $cmdArgs->optEnabled("--d");
                $entries = [];
                $cnt = 0;
                foreach ($j['hourly'] as $h) {
                    if ($cnt++ >= 12) break;
                    $entries[] = self::formatHourlyEntry($h, $tz, $si, $detailed);
                }
                $out = implode(', ', $entries);
                $bot->pm($args->chan, "\2{$location->name}:\2 Hourly: $out");
            } elseif (!$fc) {
                $bot->pm($args->chan, "\2{$location->name}:\2 Currently " . $cur['weather'][0]['description'] . " $temp $cur[humidity]% humidity, UVI of $cur[uvi], wind " . self::windDir($cur['wind_deg']) . " at $windSpeed Sun: $sunrise - $sunset");
            } else {
                $out = '';
                $cnt = 0;
                foreach ($j['daily'] as $d) {
                    if ($cnt++ >= 4) break;
                    $day = new \DateTime('@' . $d['dt']);
                    $day->setTimezone($tz);
                    $day = $day->format('D');
                    if ($cnt == 1) {
                        $day = "Today";
                    }
                    $tempMin = self::displayTemp($d['temp']['min'], $si);
                    $tempMax = self::displayTemp($d['temp']['max'], $si);
                    $w = $d['weather'][0]['main'];
                    $out .= "\2$day:\2 $w $tempMin/$tempMax $d[humidity]% humidity ";
                }
                $bot->pm($args->chan, "\2{$location->name}:\2 Forecast: $out");
            }
```

- [ ] **Step 7: Verify syntax**

Run: `php -l scripts/weather/weather.php`
Expected: `No syntax errors`

- [ ] **Step 8: Run tests**

Run: `composer test`
Expected: All tests PASS

- [ ] **Step 9: Commit**

```bash
git add scripts/weather/weather.php
git commit -m "weather: add --hourly flag for hourly forecast output"
```
