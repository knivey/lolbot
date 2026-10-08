<?php

// tests/TestEnv/MacroRunTest.php — proves the v2 macro runner end-to-end:
// the real testenv/client.php in macro mode against a plain stream_socket
// IRC emulator (ClientDrainTest's harness shape, minus the stdin feed —
// the feeder is the macro). Exit codes: 0 = all expectations met,
// 1 = expectation timeout, 2 = parse error.
use PHPUnit\Framework\TestCase;

class MacroRunTest extends TestCase
{
    /** overall wall-clock bound so every scenario can never hang */
    private const DEADLINE = 15.0;

    public function test_passing_macro_exits_zero_with_transcript(): void
    {
        [$exitCode, $out, $err] = $this->runMacro(
            "hello bot\n.expect /flags for/ 5\n",
            reply: ':EmuBot!e@t PRIVMSG #gate :flags for boss: quotes',
        );

        $this->assertSame(0, $exitCode, "exit code; stderr: {$err}\nstdout: {$out}");
        $this->assertStringContainsString('*** MACRO PASS', $out, "stdout: {$out}");
        $this->assertStringContainsString('*** ok: /flags for/', $out, "stdout: {$out}");
        // the transcript is the run's evidence: both wire directions echo
        $this->assertStringContainsString('>> PRIVMSG #gate :hello bot', $out, "stdout: {$out}");
        $this->assertStringContainsString('<< :EmuBot!e@t PRIVMSG #gate :flags for boss: quotes', $out, "stdout: {$out}");
        $this->assertStringContainsString('*** draining inbound before exit', $out, "stdout: {$out}");
    }

    public function test_timing_out_macro_exits_one_with_failure_report(): void
    {
        [$exitCode, $out, $err] = $this->runMacro(
            // probe first so chatter actually arrives, then expect something
            // that never comes — the failure tail must show the real replies
            ".send probe\n.expect /never comes/ 1\n",
            reply: ':EmuBot!e@t PRIVMSG #gate :unrelated chatter',
        );

        $this->assertSame(1, $exitCode, "exit code; stderr: {$err}\nstdout: {$out}");
        $this->assertStringContainsString('*** MACRO FAIL: expected /never comes/ within 1s', $out, "stdout: {$out}");
        // what the bot ACTUALLY said is in the failure tail
        $this->assertStringContainsString('unrelated chatter', $out, "stdout: {$out}");
    }

    public function test_parse_error_exits_two_without_running(): void
    {
        [$exitCode, $out, $err] = $this->runMacro(".wait soonish\n", reply: null);

        $this->assertSame(2, $exitCode, "exit code; stderr: {$err}\nstdout: {$out}");
        $this->assertStringContainsString('line 1', $err, "stderr: {$err}");
        $this->assertStringNotContainsString('MACRO', $out . $err);
    }

    public function test_sequential_expectation_orders_steps(): void
    {
        // two commands, two expectations: the feeder must not fire the
        // second send until the first expectation matched
        [$exitCode, $out, $err] = $this->runMacro(
            "first cmd\n.expect /first reply/\nsecond cmd\n.expect /second reply/ 5\n",
            reply: ':EmuBot!e@t PRIVMSG #gate :first reply',
            secondReply: ':EmuBot!e@t PRIVMSG #gate :second reply',
        );

        $this->assertSame(0, $exitCode, "exit code; stderr: {$err}\nstdout: {$out}");
        $this->assertStringContainsString('*** MACRO PASS', $out, "stdout: {$out}");
        // wire order proves sequencing: first PRIVMSG, first reply, second
        // PRIVMSG only after the first reply arrived
        $firstSend = strpos($out, '>> PRIVMSG #gate :first cmd');
        $firstReply = strpos($out, '<< :EmuBot!e@t PRIVMSG #gate :first reply');
        $secondSend = strpos($out, '>> PRIVMSG #gate :second cmd');
        $this->assertIsInt($firstSend);
        $this->assertIsInt($firstReply);
        $this->assertIsInt($secondSend);
        $this->assertLessThan($firstReply, $firstSend);
        $this->assertLessThan($secondSend, $firstReply);
    }

    public function test_burst_fulfillment_does_not_false_fail(): void
    {
        // review finding: two matching lines arriving in ONE socket chunk
        // advance the macro past two expectations while the feeder has
        // only the first armed — arming the second must be skipped, not
        // dead-waited into a bogus timeout
        [$exitCode, $out, $err] = $this->runMacro(
            ".send go\n.expect /first line/\n.expect /second line/ 3\n",
            reply: ':EmuBot!e@t PRIVMSG #gate :first line',
            secondReply: ':EmuBot!e@t PRIVMSG #gate :second line',
            burst: true,
        );

        $this->assertSame(0, $exitCode, "exit code; stderr: {$err}\nstdout: {$out}");
        $this->assertStringContainsString('*** MACRO PASS', $out, "stdout: {$out}");
        $this->assertStringContainsString('*** ok: /second line/ (burst)', $out, "stdout: {$out}");
        $this->assertStringNotContainsString('MACRO FAIL', $out, "stdout: {$out}");
    }

