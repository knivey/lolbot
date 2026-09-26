<?php
namespace Tests\Irc;

use Amp\TimeoutCancellation;
use Irc\Client;
use Irc\Event\Event;
use Irc\Event\MessageEvent;
use Irc\Message;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../library/Irc/Consts.php';

/**
 * Captures emitted events instead of dispatching them to subscribers, so
 * handleMessage() can be driven directly with crafted raw IRC lines.
 * Named distinctly from ClientHarness / CapJoinTestHarness so all harness
 * files can be loaded in the same suite run.
 */
final class WhoxTestHarness extends Client
{
    /** @var array<string, Event> */
    public array $emitted = [];

    public function exposeHandleMessage(MessageEvent $e): void
    {
        $this->handleMessage($e);
    }

    public function exposeOnDisconnect(): void
    {
        $this->onDisconnect();
    }

    public function emit(string $event, ?Event $args = null): static
    {
        // mirror EventEmitter::emit() semantics minus callback dispatch:
        // canonical first name from a possible comma-separated event list
        $name = trim(explode(',', $event)[0]);
        if ($args === null) {
            $args = new class(time(), $name, $this) extends Event {};
        }
        $args->event = $name;
        $this->emitted[$name] = $args;
        return $this;
    }
}

class WhoxTest extends TestCase
{
    private WhoxTestHarness $client;

    protected function setUp(): void
    {
        $this->client = new WhoxTestHarness('testbot', 'irc.old.example', new Logger('test'), '6667', '0', false);
        $this->client->isConnected = true;
    }

