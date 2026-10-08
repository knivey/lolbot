<?php
// tests/TestEnv/ClientDrainTest.php — proves testenv/client.php drains inbound
// server lines after /quit instead of racing fully-buffered stdin to exit
// (issue #142 item 2). A plain stream_socket_server emulator speaks just
// enough IRC (001, a PRIVMSG echo, a post-QUIT reply) to drive the real
// client process via proc_open — no bot, no testenv profile machinery.
use PHPUnit\Framework\TestCase;

class ClientDrainTest extends TestCase
{
    /** overall wall-clock bound so every scenario can never hang */
    private const DEADLINE = 15.0;

    public function test_quit_drains_trailing_inbound_before_exit(): void
    {
        [$exitCode, $elapsed, $out, $err] = $this->driveClient('drain');

        $this->assertSame(0, $exitCode, "client exit code; stderr: {$err}\nstdout: {$out}");
        // the drain marker tells a human why exit is delayed
        $this->assertStringContainsString('*** draining inbound before exit', $out, "stdout: {$out}");
        // the in-flight reply to our last command must be read before exit
        $this->assertStringContainsString('INFLIGHT-REPLY', $out, "stdout: {$out}");
        // the reply that arrives after QUIT is the one the old race dropped
        $this->assertStringContainsString('TRAILING-REPLY-AFTER-QUIT', $out, "stdout: {$out}");
        // the server closed the connection, so the drain must end on EOF,
        // not on the 3s safety bound
        $this->assertStringNotContainsString('*** drain timeout', $out, "stdout: {$out}");
    }

    public function test_drain_times_out_when_server_never_closes(): void
    {
        [$exitCode, $elapsed, $out, $err] = $this->driveClient('never-close');

        $this->assertSame(0, $exitCode, "client exit code; stderr: {$err}\nstdout: {$out}");
        $this->assertStringContainsString('*** drain timeout', $out, "stdout: {$out}");
        // it actually waited for the 3s safety bound instead of exiting early...
        $this->assertGreaterThanOrEqual(2.5, $elapsed);
        // ...but is still bounded by it
        $this->assertLessThan(6.0, $elapsed);
    }

    public function test_exits_promptly_without_crash_when_server_closes_first(): void
    {
        [$exitCode, $elapsed, $out, $err] = $this->driveClient('close-now');

        // unexpected disconnect (no /quit in flight) is still an error exit
        $this->assertSame(1, $exitCode, "client exit code; stderr: {$err}\nstdout: {$out}");
        $this->assertStringContainsString('*** disconnected', $out, "stdout: {$out}");
        // no 3s wait: the read loop ended on EOF
        $this->assertLessThan(3.0, $elapsed);
        // writes against the already-dead socket (NICK/USER burst) must not
        // surface as an UnhandledFutureError fatal in the event loop
        $this->assertStringNotContainsString('Fatal error', $err . $out);
    }

    /**
     * Run `php testenv/client.php` against an in-test IRC emulator and return
     * [exit code, elapsed seconds, stdout, stderr].
     *
     * Scenarios:
     *  - 'drain':       register the client, then on JOIN pipe the fully
     *                   buffered stdin macro (`hello` + `/quit` + EOF); reply
     *                   to the PRIVMSG, then after seeing QUIT send one more
     *                   reply and close — the trailing inbound the drain must
     *                   still process before exit.
     *  - 'never-close': register, /quit via stdin, but never reply to QUIT
     *                   and never close — exercises the 3s safety bound.
     *  - 'close-now':   accept the connection then immediately close it —
     *                   the client must exit promptly and not crash on the
     *                   registration burst it writes into the dead socket.
     *
     * @return array{0: int, 1: float, 2: string, 3: string}
     */
    private function driveClient(string $scenario): array
    {
        $root = dirname(__DIR__, 2);

        // ephemeral listener so the port can never collide with a real server
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, "listener failed: {$errstr} ({$errno})");
        $addr = stream_socket_get_name($server, false);
        $this->assertIsString($addr, 'stream_socket_get_name failed');
        $port = (int) substr((string) strrchr($addr, ':'), 1);

