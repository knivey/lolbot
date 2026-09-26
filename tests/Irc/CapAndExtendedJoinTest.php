<?php
namespace Tests\Irc;

use Irc\Client;
use Irc\Event\Event;
use Irc\Event\JoinEvent;
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
 * Named distinctly from ClientHarness in AccountTagEventTest.php so both
 * files can be loaded in the same suite run.
 */
final class CapJoinTestHarness extends Client
{
    /** @var array<string, Event> */
    public array $emitted = [];

    public function exposeHandleMessage(MessageEvent $e): void
    {
        $this->handleMessage($e);
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

class CapAndExtendedJoinTest extends TestCase
{
    private CapJoinTestHarness $client;

    protected function setUp(): void
    {
        $this->client = new CapJoinTestHarness('testbot', 'irc.old.example', new Logger('test'), '6667', '0', false);
        // CHANTYPES is required for handleMessage() to route channel lines
        // correctly; real servers announce it via 005 (RPL_ISUPPORT)
        $this->feed(':irc.old.example 005 testbot CHANTYPES=#& :are supported by this server', 'options');
    }

    private function feed(string $raw, ?string $eventKey = null): ?Event
    {
        $message = Message::parse($raw);
        $this->assertNotNull($message, "failed to parse: $raw");
        $this->client->exposeHandleMessage(new MessageEvent(
            time: time(), event: 'message', sender: $this->client, message: $message, raw: $raw
        ));
        if ($eventKey !== null) {
            $this->assertArrayHasKey($eventKey, $this->client->emitted, "no '$eventKey' event emitted for: $raw");
            return $this->client->emitted[$eventKey];
        }
        return null;
    }

    private function countSent(string $line): int
    {
        return count(array_keys($this->client->sendQ, $line, true));
    }

    public function test_cap_ls_advertising_new_caps_gets_requested_and_cap_end_sent_once(): void
    {
        $this->client->isConnected = true;
        $this->feed(':srv CAP * LS :multi-prefix account-tag extended-join');

        $this->assertSame(1, $this->countSent("CAP REQ :multi-prefix\r\n"), 'multi-prefix REQ missing');
        $this->assertSame(1, $this->countSent("CAP REQ :account-tag\r\n"), 'account-tag REQ missing');
        $this->assertSame(1, $this->countSent("CAP REQ :extended-join\r\n"), 'extended-join REQ missing');
        $this->assertSame(1, $this->countSent("CAP END\r\n"), 'CAP END must be sent exactly once');
        $this->assertSame(0, $this->countSent("CAP REQ :sasl\r\n"), 'sasl must not be requested when not configured');
    }

    public function test_cap_ls_without_new_caps_sends_no_new_requests(): void
    {
        $this->client->isConnected = true;
        $this->feed(':srv CAP * LS :multi-prefix');

        $this->assertSame(1, $this->countSent("CAP REQ :multi-prefix\r\n"), 'multi-prefix REQ missing');
        $this->assertSame(0, $this->countSent("CAP REQ :account-tag\r\n"), 'account-tag must not be requested');
        $this->assertSame(0, $this->countSent("CAP REQ :extended-join\r\n"), 'extended-join must not be requested');
        $this->assertSame(1, $this->countSent("CAP END\r\n"), 'CAP END must still be sent exactly once');
    }

    /**
     * @return array<string, array{0: string, 1: string|null, 2: string|null}>
     */
    public static function provideExtendedJoinLines(): array
    {
        return [
            'extended join'            => [':zen!~z@h JOIN #chan zenith :Real Name', 'zenith', 'Real Name'],
            'extended join star'       => [':zen!~z@h JOIN #chan * :Real Name', null, 'Real Name'],
            'old form'                 => [':zen!~z@h JOIN #chan', null, null],
            'tagged old form fallback' => ['@account=zen :zen!~z@h JOIN #chan', 'zen', null],
            'params take precedence'   => ['@account=tagged :zen!~z@h JOIN #chan zenith :Real Name', 'zenith', 'Real Name'],
        ];
    }

    /**
     * @dataProvider provideExtendedJoinLines
     */
    #[DataProvider('provideExtendedJoinLines')]
    public function test_extended_join_populates_account_and_realname(
        string $raw,
        ?string $expectedAccount,
        ?string $expectedRealname,
    ): void {
        $event = $this->feed($raw, 'join');
        $this->assertInstanceOf(JoinEvent::class, $event);
        $this->assertSame('#chan', $event->chan);
        $this->assertSame($expectedAccount, $event->account, "account mismatch for: $raw");
        $this->assertSame($expectedRealname, $event->realname, "realname mismatch for: $raw");
    }
}
