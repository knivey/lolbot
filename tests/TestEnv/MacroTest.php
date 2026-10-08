<?php

namespace Tests\TestEnv;

use library\testenv\Macro;
use library\testenv\MacroException;
use PHPUnit\Framework\TestCase;

// tests/TestEnv/MacroTest.php — the v2 macro core (parse + inbound
// matching + failure reporting), pure and clock-free: the client owns all
// async wiring (feeder loop, timeouts), this file pins the semantics the
// feeder relies on.
class MacroTest extends TestCase
{
    public function test_parse_plain_lines_and_repl_verbs_are_send_steps(): void
    {
        $steps = Macro::parse(".set minmode +\n/join #gate2\nplain text with /slash inside\n");
        $this->assertSame([
            ['type' => 'send', 'line' => '.set minmode +'],
            ['type' => 'send', 'line' => '/join #gate2'],
            ['type' => 'send', 'line' => 'plain text with /slash inside'],
        ], $steps);
    }

    public function test_parse_send_directive_escapes_repl_interpretation(): void
    {
        $steps = Macro::parse(".send /this is chat not a verb\n");
        $this->assertSame([['type' => 'send', 'line' => '/this is chat not a verb']], $steps);
    }

    public function test_parse_expect_defaults_to_ten_seconds(): void
    {
        $steps = Macro::parse(".expect /flags for/\n");
        $this->assertCount(1, $steps);
        $this->assertSame('expect', $steps[0]['type']);
        $this->assertSame('flags for', $steps[0]['pattern']);
        $this->assertSame(10.0, $steps[0]['timeout']);
    }

    public function test_parse_expect_with_explicit_timeout(): void
    {
        $steps = Macro::parse(".expect /whox|vhost/ 25\n.expect /quick/ 2.5\n");
        $this->assertSame(25.0, $steps[0]['timeout']);
        $this->assertSame(2.5, $steps[1]['timeout']);
    }

    public function test_parse_wait_accepts_fractional_seconds(): void
    {
        $steps = Macro::parse(".wait 2\n.wait 0.5\n");
        $this->assertSame([['type' => 'wait', 'seconds' => 2.0], ['type' => 'wait', 'seconds' => 0.5]], $steps);
    }

    public function test_parse_skips_comments_and_blank_lines(): void
    {
        $steps = Macro::parse("# a comment\n\n   \n.send real\n# trailing\n");
        $this->assertSame([['type' => 'send', 'line' => 'real']], $steps);
    }

    public function test_parse_unknown_dot_lines_are_chat_to_the_bot(): void
    {
        // bot commands are dot-prefixed (.set, .cflags): only the strict
        // directive family (.send/.expect/.wait) is macro syntax; a
        // typo'd directive rides to the bot as chat and fails THERE
        $steps = Macro::parse(".set minmode +\n.bogus thing\n.cflags *boss\n");
        $this->assertSame([
            ['type' => 'send', 'line' => '.set minmode +'],
            ['type' => 'send', 'line' => '.bogus thing'],
            ['type' => 'send', 'line' => '.cflags *boss'],
        ], $steps);
    }

    public function test_parse_malformed_expect_and_wait_throw(): void
    {
        $this->expectException(MacroException::class);
        $this->expectExceptionMessage('line 1');
        Macro::parse('.expect no-delimiters\n');
    }

    public function test_parse_wait_non_numeric_throws(): void
    {
        $this->expectException(MacroException::class);
        $this->expectExceptionMessage('line 1');
        Macro::parse('.wait soonish\n');
    }

    public function test_parse_expect_unclosed_delimiter_throws(): void
    {
        $this->expectException(MacroException::class);
        Macro::parse('.expect /never closed 5\n');
    }

    public function test_parse_invalid_regex_throws_with_line_number(): void
    {
        $this->expectException(MacroException::class);
        $this->expectExceptionMessage('line 2');
        Macro::parse(".send ok\n.expect /bad\\/\n");
    }

