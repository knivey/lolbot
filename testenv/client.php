<?php
// testenv/client.php — interactive IRC client for a test-env profile's
// driver identity. Launched by `php testenv.php <profile> client` or run
// directly as `php testenv/client.php <profile>`.
//
// Connects as the profile driver (nick; SASL PLAIN only when the profile
// supplies BOTH sasl_account and sasl_pass), auto-joins the first network's
// first bot's channels, prints every inbound PRIVMSG through
// library\testenv\ClientFormat, and reads stdin:
//   plain line        -> PRIVMSG to the first joined channel
//   /msg <nick> <text>, /join <chan>, /part <chan>, /raw <line>, /help, /quit
// PINGs are answered automatically.
//
// Like testenv.php this script only needs the autoloader — it must NEVER
// require bootstrap.php, so it never boots against a real bot config or
// the dev database.

require_once dirname(__DIR__) . '/vendor/autoload.php';

use function Amp\async;
use Amp\ByteStream\StreamException;
use function Amp\ByteStream\getStdin;
use Amp\Socket\ConnectContext;
use Amp\Socket\ClientTlsContext;
use function Amp\Socket\connect;
use Irc\Message;
use library\testenv\ClientFormat;
use library\testenv\Profile;
use library\testenv\ProfileException;
use Revolt\EventLoop;

/**
 * The parse-to-format step the REPL runs on every inbound line: parse with
 * Irc\Message::parse and render PRIVMSGs through ClientFormat. Returns null
 * for non-PRIVMSG (or unparseable/argless) lines so the caller can route
 * them elsewhere. Split out pure so an offline harness can exercise the
 * exact pipeline the REPL uses without a network.
 */
function client_format_message(string $rawLine): ?string
{
    $msg = Message::parse($rawLine);
    if ($msg === null || strcasecmp($msg->command, 'PRIVMSG') !== 0) {
        return null;
    }
    $target = $msg->getArg(0);
    $text = $msg->getArg(1);
    $from = $msg->nick ?? $msg->getHostString();
    if ($target === null || $text === null || $from === '') {
        return null;
    }
    return ClientFormat::formatLine(['from' => $from, 'target' => $target, 'text' => $text]);
}

/**
 * Pop the next complete line (terminated by \r or \n) off the read buffer,
 * mirroring Irc\Client::getLine()'s framing idiom.
 */
function client_next_line(string &$inQ): ?string
{
    $r = strpos($inQ, "\r");
    $n = strpos($inQ, "\n");
    if ($r === false && $n === false) {
        return null;
    }
    $end = (int)max($r, $n) + 1;
    $line = substr($inQ, 0, $end);
    $inQ = substr($inQ, $end);
    return trim($line, "\r\n");
}

/**
 * Resolve the IRC endpoint: a per-driver `server: {address, port?, ssl?}`
 * override key is honored verbatim when present in the profile data;
 * otherwise the first network's first server.
 *
 * @return array{address: string, port: int, ssl: bool}
 */
function client_endpoint(Profile $profile): array
{
    $driver = $profile->driver();
    $srv = $driver['server'] ?? null;
    if (!is_array($srv)) {
        $net = $profile->networks()[0];
        $servers = is_array($net['servers'] ?? null) ? $net['servers'] : [];
        $srv = is_array($servers[0] ?? null) ? $servers[0] : [];
    }
    $address = is_string($srv['address'] ?? null) ? $srv['address'] : '';
    $port = is_int($srv['port'] ?? null) ? $srv['port'] : 6667;
    $ssl = is_bool($srv['ssl'] ?? null) ? $srv['ssl'] : false;
    return ['address' => $address, 'port' => $port, 'ssl' => $ssl];
}

/**
 * Auto-join list: the first network's first bot's channels.
 *
 * @return array<int, string>
 */
function client_channels(Profile $profile): array
{
    $net = $profile->networks()[0];
    $bots = is_array($net['bots'] ?? null) ? $net['bots'] : [];
    $bot = is_array($bots[0] ?? null) ? $bots[0] : [];
    $channels = is_array($bot['channels'] ?? null) ? $bot['channels'] : [];
    $names = [];
    foreach ($channels as $chan) {
        if (is_string($chan) && $chan !== '') {
            $names[] = $chan;
        }
    }
    return $names;
}

/**
 * @param array<int, string> $argv
 */