        // Profile::load() reads testenv/profiles/<name>.yaml; a confined name
        // is written here and unlinked in the finally below so failed
        // assertions can never leak it into the repo.
        $profileName = 'client_drain_test';
        $profileFile = $root . "/testenv/profiles/{$profileName}.yaml";
        file_put_contents($profileFile, sprintf(
            "driver:\n" .
            "  nick: DrainCli\n" .
            "  server: {address: 127.0.0.1, port: %d}\n" .
            "networks:\n" .
            "  - name: DrainNet\n" .
            "    servers:\n" .
            "      - {address: 127.0.0.1, port: %d}\n" .
            "    bots:\n" .
            "      - {name: DrainBot, channels: [\"#drain\"]}\n",
            $port,
            $port
        ));

        $proc = proc_open(
            ['php', 'testenv/client.php', $profileName],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root
        );
        $this->assertIsResource($proc, 'proc_open of testenv/client.php failed');
        [$stdin, $stdout, $stderr] = $pipes;
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);

        $out = '';
        $err = '';
        /** @var resource|null $conn the accepted client connection */
        $conn = null;
        $inBuf = '';
        $stdinWritten = false;
        $sawQuit = false;
        $trailingAt = null;
        $trailingSent = false;
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
                    $chunk = fread($conn, 8192);
                    if (is_string($chunk) && $chunk !== '') {
                        $inBuf .= $chunk;
                        while (($nl = strpos($inBuf, "\n")) !== false) {
                            $line = rtrim(substr($inBuf, 0, $nl), "\r");
                            $inBuf = substr($inBuf, $nl + 1);
                            $cmd = strtoupper((string) strtok($line, ' '));
                            if ($cmd === 'USER') {
                                // enough registration for the client to banner + JOIN
                                fwrite($conn, ":drain.test 001 DrainCli :Welcome\r\n");
                            } elseif ($cmd === 'JOIN' && !$stdinWritten) {
                                // client is registered and joined: fire the
                                // fully-buffered stdin macro from the issue —
                                // commands + /quit + immediate EOF
                                $stdinWritten = true;
                                fwrite($stdin, $scenario === 'never-close' ? "/quit\n" : "hello\n/quit\n");
                                fclose($stdin);
                            } elseif ($cmd === 'PRIVMSG' && $scenario === 'drain') {
                                // in-flight bot reply: exactly the class of line
                                // the old code dropped when /quit raced stdin EOF
                                fwrite($conn, ":DrainBot!d@test PRIVMSG #drain :INFLIGHT-REPLY\r\n");
                            } elseif ($cmd === 'QUIT') {
                                $sawQuit = true;
                                if ($scenario === 'drain') {
                                    // trailing inbound arrives only AFTER QUIT was
                                    // seen — what the drain must still process
                                    $trailingAt = microtime(true) + 0.3;
                                }
                            }
                        }
                    }
                } elseif (in_array($server, $read, true)) {
                    $newConn = @stream_socket_accept($server, 0);
                    if (is_resource($newConn)) {
                        $conn = $newConn;
                        stream_set_blocking($conn, false);
                        if ($scenario === 'close-now') {
                            // slam the connection shut before reading anything
                            fclose($conn);
                            $conn = null;
                        }
                    }
                }

                if ($sawQuit && !$trailingSent && $trailingAt !== null && microtime(true) >= $trailingAt) {
                    $trailingSent = true;
                    if (is_resource($conn) && !feof($conn)) {
                        fwrite($conn, ":DrainBot!d@test PRIVMSG #drain :TRAILING-REPLY-AFTER-QUIT\r\n");
                    }
                    if (is_resource($conn)) {
                        // server closes the connection — the client's drain ends
                        fclose($conn);
                        $conn = null;
                    }
                }

                // non-blocking sweep of the client's pipes each turn
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

            // drain whatever is still buffered in the pipes after exit
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
            if (is_resource($stdin)) {
                fclose($stdin);
            }
            if (is_resource($stdout)) {
                fclose($stdout);
            }
            if (is_resource($stderr)) {
                fclose($stderr);
            }
            if (is_resource($conn)) {
                fclose($conn);
            }
            if (is_resource($server)) {
                fclose($server);
            }
            if (is_resource($proc)) {
                proc_close($proc);
            }
            if (is_file($profileFile)) {
                unlink($profileFile);
            }
        }

        return [$exitCode, microtime(true) - $start, $out, $err];
    }
}
