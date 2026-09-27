<?php

namespace Tests\Irc;

use Amp\DeferredFuture;
use Irc\Client;
use Irc\Event\JoinEvent;
use Irc\Event\MessageEvent;
use Irc\Event\NamesEvent;
use Irc\Event\NamesReply;
use Irc\Message;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../library/Irc/Consts.php';
require_once __DIR__ . '/../../library/Nicks.php';

/**
 * Real Client (real EventEmitter dispatch + real handleMessage via the
 * 'message' subscription) whose whox() is faked to return a controllable
 * DeferredFuture, so Nicks' join-time WHOX backfill can be driven without
 * a socket. Named distinctly from the other harnesses so all harness
 * files can load in the same suite run.
 */
final class NicksWhoxHarness extends Client
{
    /** @var list<array{0: string, 1: string}> target+fields of every whox() call */
    public array $whoxCalls = [];
    /** Deferred backing the most recent faked whox() future
     * @var ?DeferredFuture<list<array<string, mixed>>>
     */
    public ?DeferredFuture $whoxDeferred = null;

    public function setOptionForTest(string $key, mixed $value): void
    {
        $this->options[strtoupper($key)] = $value;
    }

    public function whox(string $target, string $fields = 'uhnaf'): \Amp\Future
    {
        $this->whoxCalls[] = [$target, $fields];
        $this->whoxDeferred = new DeferredFuture();
        return $this->whoxDeferred->getFuture();
    }
}

class NicksWhoxTest extends TestCase
{
    private NicksWhoxHarness $client;
    private \Nicks $nicks;

    protected function setUp(): void
    {
        $this->client = new NicksWhoxHarness('testbot', 'irc.old.example', new Logger('test'), '6667', '0', false);
        $this->client->isConnected = true;
        $this->nicks = new \Nicks($this->client);
    }

    /**
     * Feed a raw IRC line through the real handleMessage (the Client
     * constructor wires handleMessage to the 'message' event).
     */
    private function feed(string $raw): void
    {
        $message = Message::parse($raw);
        $this->assertNotNull($message, "failed to parse: $raw");
        $this->client->emit('message', new MessageEvent(
            time: time(), event: 'message', sender: $this->client, message: $message, raw: $raw
        ));
    }

    private function emitOurJoin(string $chan = '#chan'): void
    {
        $this->client->emit('join', new JoinEvent(
            time: time(), event: 'join', sender: $this->client,
            nick: 'testbot', ident: 'bot', host: 'bot.host',
            identhost: 'bot@bot.host', fullhost: 'testbot!bot@bot.host', chan: $chan
        ));
    }

    /**
     * Populate ppl the way the wire does on our join: NAMES reply first.
     * @param list<string> $names
     */
    private function feedNames(string $chan = '#chan', array $names = []): void
    {
        $reply = new NamesReply();
        $reply->nick = 'testbot';
        $reply->channelType = '=';
        $reply->chan = $chan;
        $reply->names = $names;
        $this->client->emit('names', new NamesEvent(
            time: time(), event: 'names', sender: $this->client, chan: $chan, names: $reply
        ));
    }

    /**
     * @return list<string> raw "WHO ..." lines pushed to the send queue
     */
    private function sentWhoLines(): array
    {
        return array_values(array_filter(
            $this->client->sendQ,
            fn(string $line): bool => str_starts_with($line, 'WHO ')
        ));
    }

    /**
     * Advance the event loop just enough to run already-queued fibers (the
     * whox consumer), then stop: earlier tests in this process leak live
     * repeat timers (e.g. seen.php's 15s saveSeens flush against a closed
     * EntityManager) that a bare EventLoop::run() would wait for and detonate.
     * The stop-defer runs in the first callback batch, before dispatch(),
     * so due timers from other tests never fire.
     */
    private function advanceLoop(): void
    {
        \Revolt\EventLoop::defer(static function (): void {
            \Revolt\EventLoop::getDriver()->stop();
        });
        \Revolt\EventLoop::run();
    }

    public function test_whox_join_backfills_host_and_modes(): void
    {
        $this->client->setOptionForTest('WHOX', true);
        $this->feedNames(names: ['@OpNick', '+VoNick', 'PlainNick', 'testbot']);

        $this->emitOurJoin();

        // WHOX path must go through Client::whox with the old field set
        // (t=token, c=channel, u=ident, h=host, n=nick, f=flags) instead
        // of a raw `WHO #chan %tnchuf,777` send
        $this->assertSame([['#chan', 'tcuhnf']], $this->client->whoxCalls);
        $this->assertSame([], $this->sentWhoLines(), 'no raw legacy WHO line may be sent on the WHOX path');

        // our own ppl entry exists immediately (join handler ran to completion)
        $this->assertNotNull($this->nicks->n2h('testbot'));

        $this->client->whoxDeferred?->complete([
            ['t' => '42', 'c' => '#chan', 'u' => 'opident', 'h' => 'op.host', 'n' => 'OpNick', 'f' => 'H@'],
            ['t' => '42', 'c' => '#chan', 'u' => 'voident', 'h' => 'vo.host', 'n' => 'VoNick', 'f' => 'H'],
            ['t' => '42', 'c' => '#chan', 'u' => 'plainident', 'h' => 'plain.host', 'n' => 'PlainNick', 'f' => 'H+'],
            ['t' => '42', 'c' => '#chan', 'u' => 'bot', 'h' => 'bot.host', 'n' => 'testbot', 'f' => 'H'],
        ]);
        $this->advanceLoop();

        $this->assertSame('opident@op.host', $this->nicks->n2h('OpNick'));
        $this->assertSame('voident@vo.host', $this->nicks->n2h('VoNick'));
        $this->assertSame('plainident@plain.host', $this->nicks->n2h('PlainNick'));
        // host is ident@host exactly like the old 354 handler built it
        $this->assertSame('bot@bot.host', $this->nicks->n2h('testbot'));

        // modes come from the flags string; the WHOX consumer REPLACES the
        // modes array (old whox() behavior), so VoNick's names-reply '+' is
        // dropped by its 'H' (no prefix) flags
        $this->assertTrue($this->nicks->isOp('OpNick', '#chan'));
        $this->assertFalse($this->nicks->isVoice('VoNick', '#chan'), 'flagless entry must replace, not merge, names-reply modes');
        $this->assertTrue($this->nicks->isVoice('PlainNick', '#chan'));
    }

