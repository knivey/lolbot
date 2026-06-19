<?php

namespace Tests\NetworkContext;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../NetworkContext.php';

class PumpLagSecondsTest extends TestCase
{
    public function test_no_speed_returns_configured_pumplag(): void
    {
        $this->assertSame(0.025, \NetworkContext::pumpLagSeconds(0.025, null));
    }

    public function test_speed_in_milliseconds_is_converted_to_seconds(): void
    {
        // Regression: previously --speed=499 was passed straight into
        // \Amp\delay() as if it were seconds, freezing the bot for ~8 minutes
        // between pump lines. It must be interpreted as milliseconds.
        $this->assertEqualsWithDelta(0.499, \NetworkContext::pumpLagSeconds(0.025, '499'), 1e-9);
    }

    public function test_speed_smaller_than_pumplag_is_clamped_to_pumplag(): void
    {
        // 20ms (lower bound of --speed) < default 25ms pumplag → keep pumplag.
        $this->assertEqualsWithDelta(0.025, \NetworkContext::pumpLagSeconds(0.025, '20'), 1e-9);
    }

    public function test_speed_larger_than_pumplag_wins(): void
    {
        $this->assertEqualsWithDelta(0.5, \NetworkContext::pumpLagSeconds(0.025, '500'), 1e-9);
    }

    public function test_missing_pumplag_config_uses_default(): void
    {
        $this->assertEqualsWithDelta(0.499, \NetworkContext::pumpLagSeconds(null, '499'), 1e-9);
    }

    public function test_non_numeric_pumplag_config_uses_default(): void
    {
        $this->assertEqualsWithDelta(0.499, \NetworkContext::pumpLagSeconds('garbage', '499'), 1e-9);
    }

    public function test_non_numeric_speed_is_ignored(): void
    {
        $this->assertSame(0.025, \NetworkContext::pumpLagSeconds(0.025, 'notanumber'));
    }

    public function test_numeric_string_config_is_accepted(): void
    {
        $this->assertEqualsWithDelta(0.1, \NetworkContext::pumpLagSeconds('0.1', null), 1e-9);
    }

    public function test_speed_zero_is_treated_as_no_speed(): void
    {
        // Validation upstream enforces --speed >= 20, but the helper must not
        // treat '0' as a meaningful ms value (would yield 0.0 via /1000).
        $this->assertSame(0.025, \NetworkContext::pumpLagSeconds(0.025, '0'));
    }

    public function test_speed_negative_is_ignored(): void
    {
        $this->assertSame(0.025, \NetworkContext::pumpLagSeconds(0.025, '-5'));
    }

    public function test_speed_empty_string_is_ignored(): void
    {
        $this->assertSame(0.025, \NetworkContext::pumpLagSeconds(0.025, ''));
    }

    public function test_pumplag_zero_is_distinct_from_unset(): void
    {
        // A config that explicitly sets pumplag: 0 means "no minimum delay";
        // it must not be conflated with the unset/default case (0.025).
        $this->assertSame(0.0, \NetworkContext::pumpLagSeconds(0, null));
    }
}
