<?php

namespace Tests\Weather;

use PHPUnit\Framework\TestCase;
use scripts\weather\weather;

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
        $this->assertStringStartsWith("\2$time:\2 Clear sky", $result);
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
