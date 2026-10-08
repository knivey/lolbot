<?php

namespace library\testenv;

/*
 * The v2 macro core: parse a macro script into steps and track inbound
 * matching, fully synchronous and clock-free. The client (testenv/client.php)
 * owns every async concern — the feeder loop walks the steps in order with
 * Amp primitives, arms timeouts around expect steps, and calls onInbound()
 * from the socket read loop. This class is the pure semantics that loop
 * relies on, unit-testable offline.
 *
 * Step shapes:
 *   ['type' => 'send',   'line' => string]        plain text / REPL verbs / .send
 *   ['type' => 'expect', 'pattern' => string, 'timeout' => float]
 *   ['type' => 'wait',   'seconds' => float]
 *
 * Expectations are sequential: onInbound() only ever matches the EARLIEST
 * unfulfilled expect step, so a line matching a later pattern is recorded
 * as chatter while an earlier expectation is still outstanding — the same
 * ordering a blocking feeder produces.
 */
final class Macro
{
    private const DEFAULT_TIMEOUT = 10.0;

    /** @var list<string> every inbound raw line, for failure transcripts */
    private array $recorded = [];

    /** @var list<int> indices into steps of expect steps, in order */
    private array $expectIdx = [];

    /** next unfulfilled entry in expectIdx */
    private int $expectPos = 0;

    /** set by failCurrentExpectation(): the pattern that timed out */
    private ?string $failedPattern = null;

    /**
     * @param list<array<string, mixed>> $steps from parse()
     */
    public function __construct(private readonly array $steps)
    {
        foreach ($this->steps as $i => $step) {
            if ($step['type'] === 'expect') {
                $this->expectIdx[] = $i;
            }
        }
    }

    /**
     * Parse macro text into steps. Throws MacroException naming the line
     * number on unknown directives or malformed .expect/.wait/.send.
     *
     * @return list<array<string, mixed>>
     */
    public static function parse(string $text): array
    {
        $steps = [];
        $lines = preg_split('/\r\n|\n|\r/', $text);
        if ($lines === false) {
            throw new MacroException('macro text could not be split into lines');
        }
        foreach ($lines as $i => $raw) {
            $line = trim($raw);
            $lineno = $i + 1;
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/^\.expect\s+\/(.+)\/(?:\s+([0-9]+(?:\.[0-9]+)?))?$/', $line, $m) === 1) {
                // validate the pattern NOW: a broken regex would otherwise
                // silently never-match and time out mid-run instead of
                // failing the macro at parse time with a line number
                if (@preg_match('/' . $m[1] . '/', '') === false) {
                    throw new MacroException("macro line {$lineno}: invalid regex '{$m[1]}'");
                }
                $steps[] = [
                    'type' => 'expect',
                    'pattern' => $m[1],
                    'timeout' => isset($m[2]) ? (float) $m[2] : self::DEFAULT_TIMEOUT,
                ];
                continue;
            }
            // word-boundary: only the exact directive word is syntax —
            // '.expectx' / '.waitx' are chat to the bot, not parse errors
            if (preg_match('/^\.expect(?=\s)/', $line) === 1) {
                // reached only when the delimited form didn't match
                throw new MacroException("macro line {$lineno}: .expect needs /pattern/ [timeout] — got '{$line}'");
            }
            if (preg_match('/^\.wait\s+(\d+(?:\.\d+)?)$/', $line, $m) === 1) {
                $steps[] = ['type' => 'wait', 'seconds' => (float) $m[1]];
                continue;
            }
            if (preg_match('/^\.wait(?=\s)/', $line) === 1) {
                throw new MacroException("macro line {$lineno}: .wait needs numeric seconds — got '{$line}'");
            }
            if (preg_match('/^\.send\s+(.+)$/', $line, $m) === 1) {
                $steps[] = ['type' => 'send', 'line' => $m[1]];
                continue;
            }
            // strict directive family: .send / .expect / .wait (arg
            // validation above). Every OTHER dot-line is chat to the bot —
            // bot commands are dot-prefixed too (.set, .cflags, ...), and
            // a typo'd directive should reach the bot and fail there,
            // same as a human at the REPL
            $steps[] = ['type' => 'send', 'line' => $line];
        }
        return $steps;
    }

    /** @return list<array<string, mixed>> */
    public function steps(): array
    {
        return $this->steps;
    }

    public function stepCount(): int
    {
        return count($this->steps);
    }

    /**
     * Every expectation fulfilled (a replay macro with no expect steps is
     * trivially done). The client derives its exit status from this.
     */
    public function done(): bool
    {
        return $this->expectPos >= count($this->expectIdx);
    }

    /**
     * How many expectations have been fulfilled so far. The feeder uses
     * this to detect BURST fulfillment: when several matching lines
     * arrive in one socket chunk, the read loop advances past expectations
     * the feeder hasn't armed yet — arming those would dead-wait for a
     * match that already happened (skip them instead).
     */
    public function fulfilledCount(): int
    {
        return $this->expectPos;
    }

    /**
     * Feed one inbound RAW wire line: always recorded; when it matches the
     * earliest outstanding expectation, that expectation is fulfilled and
     * true is returned (one line fulfills at most one expectation).
     */
    public function onInbound(string $rawLine): bool
    {
        $this->recorded[] = $rawLine;
        if ($this->done()) {
            return false;
        }
        $pattern = $this->steps[$this->expectIdx[$this->expectPos]]['pattern'] ?? null;
        if (is_string($pattern) && preg_match('/' . $pattern . '/', $rawLine) === 1) {
            $this->expectPos++;
            return true;
        }
        return false;
    }

    /**
     * Mark the earliest outstanding expectation as failed (the client calls
     * this when its timeout fires before a match). Safe to call with
     * nothing outstanding — a no-op.
     */
    public function failCurrentExpectation(): void
    {
        if (!$this->done()) {
            $pattern = $this->steps[$this->expectIdx[$this->expectPos]]['pattern'] ?? null;
            if (is_string($pattern)) {
                $this->failedPattern = $pattern;
            }
        }
    }

    /**
     * Failure transcript for a timed-out expectation, or null while no
     * expectation has been marked failed. `timeoutSeconds` is what the
     * caller actually waited, not a stored value — the core is clock-free.
     *
     * @return null|array{pattern: string, timeout: float, tail: list<string>}
     */
    public function failureReport(float $timeoutSeconds, int $tailLimit = 10): ?array
    {
        if ($this->failedPattern === null) {
            return null;
        }
        return [
            'pattern' => $this->failedPattern,
            'timeout' => $timeoutSeconds,
            'tail' => array_slice($this->recorded, -$tailLimit),
        ];
    }
}