    /**
     * Run the client in macro mode against an in-test IRC emulator.
     * Emulator behavior: 001 on USER; on each PRIVMSG send $reply (and
     * $secondReply once, after the first reply was matched — in ONE chunk
     * together when $burst); close on QUIT.
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runMacro(string $macroText, ?string $reply, ?string $secondReply = null, bool $burst = false): array
    {
        $root = dirname(__DIR__, 2);

        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, "listener failed: {$errstr} ({$errno})");
        $addr = stream_socket_get_name($server, false);
        $port = (int) substr((string) strrchr((string) $addr, ':'), 1);

        $profileName = 'macro_run_test';
        $profileFile = $root . "/testenv/profiles/{$profileName}.yaml";
        file_put_contents($profileFile, sprintf(
            "driver:\n" .
            "  nick: MacroCli\n" .
            "  server: {address: 127.0.0.1, port: %d}\n" .
            "networks:\n" .
            "  - name: MacroNet\n" .
            "    servers:\n" .
            "      - {address: 127.0.0.1, port: %d}\n" .
            "    bots:\n" .
            "      - {name: MacroBot, channels: [\"#gate\"]}\n",
            $port,
            $port,
        ));

        $macroFile = tempnam(sys_get_temp_dir(), 'macro_run_');
        file_put_contents($macroFile, $macroText);

        $proc = proc_open(
            ['php', 'testenv/client.php', $profileName, $macroFile],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );
        $this->assertIsResource($proc, 'proc_open of testenv/client.php failed');
        [$stdin, $stdout, $stderr] = $pipes;
        fclose($stdin);
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);

        $out = '';
        $err = '';
        /** @var resource|null $conn */
        $conn = null;
        $inBuf = '';
        $privmsgs = 0;
        $secondSent = false;
        $exitCode = -1;
        $start = microtime(true);

        try {
            while (true) {
                if (microtime(true) - $start > self::DEADLINE) {
                    $this->fail("client did not exit within " . self::DEADLINE . "s; stdout so far: {$out}");
                }
                $status = proc_get_status($proc);
                if (!$status['running']) {
                    $exitCode = $status['exitcode'];
                    break;
                }

                $read = [$server, $stdout, $stderr];
                if (is_resource($conn)) {
                    $read[] = $conn;
                }
                $write = null;
                $except = null;
                stream_select($read, $write, $except, 0, 200000);

                if (is_resource($conn)) {
                    // narrowed copy: the QUIT branch nulls $conn below, so
                    // the loop-local keeps a provably-open resource for writes
                    $c = $conn;
                    $chunk = fread($c, 8192);
                    if (is_string($chunk) && $chunk !== '') {
                        $inBuf .= $chunk;
                        while (($nl = strpos($inBuf, "\n")) !== false) {
                            $line = rtrim(substr($inBuf, 0, $nl), "\r");
                            $inBuf = substr($inBuf, $nl + 1);
                            $cmd = strtoupper((string) strtok($line, ' '));
                            if ($cmd === 'USER') {
                                fwrite($c, ":macro.test 001 MacroCli :Welcome\r\n");
                            } elseif ($cmd === 'PRIVMSG' && $reply !== null) {
                                $privmsgs++;
                                if ($burst && $secondReply !== null && $privmsgs === 1) {
                                    // both replies in ONE fwrite = one socket
                                    // chunk = the burst the feeder must survive
                                    fwrite($c, $reply . "\r\n" . $secondReply . "\r\n");
                                    $secondSent = true;
                                } else {
                                    fwrite($c, $reply . "\r\n");
                                }
                                // the second reply rides the SECOND command,
                                // proving the feeder waited for the first
                                if ($secondReply !== null && !$burst && $privmsgs >= 2 && !$secondSent) {
                                    $secondSent = true;
                                    fwrite($c, $secondReply . "\r\n");
                                }
                            } elseif ($cmd === 'QUIT') {
                                // server closes — the drain ends on EOF
                                fclose($c);
                                $conn = null;
                            }
                        }
                    }
                } elseif (in_array($server, $read, true)) {
                    $newConn = @stream_socket_accept($server, 0);
                    if (is_resource($newConn)) {
                        $conn = $newConn;
                        stream_set_blocking($conn, false);
                    }
                }

                foreach ([$stdout, $stderr] as $i => $pipe) {
                    $chunk = fread($pipe, 65536);
                    if (is_string($chunk) && $chunk !== '') {
                        if ($i === 1) {
                            $err .= $chunk;
                        } else {
                            $out .= $chunk;
                        }
                    }
                }
            }

            foreach ([$stdout, $stderr] as $i => $pipe) {
                while (!feof($pipe)) {
                    $chunk = fread($pipe, 65536);
                    if (!is_string($chunk) || $chunk === '') {
                        break;
                    }
                    if ($i === 1) {
                        $err .= $chunk;
                    } else {
                        $out .= $chunk;
                    }
                }
            }
        } finally {
            if (is_resource($conn)) {
                fclose($conn);
            }
            fclose($server);
            proc_close($proc);
            unlink($macroFile);
            @unlink($profileFile);
        }

        return [$exitCode, $out, $err];
    }
}