    public function test_parse_directive_word_boundary_sends_prefixed_chat(): void
    {
        // '.waitx' / '.expectx' are chat to the bot, not parse errors —
        // only the exact directive words are syntax
        $steps = Macro::parse(".waitx 5\n.expectx /y/\n.waitforit now\n");
        $this->assertSame([
            ['type' => 'send', 'line' => '.waitx 5'],
            ['type' => 'send', 'line' => '.expectx /y/'],
            ['type' => 'send', 'line' => '.waitforit now'],
        ], $steps);
    }

    public function test_fulfilled_count_tracks_burst_advancement(): void
    {
        // one burst of inbound lines can fulfill several expectations;
        // fulfilledCount() is how the feeder detects it armed too late
        $m = new Macro(Macro::parse(".expect /one/\n.expect /two/\n"));
        $this->assertSame(0, $m->fulfilledCount());
        $this->assertTrue($m->onInbound(':x NOTICE d :one'));
        $this->assertTrue($m->onInbound(':x NOTICE d :two'));
        $this->assertSame(2, $m->fulfilledCount());
        $this->assertTrue($m->done());
    }

    public function test_on_inbound_matches_raw_lines_and_advances_once(): void
    {
        $m = new Macro(Macro::parse(".send hi\n.expect /flags for/\n.send after\n"));
        $this->assertTrue($m->onInbound(':bot!b@h PRIVMSG #gate :flags for boss: quotes'));
        // expectation fulfilled — a second matching line is just chatter
        $this->assertFalse($m->onInbound(':bot!b@h PRIVMSG #gate :flags for boss: quotes'));
    }

    public function test_on_inbound_without_active_expectation_records_only(): void
    {
        $m = new Macro(Macro::parse(".send hi\n"));
        $this->assertFalse($m->onInbound(':bot!b@h PRIVMSG #gate :anything'));
    }

    public function test_on_inbound_matches_notices_too(): void
    {
        $m = new Macro(Macro::parse(".expect /auth required/\n"));
        $this->assertTrue($m->onInbound(':bot!b@h NOTICE drivernick :auth required'));
    }

    public function test_record_and_tail_capture_every_inbound_line(): void
    {
        $m = new Macro(Macro::parse(".expect /never/\n"));
        $m->onInbound(':x NOTICE d :one');
        $m->onInbound(':x NOTICE d :two');
        $m->onInbound(':x NOTICE d :three');
        $m->failCurrentExpectation();
        // 30s default-ish irrelevant: report shows what actually arrived
        $report = $m->failureReport(timeoutSeconds: 0.2);
        $this->assertNotNull($report);
        $this->assertSame('never', $report['pattern']);
        $this->assertSame(0.2, $report['timeout']);
        $this->assertCount(3, $report['tail']);
        $this->assertStringContainsString('three', (string) end($report['tail']));
    }

    public function test_failure_report_tail_is_bounded_and_keeps_last_lines(): void
    {
        $m = new Macro(Macro::parse(".expect /nope/\n"));
        foreach (range(1, 12) as $i) {
            $m->onInbound(":x NOTICE d :line {$i}");
        }
        $m->failCurrentExpectation();
        $report = $m->failureReport(timeoutSeconds: 1.0, tailLimit: 5);
        $this->assertNotNull($report);
        $this->assertCount(5, $report['tail']);
        $this->assertStringContainsString('line 12', (string) end($report['tail']));
        $this->assertStringContainsString('line 8', $report['tail'][0]);
    }

    public function test_failure_report_null_while_hope_remains(): void
    {
        $m = new Macro(Macro::parse(".expect /x/\n"));
        // no fail() called yet — the client only builds a report once an
        // expectation has timed out
        $this->assertNull($m->failureReport(timeoutSeconds: 1.0));
        $m->failCurrentExpectation();
        $this->assertNotNull($m->failureReport(timeoutSeconds: 1.0));
    }

    public function test_step_count_and_done_semantics(): void
    {
        $m = new Macro(Macro::parse(".send a\n.expect /x/\n"));
        $this->assertSame(2, $m->stepCount());
        $this->assertFalse($m->done());
    }
}