    public function test_non_whox_join_sends_plain_who_and_352_still_populates(): void
    {
        $this->feed(':srv 376 testbot :End of MOTD command.');
        $this->feedNames(names: ['@OpNick', 'PlainNick', 'testbot']);

        $this->emitOurJoin();

        $who = $this->sentWhoLines();
        $this->assertSame(["WHO #chan\r\n"], $who, 'non-WHOX join must send a plain WHO');

        //              0        1         2          3      4        5      6       7
        //:server 352 <client> <channel> <username> <host> <server> <nick> <flags> :<hopcount> <realname>
        $this->feed(':srv 352 testbot #chan opident op.host srv OpNick H@ :0 op');
        $this->feed(':srv 352 testbot #chan plainident plain.host srv PlainNick H :0 plain');
        $this->feed(':srv 315 testbot #chan :End of WHO list');

        $this->assertSame('opident@op.host', $this->nicks->n2h('OpNick'));
        $this->assertSame('plainident@plain.host', $this->nicks->n2h('PlainNick'));
        $this->assertTrue($this->nicks->isOp('OpNick', '#chan'));
        $this->assertFalse($this->nicks->isOp('PlainNick', '#chan'));
    }

    public function test_whox_entries_for_unknown_nick_or_channel_ignored(): void
    {
        $this->client->setOptionForTest('WHOX', true);
        $this->feedNames(names: ['PlainNick', 'testbot']);
        $this->emitOurJoin();

        $this->client->whoxDeferred?->complete([
            ['t' => '42', 'c' => '#chan', 'u' => 'ghostident', 'h' => 'ghost.host', 'n' => 'GhostNick', 'f' => 'H@'],
            ['t' => '42', 'c' => '#elsewhere', 'u' => 'plainident', 'h' => 'plain.host', 'n' => 'PlainNick', 'f' => 'H@'],
        ]);
        $this->advanceLoop();

        // unknown nick: not invented; unknown channel: PlainNick untouched
        $this->assertNull($this->nicks->n2h('GhostNick'));
        $this->assertNull($this->nicks->n2h('PlainNick'));
        $this->assertArrayNotHasKey('#elsewhere', $this->nicks->nickChans('PlainNick'));
        $this->assertSame(['#chan'], array_keys($this->nicks->nickChans('PlainNick')));
    }

    public function test_join_handler_does_not_block_on_whox_future(): void
    {
        $this->client->setOptionForTest('WHOX', true);
        $this->feedNames(names: ['OpNick', 'testbot']);

        // future left PENDING: if the join handler awaited it inline, this
        // emit would never return (the read fiber that must deliver the
        // replies is the one blocked inside the handler)
        $this->emitOurJoin();

        $this->assertSame([['#chan', 'tcuhnf']], $this->client->whoxCalls, 'whox() was issued');
        $this->assertNotNull($this->nicks->n2h('testbot'), 'join handling continued past the whox call');
        $this->assertNull($this->nicks->n2h('OpNick'), 'future consumer must not have run yet');

        $this->client->whoxDeferred?->complete([
            ['t' => '42', 'c' => '#chan', 'u' => 'opident', 'h' => 'op.host', 'n' => 'OpNick', 'f' => 'H@'],
        ]);
        $this->advanceLoop();

        $this->assertSame('opident@op.host', $this->nicks->n2h('OpNick'));
    }

    public function test_legacy_354_replies_no_longer_populate(): void
    {
        $this->client->setOptionForTest('WHOX', true);
        $this->feed(':srv 376 testbot :End of MOTD command.');
        $this->feedNames(names: ['PlainNick', 'testbot']);

        // a foreign 354 (e.g. another client's `%tnchuf,777` WHOX) must no
        // longer feed Nicks: replies only arrive through Client::whox
        // futures correlated by our own token
        $this->feed(':srv 354 testbot 777 #chan ident host PlainNick H@');

        $this->assertNull($this->nicks->n2h('PlainNick'), 'foreign 354 must not populate ppl');
        $this->assertFalse($this->nicks->isOp('PlainNick', '#chan'));
    }
}
