<?php

namespace Tests\Alias;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Irc\Event\ChatEvent;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;
use knivey\cmdr\Cmdr;
use library\user\Access;
use library\user\Acl;
use library\user\Flags;
use library\user\UserSystemFactory;
use lolbot\entities\Bot;
use lolbot\entities\Network;
use lolbot\entities\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use scripts\alias\alias as AliasScript;
use scripts\alias\entities\alias as AliasEntity;

require_once __DIR__ . '/../../library/Nicks.php';
require_once __DIR__ . '/../../library/Channels.php';
require_once __DIR__ . '/../../scripts/user/user.php';

/**
 * Irc\Client stand-in recording notice()/msg() instead of sending; the
 * assertions read what it captured. The parent constructor is
 * deliberately not called (connection machinery stays unconstructed),
 * the same pattern as AclFakeClient in Tests\User.
 */
final class AliasDenyClient extends \Irc\Client
{
    /** @var list<array{0: string, 1: string}> */
    public array $notices = [];
    /** @var list<array{0: string, 1: string}> */
    public array $msgs = [];

    public function __construct()
    {
    }

    public function notice(string $nick, string $message): static
    {
        $this->notices[] = [$nick, $message];
        return $this;
    }

    public function msg(string $target, string $message): static
    {
        $this->msgs[] = [$target, $message];
        return $this;
    }
}

// Nicks/Channels subscribe to the client's event emitter in their
// constructors; handleCmd() never touches them, so empty-constructor
// stand-ins suffice (their typed props stay uninitialized but unread).
final class AliasDenyNicks extends \Nicks
{
    public function __construct()
    {
    }
}

final class AliasDenyChannels extends \Channels
{
    public function __construct()
    {
    }
}

// Real #[Acl]-gated PUBLIC command, loaded through the router's own
// attribute reflection exactly like lolbot.php loads every command.
// user.php's gated commands (setflags & co) are #[PrivCmd]s, which
// handleCmd()'s cmdExists() guard rejects before the router ever runs,
// so the gate exercised here must be a public #[Cmd].
#[Cmd('gatedpoke')]
#[Syntax('[text]...')]
#[Acl('admin')]
function aliasdeny_gated(ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): string
{
    return 'gated command ran';
}

#[Cmd('plainpoke')]
#[Syntax('[text]...')]
function aliasdeny_plain(ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
{
    $bot->msg($args->chan, 'plain command ran');
}

/**
 * Issue #137: handleCmd() must pass a middleware deny string (e.g. the
 * acl middleware's "auth required" for an unauthed speaker) through to
 * the requesting nick via notice(), the same deny-string convention
 * BotManager's chat/pm handlers already follow for router->call()
 * returns.
 */
class AliasDenyPassthroughTest extends TestCase
{
    private EntityManager $em;
    private Cmdr $router;
    private AliasDenyClient $client;
    private Network $network;
    private AliasScript $script;

    protected function setUp(): void
    {
        // same entity paths as bootstrap.php plus the alias entities,
        // in-memory sqlite with the schema created from the metadata
        $paths = [
            __DIR__ . '/../../entities',
            __DIR__ . '/../../scripts/linktitles/entities',
            __DIR__ . '/../../scripts/weather/entities',
            __DIR__ . '/../../scripts/lastfm/entities',
            __DIR__ . '/../../scripts/remindme/entities',
            __DIR__ . '/../../scripts/alias/entities',
        ];
        $ormConfig = ORMSetup::createAttributeMetadataConfiguration($paths, true);
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $ormConfig);
        $this->em = new EntityManager($conn, $ormConfig);
        $tool = new SchemaTool($this->em);
        $tool->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        // alias::init() reads the entity manager from globals
        $GLOBALS['entityManager'] = $this->em;

        // real router with the real acl middleware and the production
        // user resolver wired, exactly like BotManager::spawn() does
        $this->router = new Cmdr();
        $this->router->loadFuncs();
        Acl::register($this->router);
        UserSystemFactory::wireAccess();

        $this->network = new Network();
        $this->network->name = 'DenyNet';
        $this->em->persist($this->network);
        $this->em->flush();

        $this->client = new AliasDenyClient();
        $bot = new Bot();
        $bot->name = 'denybot';
        $bot->network = $this->network;

        // unauthed speaker: the event carries no services account and
        // the fake client has no userSystem, so the wired resolver
        // finds no user and the acl middleware must deny
        $this->script = new AliasScript(
            $this->network,
            $bot,
            new Server(),
            [],
            $this->client,
            new NullLogger(),
            new AliasDenyNicks(),
            new AliasDenyChannels(),
            $this->router,
        );
    }

    protected function tearDown(): void
    {
        // Access state is process-global; reset so the wired resolver
        // and flag definitions do not leak into other tests
        Access::before(null);
        Access::userResolver(null);
        Flags::reset();
        unset($GLOBALS['entityManager']);
        $this->em->close();
    }

    private function seedAlias(string $name, string $cmd): AliasEntity
    {
        $alias = new AliasEntity();
        $alias->name = $name;
        $alias->nameLowered = strtolower($name);
        $alias->value = 'hello there';
        $alias->chan = '#gate';
        $alias->chanLowered = '#gate';
        $alias->fullhost = 'stranger!i@h';
        $alias->act = false;
        $alias->cmd = $cmd;
        $alias->network = $this->network;
        $this->em->persist($alias);
        $this->em->flush();
        return $alias;
    }

    private function chatEvent(string $text): ChatEvent
    {
        return new ChatEvent(
            time(), 'privmsg', $this->client, 'stranger', 'i', 'h', 'i@h',
            'stranger!i@h', '#gate', $text,
        );
    }

    public function test_gated_alias_deny_string_reaches_nick_via_notice(): void
    {
        $this->seedAlias('poke', 'gatedpoke');

        $handled = $this->script->handleCmd($this->chatEvent('poke'), $this->client, 'poke', []);

        $this->assertTrue($handled);
        // the acl middleware's deny string must reach the nick
        $this->assertContains(['stranger', 'auth required'], $this->client->notices);
        // ...and the gated command itself must never have run
        $this->assertSame([], $this->client->msgs);
    }

    public function test_ungated_alias_runs_command_without_notice(): void
    {
        $this->seedAlias('plain', 'plainpoke');

        $handled = $this->script->handleCmd($this->chatEvent('plain'), $this->client, 'plain', []);

        $this->assertTrue($handled);
        // no middleware short-circuit: no notice, the command ran
        $this->assertSame([], $this->client->notices);
        $this->assertContains(['#gate', 'plain command ran'], $this->client->msgs);
    }
}