function testenv_client_main(array $argv): int
{
    $profileName = $argv[1] ?? '';
    if ($profileName === '') {
        fwrite(STDERR, "Usage: php testenv/client.php <profile>\n");
        return 1;
    }
    try {
        $profile = Profile::load($profileName);
    } catch (ProfileException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        return 1;
    }

    $driver = $profile->driver();
    $nick = is_string($driver['nick'] ?? null) ? $driver['nick'] : '';
    if ($nick === '') {
        fwrite(STDERR, "profile '{$profileName}' has no driver nick\n");
        return 1;
    }
    // SASL only when BOTH halves are present — otherwise registration is a
    // plain PASS-less NICK/USER and the human can IDENTIFY via /raw.
    $saslUser = is_string($driver['sasl_account'] ?? null) && $driver['sasl_account'] !== ''
        ? $driver['sasl_account'] : null;
    $saslPass = is_string($driver['sasl_pass'] ?? null) && $driver['sasl_pass'] !== ''
        ? $driver['sasl_pass'] : null;
    /** @var array{0: string, 1: string}|null $sasl */
    $sasl = ($saslUser !== null && $saslPass !== null) ? [$saslUser, $saslPass] : null;
    $endpoint = client_endpoint($profile);
    if ($endpoint['address'] === '') {
        fwrite(STDERR, "profile '{$profileName}' has no server address for the driver\n");
        return 1;
    }
    $channels = client_channels($profile);
    // post-001 auth lines (GameSurge AuthServ — no SASL there; auth
    // happens after welcome)
    $onConnect = $profile->driverOnConnect();

    $code = 0;
    // lolbot.php's idiom: spawn the session as an Amp coroutine from main,
    // then run the loop until it drains
    async(function () use ($nick, $sasl, $endpoint, $channels, $onConnect, &$code): void {
        // connect + TLS idiom copied from Irc\Client::go()/__construct():
        // ConnectContext, ClientTlsContext without peer verification,
        // setupTls() right after the socket opens
        try {
            $context = new ConnectContext();
            if ($endpoint['ssl']) {
                $context = $context->withTlsContext(
                    (new ClientTlsContext($endpoint['address']))->withoutPeerVerification()
                );
            }
            $socket = connect($endpoint['address'] . ':' . $endpoint['port'], $context);
            if ($endpoint['ssl']) {
                $socket->setupTls();
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf(
                "connect to %s:%d failed: %s\n",
                $endpoint['address'],
                $endpoint['port'],
                $e->getMessage()
            ));
            $code = 1;
            return;
        }

        $send = static function (string $line) use ($socket): void {
            try {
                $socket->write($line . "\r\n");
            } catch (StreamException) {
                // socket already gone (e.g. broken pipe during the quit
                // drain) — the read loop's EOF path below handles the exit
            }
        };

        /** @var array<int, string> $joined first entry is where plain lines go */
        $joined = [];
        $bannerDone = false;
        // set once /quit (or stdin EOF) starts the exit drain: later stdin
        // lines are ignored and the socket read loop keeps running until the
        // server closes the connection (or the 3s safety timer below fires)
        $quitting = false;

        // Issue #142 item 2: fully-buffered stdin + EOF used to exit before
        // inbound bot replies still in flight got read (v2 scripted macros
        // pipe all their lines then EOF, so /quit raced the socket). Instead
        // of closing the socket here, send QUIT and let the read loop below
        // drain inbound until EOF or the safety bound, then exit.
        $beginQuit = function () use ($send, &$quitting): void {
            if ($quitting) {
                // already draining — stdin EOF after an explicit /quit (or a
                // second /quit) must not restart or duplicate the drain
                return;
            }
            $quitting = true;
            $send('QUIT :testenv client closing');
            echo "*** draining inbound before exit...\n";
            // safety bound: a server that ignores QUIT and never closes must
            // not hang the client
            EventLoop::delay(3, static function (): void {
                echo "\n*** drain timeout — exiting\n";
                exit(0);
            });
        };

        $handleServerLine = function (string $line) use ($send, $nick, $sasl, $endpoint, $channels, $onConnect, &$joined, &$bannerDone): void {
            $msg = Message::parse($line);
            if ($msg === null) {
                return;
            }
            switch (strtoupper($msg->command)) {
                case 'PING':
                    // auto-reply, same as Irc\Client's CMD_PING case
                    $send('PONG ' . $msg->getArg(0, $endpoint['address']));
                    return;
                case 'PRIVMSG':
                    $formatted = client_format_message($line);
                    if ($formatted !== null) {
                        $target = $msg->getArg(0) ?? '';
                        // any IRC chantype (# + ! &) marks channel output;
                        // a nick/account target is a [PM]
                        $isChannel = $target !== '' && strpbrk($target[0], '#+!&') !== false;
                        echo ($isChannel ? '' : '[PM] ') . $formatted, "\n";
                    }
                    return;
                case 'CAP':
                    $sub = $msg->getArg(1) ?? '';
                    $caps = $msg->getArg(2) ?? '';
                    if ($sasl !== null && strcasecmp($sub, 'ACK') === 0 && stripos($caps, 'sasl') !== false) {
                        $send('AUTHENTICATE PLAIN');
                    } elseif (strcasecmp($sub, 'NAK') === 0) {
                        echo "<< {$line}\n";
                        echo "*** server refused the CAP request — continuing without SASL (/raw for NickServ)\n";
                        $send('CAP END');
                    }
                    return;
                case 'AUTHENTICATE':
                    // server asks for the payload with '+'
                    if ($sasl !== null && ($msg->getArg(0) ?? '') === '+') {
                        // authzid \0 authcid \0 password, same layout as Irc\Client
                        $send('AUTHENTICATE ' . base64_encode("{$sasl[0]}\x00{$sasl[0]}\x00{$sasl[1]}"));
                    }
                    return;
                case '903':
                    echo "<< {$line}\n*** SASL login ok\n";
                    $send('CAP END');
                    return;
                case '904':
                case '905':
                case '906':
                case '907':
                    echo "<< {$line}\n*** SASL failed — continue manually: /raw PRIVMSG NickServ :IDENTIFY <pass>\n";
                    $send('CAP END');
                    return;
                case '001':
                    if (!$bannerDone) {
                        $bannerDone = true;
                        if ($channels !== []) {
                            $send('JOIN ' . implode(',', $channels));
                            $joined = $channels;
                        }
                        $target = $joined[0] ?? '(no auto-join channel — /join <chan> first)';
                        echo "*** connected to {$endpoint['address']}:{$endpoint['port']} as {$nick}\n";
                        echo "*** sending to: {$target} — plain lines go there; /help lists commands\n";
                        foreach ($onConnect as $authLine) {
                            $send($authLine);
                        }
                        if ($onConnect !== []) {
                            // don't echo the lines themselves — they carry
                            // passwords; the tester knows what they configured
                            echo "*** sent " . count($onConnect) . " on_connect line(s)\n";
                        }
                    }
                    return;
                case 'ERROR':
                    echo "<< {$line}\n";
                    return;
                default:
                    // surface registration/join failures; MOTD and other
                    // chatter stays quiet
                    if (preg_match('/^[45]\d\d$/', $msg->command) === 1) {
                        echo "<< {$line}\n";
                        if ($msg->command === '433') {
                            echo "*** nickname already in use — recover with /raw NICK <newnick>\n";
                        }
                    }
            }
        };

        $handleInput = function (string $line) use ($send, $beginQuit, &$joined, &$quitting): void {
            if ($quitting) {
                // drain in progress: stdin lines after /quit are ignored
                return;
            }
            if ($line === '') {
                return;
            }
            if ($line[0] !== '/') {
                $target = $joined[0] ?? null;
                if ($target === null) {
                    echo "*** not in a channel — /join <chan> first\n";
                    return;
                }
                $send("PRIVMSG {$target} :{$line}");
                return;
            }
            $sp = strpos($line, ' ');
            $cmd = strtolower($sp === false ? substr($line, 1) : substr($line, 1, $sp - 1));
            $rest = $sp === false ? '' : trim(substr($line, $sp + 1));
            switch ($cmd) {
                case 'msg':
                    [$to, $text] = array_pad(explode(' ', $rest, 2), 2, '');
                    if ($to === '' || $text === '') {
                        echo "*** usage: /msg <nick> <text>\n";
                        return;
                    }
                    $send("PRIVMSG {$to} :{$text}");
                    return;
                case 'join':
                    if ($rest === '') {
                        echo "*** usage: /join <chan>[,<chan>...]\n";
                        return;
                    }
                    $send("JOIN {$rest}");
                    // comma-lists stay one JOIN on the wire but bookkeep
                    // per channel; channel names are case-insensitive, so
                    // joining #Gate then #gate must not double-bookkeep.
                    // Only chantype-prefixed items are channels — a key
                    // arg (`/join #a,#b k1,k2`) is not bookkept.
                    $known = array_map('strtolower', $joined);
                    foreach (explode(',', $rest) as $chan) {
                        $chan = trim($chan);
                        if ($chan === '' || strpbrk($chan[0], '#+!&') === false) {
                            continue;
                        }
                        if (in_array(strtolower($chan), $known, true)) {
                            continue;
                        }
                        $known[] = strtolower($chan);
                        $joined[] = $chan;
                    }
                    return;
                case 'part':
                    if ($rest === '') {
                        echo "*** usage: /part <chan>[,<chan>...]\n";
                        return;
                    }
                    $send("PART {$rest}");
                    // mirror /join: comma-lists, case-insensitive removal
                    $leaving = array_map('strtolower', array_filter(
                        array_map('trim', explode(',', $rest)),
                        static fn (string $chan): bool => $chan !== ''
                    ));
                    $joined = array_values(array_filter(
                        $joined,
                        static fn (string $chan): bool => !in_array(strtolower($chan), $leaving, true)
                    ));
                    return;
                case 'raw':
                    if ($rest === '') {
                        echo "*** usage: /raw <line>\n";
                        return;
                    }
                    $send($rest);
                    return;
                case 'help':
                    echo "commands:\n";
                    echo "  <plain line>          send to the first joined channel\n";
                    echo "  /msg <nick> <text>    send a private message\n";
                    echo "  /join <chan>[,<chan>...] join channel(s)\n";
                    echo "  /part <chan>[,<chan>...] leave channel(s)\n";
                    echo "  /raw <line>           send a raw IRC line\n";
                    echo "  /help                 this help\n";
                    echo "  /quit                 close and exit\n";
                    return;
                case 'quit':
                    // send QUIT and drain inbound before exit — see $beginQuit
                    $beginQuit();
                    return;
                default:
                    echo "*** unknown command /{$cmd} — try /help\n";
            }
        };

        // registration, mirroring Irc\Client::go()/sendLogin() minus the
        // bot machinery: CAP only when we actually have SASL credentials,
        // otherwise a plain 001 wait
        if ($sasl !== null) {
            $send('CAP REQ :sasl');
        }
        $send("NICK {$nick}");
        $send("USER {$nick} {$nick} {$nick} :{$nick}");

        // stdin coroutine: chunked reads split on newline into input lines
        \Amp\async(function () use ($handleInput): void {
            $stdin = getStdin();
            $buf = '';
            while (($chunk = $stdin->read()) !== null) {
                $buf .= $chunk;
                while (($nl = strpos($buf, "\n")) !== false) {
                    $line = substr($buf, 0, $nl);
                    $buf = substr($buf, $nl + 1);
                    $handleInput(rtrim($line, "\r"));
                }
            }
            // stdin EOF (Ctrl-D) behaves like /quit
            $handleInput('/quit');
        });

        // socket read loop, Irc\Client::doRead()'s inQ/getLine() idiom
        $inQ = '';
        try {
            while (($chunk = $socket->read()) !== null) {
                $inQ .= $chunk;
                while (($line = client_next_line($inQ)) !== null) {
                    if ($line !== '') {
                        $handleServerLine($line);
                    }
                }
            }
        } catch (StreamException) {
            // an abrupt close (RST) during the drain lands here — treat it
            // like EOF and fall through to the exit below; narrower than
            // \Throwable so real bugs in handleServerLine still surface
        }
        echo "\n*** disconnected\n";
        // the stdin coroutine would otherwise keep the loop alive;
        // a completed drain (post-/quit disconnect) exits cleanly, an
        // unexpected disconnect is still an error exit
        exit($quitting ? 0 : 1);
    });
    EventLoop::run();
    return $code;
}

$argv = $_SERVER['argv'] ?? null;
if (is_string($_SERVER['SCRIPT_FILENAME'] ?? null) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(testenv_client_main(is_array($argv) ? array_values(array_filter($argv, 'is_string')) : []));
}