    private function feed(string $raw): void
    {
        $message = Message::parse($raw);
        $this->assertNotNull($message, "failed to parse: $raw");
        $this->client->exposeHandleMessage(new MessageEvent(
            time: time(), event: 'message', sender: $this->client, message: $message, raw: $raw
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

    private function labelAt(int $index, string $fields = 'uhnaf'): string
    {
        $lines = $this->sentWhoLines();
        $this->assertArrayHasKey($index, $lines, "expected WHO line #$index in sendQ");
        $pattern = '/^WHO \S+ %' . $fields . ',([0-9a-f]{8})\r\n$/';
        $this->assertSame(1, preg_match($pattern, $lines[$index], $m), 'malformed WHO line: ' . $lines[$index]);
        return $m[1];
    }

    public function test_whox_sends_who_with_fields_and_unique_labels(): void
    {
        $this->client->whox('#chan');
        $this->client->whox('#chan');

        $lines = $this->sentWhoLines();
        $this->assertCount(2, $lines, 'two WHO lines expected in sendQ');
        $first = $this->labelAt(0);
        $second = $this->labelAt(1);
        $this->assertNotSame($first, $second, 'labels must be unique per call');
    }

    public function test_whox_resolves_entries_on_endofwho_with_star_account_null(): void
    {
        $future = $this->client->whox('#chan');
        $label = $this->labelAt(0);

        $this->feed(":srv 354 testbot $label identX hostX nickX someacct H@");
        $this->feed(":srv 354 testbot $label identY hostY nickY * H@");
        $this->assertFalse($future->isComplete(), 'future must stay pending until 315 arrives');

        $this->feed(':srv 315 testbot #chan :End of WHO list');

        $this->assertTrue($future->isComplete(), 'future must resolve on 315');
        // key order follows the requested field string (u,h,n,a,f)
        $this->assertSame([
            ['u' => 'identX', 'h' => 'hostX', 'n' => 'nickX', 'a' => 'someacct', 'f' => 'H@'],
            ['u' => 'identY', 'h' => 'hostY', 'n' => 'nickY', 'a' => null, 'f' => 'H@'],
        ], $future->await(new TimeoutCancellation(2)));
        // existing default-case behavior for 315 is preserved
        $this->assertArrayHasKey('315', $this->client->emitted, '315 NumericEvent must still be emitted');
    }

    public function test_whox_field_mapping_is_positional_for_requested_fields(): void
    {
        $future = $this->client->whox('#chan', 'nu');
        $label = $this->labelAt(0, 'nu');

        $this->feed(":srv 354 testbot $label nickZ userZ");
        $this->feed(':srv 315 testbot #chan :End of WHO list');

        $this->assertSame([['n' => 'nickZ', 'u' => 'userZ']], $future->await(new TimeoutCancellation(2)));
    }

    public function test_whox_passthrough_letters_keep_their_key(): void
    {
        $future = $this->client->whox('#chan', 'nr');
        $label = $this->labelAt(0, 'nr');

        $this->feed(":srv 354 testbot $label nickR :Real Name Here");
        $this->feed(':srv 315 testbot #chan :End of WHO list');

        $this->assertSame([['n' => 'nickR', 'r' => 'Real Name Here']], $future->await(new TimeoutCancellation(2)));
    }

    public function test_concurrent_whox_calls_correlate_by_label_and_unknown_label_ignored(): void
    {
        $one = $this->client->whox('#chan');
        $two = $this->client->whox('#other');
        $labelOne = $this->labelAt(0);
        $labelTwo = $this->labelAt(1);

        // interleaved replies, plus a foreign 354 from another client's WHOX
        $this->feed(":srv 354 testbot $labelOne ident1 host1 nick1 acct1 H@");
        $this->feed(":srv 354 testbot $labelTwo ident2 host2 nick2 * H@");
        $this->feed(":srv 354 testbot deadbeef foreign host foreign nick x H@");
        $this->feed(":srv 354 testbot $labelOne ident3 host3 nick3 acct3 H@");

        $this->feed(':srv 315 testbot #chan :End of WHO list');
        $this->assertTrue($one->isComplete(), 'first future resolved by its own 315');
        $this->assertFalse($two->isComplete(), 'second future still pending');

        $this->assertSame([
            ['u' => 'ident1', 'h' => 'host1', 'n' => 'nick1', 'a' => 'acct1', 'f' => 'H@'],
            ['u' => 'ident3', 'h' => 'host3', 'n' => 'nick3', 'a' => 'acct3', 'f' => 'H@'],
        ], $one->await(new TimeoutCancellation(2)));

        $this->feed(':srv 315 testbot #other :End of WHO list');
        $this->assertTrue($two->isComplete());
        $this->assertSame([
            ['u' => 'ident2', 'h' => 'host2', 'n' => 'nick2', 'a' => null, 'f' => 'H@'],
        ], $two->await(new TimeoutCancellation(2)));
    }

    public function test_foreign_label_354_still_emits_numeric_event(): void
    {
        // e.g. Nicks.php runs its own legacy WHOX on joins (`%tnchuf,777`)
        // and subscribes to the plain '354' numeric event to collect replies
        $this->feed(':srv 354 testbot 777 #chan H@ userX hostX nickX acctX');

        $this->assertArrayHasKey('354', $this->client->emitted, 'foreign-label 354 must still emit the numeric event');
        $event = $this->client->emitted['354'] ?? null; // @phpstan-ignore nullCoalesce.offset
        $this->assertInstanceOf(\Irc\Event\NumericEvent::class, $event);
        $this->assertSame('354', $event->message->command);
        $this->assertSame('777', $event->message->getArg(1));
    }

    public function test_own_label_354_does_not_emit_numeric_event(): void
    {
        $future = $this->client->whox('#chan');
        $label = $this->labelAt(0);

        $this->feed(":srv 354 testbot $label identX hostX nickX acctX H@");

        $this->assertArrayNotHasKey('354', $this->client->emitted, 'own-label 354s are consumed by the future, not re-emitted');

        $this->feed(':srv 315 testbot #chan :End of WHO list');
        $this->assertTrue($future->isComplete(), 'future still resolves normally');
        $this->assertSame([
            ['u' => 'identX', 'h' => 'hostX', 'n' => 'nickX', 'a' => 'acctX', 'f' => 'H@'],
        ], $future->await(new \Amp\TimeoutCancellation(2)));
    }

    public function test_315_for_untracked_target_is_ignored_without_error(): void
    {
        $future = $this->client->whox('#chan');
        $label = $this->labelAt(0);

        $this->feed(':srv 315 testbot #untracked :End of WHO list');

        $this->assertFalse($future->isComplete(), 'untracked 315 must not resolve anything');
        $this->assertArrayHasKey('315', $this->client->emitted, 'numeric event still emitted');

        // the tracked call still resolves afterwards
        $this->feed(":srv 354 testbot $label identX hostX nickX * H@");
        $this->feed(':srv 315 testbot #chan :End of WHO list');
        $this->assertTrue($future->isComplete());
    }

    public function test_timeout_resolves_with_partial_entries(): void
    {
        $timeout = new \ReflectionProperty(Client::class, 'whoxTimeout');
        $timeout->setValue($this->client, 0);

        $future = $this->client->whox('#chan');
        $label = $this->labelAt(0);

        $this->feed(":srv 354 testbot $label identX hostX nickX acctX H@");

        $this->assertSame([
            ['u' => 'identX', 'h' => 'hostX', 'n' => 'nickX', 'a' => 'acctX', 'f' => 'H@'],
        ], $future->await(new TimeoutCancellation(2)), 'timeout must resolve with entries so far');
    }

    public function test_timeout_resolves_empty_when_no_354_arrives(): void
    {
        $timeout = new \ReflectionProperty(Client::class, 'whoxTimeout');
        $timeout->setValue($this->client, 0);

        $future = $this->client->whox('#chan');

        $this->assertSame([], $future->await(new TimeoutCancellation(2)), 'timeout with no replies resolves empty');
    }

    public function test_on_disconnect_resolves_pending(): void
    {
        $empty = $this->client->whox('#chan');
        $partial = $this->client->whox('#other');
        $partialLabel = $this->labelAt(1);

        $this->feed(":srv 354 testbot $partialLabel identX hostX nickX * H@");
        $this->client->exposeOnDisconnect();

        $this->assertTrue($empty->isComplete(), 'disconnect must resolve pending futures');
        $this->assertSame([], $empty->await(new TimeoutCancellation(2)), 'no replies received → empty list');
        $this->assertTrue($partial->isComplete());
        $this->assertSame([
            ['u' => 'identX', 'h' => 'hostX', 'n' => 'nickX', 'a' => null, 'f' => 'H@'],
        ], $partial->await(new TimeoutCancellation(2)), 'entries received before disconnect are kept');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideInvalidArguments(): array
    {
        return [
            'empty target'      => ['', 'uhnaf'],
            'target with space' => ['#ch an', 'uhnaf'],
            'empty fields'      => ['#chan', ''],
            'uppercase fields'  => ['#chan', 'UHNAF'],
            'digit in fields'   => ['#chan', 'uh1af'],
            'space in fields'   => ['#chan', 'u n'],
        ];
    }

    /**
     * @dataProvider provideInvalidArguments
     */
    #[DataProvider('provideInvalidArguments')]
    public function test_invalid_arguments_throw(string $target, string $fields): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client->whox($target, $fields);
    }
}
