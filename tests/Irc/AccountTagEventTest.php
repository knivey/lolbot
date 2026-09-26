<?php
namespace Tests\Irc;

use Irc\Client;
use Irc\Event\ChatEvent;
use Irc\Event\Event;
use Irc\Event\KickEvent;
use Irc\Event\MessageEvent;
use Irc\Event\NickEvent;
use Irc\Event\NoticeEvent;
use Irc\Event\PartEvent;
use Irc\Event\PmEvent;
use Irc\Event\QuitEvent;
use Irc\Event\UserEvent;
use Irc\Message;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../library/Irc/Consts.php';

/**
 * Captures emitted events instead of dispatching them to subscribers, so
 * handleMessage() can be driven directly with crafted raw IRC lines.
 */
final class ClientHarness extends Client
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

class AccountTagEventTest extends TestCase
{
    private ClientHarness $client;

    protected function setUp(): void
    {
        $this->client = new ClientHarness('testbot', 'irc.old.example', new Logger('test'), '6667', '0', false);
        // CHANTYPES is required for handleMessage() to route PRIVMSG #chan to
        // the ChatEvent branch instead of PmEvent; real servers announce it
        // via 005 (RPL_ISUPPORT)
        $this->feed(':irc.old.example 005 testbot CHANTYPES=#& :are supported by this server', 'options');
    }

    private function feed(string $raw, string $eventKey): Event
    {
        $message = Message::parse($raw);
        $this->assertNotNull($message, "failed to parse: $raw");
        $this->client->exposeHandleMessage(new MessageEvent(
            time: time(), event: 'message', sender: $this->client, message: $message, raw: $raw
        ));
        $this->assertArrayHasKey($eventKey, $this->client->emitted, "no '$eventKey' event emitted for: $raw");
        return $this->client->emitted[$eventKey];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: class-string, 3: string|null}>
     */
    public static function provideUserEventLines(): array
    {
        return [
            'chat tagged account'    => ['@account=zen :nick!user@host PRIVMSG #chan :hi', 'chat', ChatEvent::class, 'zen'],
            'chat without tags'      => [':nick!user@host PRIVMSG #chan :hi', 'chat', ChatEvent::class, null],
            'chat star account'      => ['@account=* :nick!user@host PRIVMSG #chan :hi', 'chat', ChatEvent::class, null],
            'pm tagged account'      => ['@account=zen :nick!user@host PRIVMSG testbot :hi', 'pm', PmEvent::class, 'zen'],
            'pm without tags'        => [':nick!user@host PRIVMSG testbot :hi', 'pm', PmEvent::class, null],
            'pm star account'        => ['@account=* :nick!user@host PRIVMSG testbot :hi', 'pm', PmEvent::class, null],
            'notice tagged account'  => ['@account=zen :nick!user@host NOTICE #chan :hi', 'notice', NoticeEvent::class, 'zen'],
            'notice without tags'    => [':nick!user@host NOTICE #chan :hi', 'notice', NoticeEvent::class, null],
            'notice star account'    => ['@account=* :nick!user@host NOTICE #chan :hi', 'notice', NoticeEvent::class, null],
            'nick tagged account'    => ['@account=zen :oldnick!user@host NICK :newnick', 'nick', NickEvent::class, 'zen'],
            'nick without tags'      => [':oldnick!user@host NICK :newnick', 'nick', NickEvent::class, null],
            'nick star account'      => ['@account=* :oldnick!user@host NICK :newnick', 'nick', NickEvent::class, null],
            'part tagged account'    => ['@account=zen :nick!user@host PART #chan :bye', 'part', PartEvent::class, 'zen'],
            'part without tags'      => [':nick!user@host PART #chan :bye', 'part', PartEvent::class, null],
            'part star account'      => ['@account=* :nick!user@host PART #chan :bye', 'part', PartEvent::class, null],
            'quit tagged account'    => ['@account=zen :nick!user@host QUIT :gone', 'quit', QuitEvent::class, 'zen'],
            'quit without tags'      => [':nick!user@host QUIT :gone', 'quit', QuitEvent::class, null],
            'quit star account'      => ['@account=* :nick!user@host QUIT :gone', 'quit', QuitEvent::class, null],
            'kick tagged account'    => ['@account=zen :op!user@host KICK #chan victim :reason', 'kick', KickEvent::class, 'zen'],
            'kick without tags'      => [':op!user@host KICK #chan victim :reason', 'kick', KickEvent::class, null],
            'kick star account'      => ['@account=* :op!user@host KICK #chan victim :reason', 'kick', KickEvent::class, null],
        ];
    }

    /**
     * @dataProvider provideUserEventLines
     * @param class-string $expectedClass
     */
    #[DataProvider('provideUserEventLines')]
    public function test_user_event_account_reflects_account_tag(
        string $raw,
        string $eventKey,
        string $expectedClass,
        ?string $expectedAccount,
    ): void {
        $event = $this->feed($raw, $eventKey);
        $this->assertInstanceOf($expectedClass, $event);
        assert($event instanceof UserEvent);
        $this->assertSame($expectedAccount, $event->account);
    }
}
