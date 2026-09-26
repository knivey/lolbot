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

    /**
     * Outgoing WHOX field string for a caller field set: the canonical
     * WHOX order tcuihsnfdlaor filtered to the requested letters, with
     * the internal correlation token 't' always forced in.
     */
    private function outgoingFields(string $callerFields): string
    {
        $set = array_flip(str_split($callerFields));
        $set['t'] = true;
        return implode('', array_filter(
            str_split('tcuihsnfdlaor'),
            fn(string $l): bool => isset($set[$l])
        ));
    }

    /**
     * Extracts and validates the WHOX token from sent WHO line #$index:
     * digits only, 1-3 chars, never Nicks.php's legacy 777.
     */
    private function tokenAt(int $index, string $callerFields = 'uhnaf'): string
    {
        $lines = $this->sentWhoLines();
        $this->assertArrayHasKey($index, $lines, "expected WHO line #$index in sendQ");
        $pattern = '/^WHO \S+ %' . $this->outgoingFields($callerFields) . ',(\d{1,3})\r\n$/';
        $this->assertSame(1, preg_match($pattern, $lines[$index], $m), 'malformed WHO line: ' . $lines[$index]);
        $this->assertNotSame('777', $m[1], 'token must never be Nicks.php legacy 777');
        return $m[1];
    }

    /**
     * A 1-3 digit token guaranteed different from every reserved one (and 777).
     * @param string ...$reserved
     */
    private function foreignToken(string ...$reserved): string
    {
        do {
            $token = strval(random_int(1, 999));
        } while (in_array($token, [...$reserved, '777'], true));
        return $token;
    }

    public function test_whox_sends_who_with_fields_and_unique_tokens(): void
    {
        $this->client->whox('#chan');
        $this->client->whox('#chan');

        $lines = $this->sentWhoLines();
        $this->assertCount(2, $lines, 'two WHO lines expected in sendQ');
        $first = $this->tokenAt(0);
        $second = $this->tokenAt(1);
        $this->assertNotSame($first, $second, 'tokens must be unique per call');
    }

    public function test_many_concurrent_calls_get_distinct_tokens_and_resolve_together(): void
    {
        $futures = [];
        $tokens = [];
        for ($i = 0; $i < 25; $i++) {
            $futures[] = $this->client->whox('#chan');
            $tokens[] = $this->tokenAt($i);
        }
        $this->assertCount(25, array_unique($tokens), 'every pending call must hold a distinct token');

        $this->feed(":srv 354 testbot {$tokens[0]} ident0 host0 nick0 H@ 0");
        $this->feed(':srv 315 testbot #chan :End of WHO list');

        foreach ($futures as $i => $future) {
            $this->assertTrue($future->isComplete(), "future #$i resolved by the shared 315");
        }
        $this->assertSame([
            ['u' => 'ident0', 'h' => 'host0', 'n' => 'nick0', 'f' => 'H@', 'a' => null],
        ], $futures[0]->await(new TimeoutCancellation(2)));
    }

    public function test_default_fields_resolve_with_canonical_mapping_and_account_sentinels(): void
    {
        $future = $this->client->whox('#chan');
        $token = $this->tokenAt(0);

        // reply values follow the CANONICAL order t,u,h,n,f,a — flags
        // arrive before the account even though 'uhnaf' requests a before f
        $this->feed(":srv 354 testbot $token identX hostX nickX H@ someacct");
        $this->feed(":srv 354 testbot $token identY hostY nickY H@ 0");
        $this->feed(":srv 354 testbot $token identZ hostZ nickZ H@ *");
        $this->assertFalse($future->isComplete(), 'future must stay pending until 315 arrives');

        $this->feed(':srv 315 testbot #chan :End of WHO list');

        $this->assertTrue($future->isComplete(), 'future must resolve on 315');
        // entry keys follow canonical reply order minus the internal token: u,h,n,f,a
        $this->assertSame([
            ['u' => 'identX', 'h' => 'hostX', 'n' => 'nickX', 'f' => 'H@', 'a' => 'someacct'],
            // WHOX signals "logged out" with 0 (spec); * mapped defensively too
            ['u' => 'identY', 'h' => 'hostY', 'n' => 'nickY', 'f' => 'H@', 'a' => null],
            ['u' => 'identZ', 'h' => 'hostZ', 'n' => 'nickZ', 'f' => 'H@', 'a' => null],
        ], $future->await(new TimeoutCancellation(2)));
        // existing default-case behavior for 315 is preserved
        $this->assertArrayHasKey('315', $this->client->emitted, '315 NumericEvent must still be emitted');
    }

    public function test_custom_field_set_maps_in_canonical_reply_order(): void
    {
        // 'na' requests nick+account; the wire carries %tna and replies
        // arrive as <token> <nick> <account>
        $future = $this->client->whox('#chan', 'na');
        $token = $this->tokenAt(0, 'na');

        $this->feed(":srv 354 testbot $token nickA acctA");
        $this->feed(':srv 315 testbot #chan :End of WHO list');

        $this->assertSame([['n' => 'nickA', 'a' => 'acctA']], $future->await(new TimeoutCancellation(2)));
    }

    public function test_reply_mapping_follows_canonical_order_not_request_order(): void
    {
        // caller order 'a,u' is reversed on the wire: outgoing is %tua and
        // replies arrive as <token> <ident> <account>
        $future = $this->client->whox('#chan', 'au');
        $token = $this->tokenAt(0, 'au');

        $this->feed(":srv 354 testbot $token identA acctA");
        $this->feed(':srv 315 testbot #chan :End of WHO list');

        $this->assertSame([['u' => 'identA', 'a' => 'acctA']], $future->await(new TimeoutCancellation(2)));
    }

    public function test_whox_passthrough_letters_keep_their_key(): void
    {
        $future = $this->client->whox('#chan', 'nr');
        $token = $this->tokenAt(0, 'nr');

        // t,n,r order: realname rides as the trailing colon parameter
        $this->feed(":srv 354 testbot $token nickR :Real Name Here");
        $this->feed(':srv 315 testbot #chan :End of WHO list');

        $this->assertSame([['n' => 'nickR', 'r' => 'Real Name Here']], $future->await(new TimeoutCancellation(2)));
    }

    public function test_duplicate_field_letters_collapse(): void
    {
        $future = $this->client->whox('#chan', 'uuh');
        $token = $this->tokenAt(0, 'uuh'); // dedupes to %tuh on the wire

        $this->feed(":srv 354 testbot $token identD hostD");
        $this->feed(':srv 315 testbot #chan :End of WHO list');

        $this->assertSame([['u' => 'identD', 'h' => 'hostD']], $future->await(new TimeoutCancellation(2)));
    }

    public function test_explicitly_requested_token_is_kept_in_entries(): void
    {
        $future = $this->client->whox('#chan', 'nt');
        $token = $this->tokenAt(0, 'nt');

        $this->feed(":srv 354 testbot $token nickT");
        $this->feed(':srv 315 testbot #chan :End of WHO list');

        $this->assertSame([['t' => $token, 'n' => 'nickT']], $future->await(new TimeoutCancellation(2)));
    }

    public function test_concurrent_whox_calls_correlate_by_token_and_foreign_tokens_ignored(): void
    {
        $one = $this->client->whox('#chan');
        $two = $this->client->whox('#other');
        $tokenOne = $this->tokenAt(0);
        $tokenTwo = $this->tokenAt(1);

        // interleaved replies, plus a foreign 354 from another client's WHOX
        $foreign = $this->foreignToken($tokenOne, $tokenTwo);
        $this->feed(":srv 354 testbot $tokenOne ident1 host1 nick1 H@ acct1");
        $this->feed(":srv 354 testbot $tokenTwo ident2 host2 nick2 H@ 0");
        $this->feed(":srv 354 testbot $foreign #chan userF hostF nickF H@");
        $this->feed(":srv 354 testbot $tokenOne ident3 host3 nick3 H@ acct3");

        $this->feed(':srv 315 testbot #chan :End of WHO list');
        $this->assertTrue($one->isComplete(), 'first future resolved by its own 315');
        $this->assertFalse($two->isComplete(), 'second future still pending');

        $this->assertSame([
            ['u' => 'ident1', 'h' => 'host1', 'n' => 'nick1', 'f' => 'H@', 'a' => 'acct1'],
            ['u' => 'ident3', 'h' => 'host3', 'n' => 'nick3', 'f' => 'H@', 'a' => 'acct3'],
        ], $one->await(new TimeoutCancellation(2)));

        $this->feed(':srv 315 testbot #other :End of WHO list');
        $this->assertTrue($two->isComplete());
        $this->assertSame([
            ['u' => 'ident2', 'h' => 'host2', 'n' => 'nick2', 'f' => 'H@', 'a' => null],
        ], $two->await(new TimeoutCancellation(2)));
    }

    public function test_token_mismatch_emits_numeric_and_leaves_future_unaffected(): void
    {
        $future = $this->client->whox('#chan');
        $token = $this->tokenAt(0);
        $foreign = $this->foreignToken($token);

        // another client's WHOX reply carrying a token we did not generate
        $this->feed(":srv 354 testbot $foreign #chan userF hostF nickF H@");

        $this->assertArrayHasKey('354', $this->client->emitted, 'mismatched-token 354 must take the foreign branch and emit the numeric');
        $event = $this->client->emitted['354'] ?? null; // @phpstan-ignore nullCoalesce.offset
        $this->assertInstanceOf(\Irc\Event\NumericEvent::class, $event);
        $this->assertSame($foreign, $event->message->getArg(1));
        $this->assertFalse($future->isComplete(), 'foreign reply must not resolve or feed our future');

        // our own replies still resolve normally afterwards
        $this->feed(":srv 354 testbot $token identX hostX nickX H@ acctX");
        $this->feed(':srv 315 testbot #chan :End of WHO list');
        $this->assertSame([
            ['u' => 'identX', 'h' => 'hostX', 'n' => 'nickX', 'f' => 'H@', 'a' => 'acctX'],
        ], $future->await(new TimeoutCancellation(2)));
    }

    public function test_foreign_token_777_still_emits_numeric_event(): void
    {
        // e.g. Nicks.php runs its own legacy WHOX on joins (`%tnchuf,777`)
        // and subscribes to the plain '354' numeric event to collect replies
        // (canonical reply order t,c,u,h,n,f)
        $this->feed(':srv 354 testbot 777 #chan userX hostX nickX H@');

        $this->assertArrayHasKey('354', $this->client->emitted, 'foreign-token 354 must still emit the numeric event');
        $event = $this->client->emitted['354'] ?? null; // @phpstan-ignore nullCoalesce.offset
        $this->assertInstanceOf(\Irc\Event\NumericEvent::class, $event);
        $this->assertSame('354', $event->message->command);
        $this->assertSame('777', $event->message->getArg(1));
    }

    public function test_own_token_354_does_not_emit_numeric_event(): void
    {
        $future = $this->client->whox('#chan');
        $token = $this->tokenAt(0);

        $this->feed(":srv 354 testbot $token identX hostX nickX H@ acctX");

        $this->assertArrayNotHasKey('354', $this->client->emitted, 'own-token 354s are consumed by the future, not re-emitted');

        $this->feed(':srv 315 testbot #chan :End of WHO list');
        $this->assertTrue($future->isComplete(), 'future still resolves normally');
        $this->assertSame([
            ['u' => 'identX', 'h' => 'hostX', 'n' => 'nickX', 'f' => 'H@', 'a' => 'acctX'],
        ], $future->await(new TimeoutCancellation(2)));
    }

    public function test_315_for_untracked_target_is_ignored_without_error(): void
    {
        $future = $this->client->whox('#chan');
        $token = $this->tokenAt(0);

        $this->feed(':srv 315 testbot #untracked :End of WHO list');

        $this->assertFalse($future->isComplete(), 'untracked 315 must not resolve anything');
        $this->assertArrayHasKey('315', $this->client->emitted, 'numeric event still emitted');

        // the tracked call still resolves afterwards
        $this->feed(":srv 354 testbot $token identX hostX nickX H@ 0");
        $this->feed(':srv 315 testbot #chan :End of WHO list');
        $this->assertTrue($future->isComplete());
    }

    public function test_duplicate_315_does_not_double_complete(): void
    {
        $future = $this->client->whox('#chan');
        $token = $this->tokenAt(0);

        $this->feed(":srv 354 testbot $token identX hostX nickX H@ acctX");
        $this->feed(':srv 315 testbot #chan :End of WHO list');
        $this->assertTrue($future->isComplete());

        // a stray second 315 for the same target must be a no-op on futures
        $this->feed(':srv 315 testbot #chan :End of WHO list');
        $resolved = $future->await(new TimeoutCancellation(2));
        $this->assertSame([
            ['u' => 'identX', 'h' => 'hostX', 'n' => 'nickX', 'f' => 'H@', 'a' => 'acctX'],
        ], $resolved);
        $this->assertSame($resolved, $future->await(new TimeoutCancellation(2)), 'already-resolved future stays stable');
    }

    public function test_timeout_resolves_with_partial_entries(): void
    {
        $timeout = new \ReflectionProperty(Client::class, 'whoxTimeout');
        $timeout->setValue($this->client, 0);

        $future = $this->client->whox('#chan');
        $token = $this->tokenAt(0);

        $this->feed(":srv 354 testbot $token identX hostX nickX H@ acctX");

        $this->assertSame([
            ['u' => 'identX', 'h' => 'hostX', 'n' => 'nickX', 'f' => 'H@', 'a' => 'acctX'],
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
        $partialToken = $this->tokenAt(1);

        $this->feed(":srv 354 testbot $partialToken identX hostX nickX H@ 0");
        $this->client->exposeOnDisconnect();

        $this->assertTrue($empty->isComplete(), 'disconnect must resolve pending futures');
        $this->assertSame([], $empty->await(new TimeoutCancellation(2)), 'no replies received → empty list');
        $this->assertTrue($partial->isComplete());
        $this->assertSame([
            ['u' => 'identX', 'h' => 'hostX', 'n' => 'nickX', 'f' => 'H@', 'a' => null],
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
            'non-whox letter'   => ['#chan', 'uhz'],
            'unknown letter'    => ['#chan', 'x'],
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
