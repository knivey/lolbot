<?php
namespace Tests\User;

// tests/User/CflagsBeforeHookTest.php — #140: the .cflags granter gate
// must honor the superadmin before-hook (Access::beforeAllows()) the
// same way Acl::middleware's channel path does. Acl::middleware checks
// the before-hook BEFORE the channel-union flags check so a registered
// Access::before() hook can never leave the owner denied; the cflags
// granter gate ran only the union check, so the same hook-owner would
// be told "you can't change X here" for flags they pass everywhere
// else. Drives the REAL router + the real scripts/user/user.php cflags
// handler over a scratch testenv DB (EnvStore::dbPath(), same recipe
// as ChannelByNameScopeTest); the chat() driver mirrors the
// /tmp/opencode/target_e2e.php harness.

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
use Doctrine\Migrations\Configuration\Migration\YamlFile;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Irc\Event\ChatEvent;
use knivey\cmdr\Cmdr;
use library\testenv\EnvStore;
use library\user\Access;
use library\user\Acl;
use library\user\DoctrineChannelFlagRepo;
use library\user\DoctrineUserHostmaskRepo;
use library\user\DoctrineUserRepo;
use library\user\Flags;
use library\user\IdentityCache;
use library\user\IdentityService;
use library\user\UserRepos;
use library\user\UserSystem;
use library\user\engines\HostmaskEngine;
use lolbot\entities\Bot;
use lolbot\entities\Channel;
use lolbot\entities\ChannelFlag;
use lolbot\entities\Network;
use lolbot\entities\User as UserEntity;
use lolbot\entities\UserHostmask;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../library/Irc/Consts.php';

/**
 * Minimal Irc\Client stand-in: the cflags handler only replies through
 * msg()/pm() and asks hasOption() about WHOX, so the connection
 * machinery stays unconstructed (parent constructor deliberately not
 * called). Recorded sends are the bot's channel output.
 */
final class CflagsHookClient extends \Irc\Client
{
    /** @var list<array{0: string, 1: string}> */
    public array $sent = [];

    public function __construct()
    {
    }

    public function pm(string $nick, string $message): static
    {
        $this->sent[] = [$nick, $message];
        return $this;
    }

    public function hasOption(string $opt): bool
    {
        return false;
    }
}

class CflagsBeforeHookTest extends TestCase
{
    private const PROFILE = 'cflagshook';

    private static EntityManager $em;
    private static Network $net;
    private static Channel $chan;
    private static UserEntity $granter;
    private static UserEntity $victim;
    private static Cmdr $router;
    private static CflagsHookClient $client;

    public static function setUpBeforeClass(): void
    {
        EnvStore::wipe(self::PROFILE);
        $root = dirname(__DIR__, 2);
        // keep in sync with bootstrap.php: script entities live in separate
        // dirs so the script files are not autoloaded at the wrong time
        $paths = [
            $root . '/entities',
            $root . '/scripts/linktitles/entities',
            $root . '/scripts/weather/entities',
            $root . '/scripts/lastfm/entities',
            $root . '/scripts/remindme/entities',
        ];
        $ormConfig = ORMSetup::createAttributeMetadataConfiguration($paths, true);
        $conn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => EnvStore::dbPath(self::PROFILE),
        ], $ormConfig);
        if ($conn->getDatabasePlatform()::class === SqlitePlatform::class) {
            $conn->executeStatement('PRAGMA foreign_keys=ON');
        }
        self::$em = new EntityManager($conn, $ormConfig);

        $df = DependencyFactory::fromEntityManager(
            new YamlFile($root . '/migrations.yml'),
            new ExistingEntityManager(self::$em)
        );
        $df->getMetadataStorage()->ensureInitialized();
        $plan = $df->getMigrationPlanCalculator()->getPlanUntilVersion(
            $df->getVersionAliasResolver()->resolveVersionAlias('latest')
        );
        $df->getMigrator()->migrate($plan, new MigratorConfiguration());

        // the #140 world: one channel, a granter row with NO flags at
        // all (no network admin, no channel grant) whose only identity
        // is a stored hostmask, and a pre-seeded target row so the
        // *account spec resolves without any services engine
        self::$net = new Network();
        self::$net->name = 'cflagshook-test-net';
        self::$em->persist(self::$net);
        self::$em->flush();
        $bot = new Bot();
        $bot->name = 'cflagshookbot';
        $bot->network = self::$net;
        self::$net->addBot($bot);
        self::$em->persist($bot);
        self::$chan = new Channel();
        self::$chan->name = '#gate';
        $bot->addChannel(self::$chan);
        self::$em->persist(self::$chan);
        self::$granter = new UserEntity();
        self::$granter->network_id = self::$net->id;
        self::$granter->name = 'HookBoss';
        self::$granter->nameLowered = 'hookboss';
        self::$granter->flags = [];
        self::$em->persist(self::$granter);
        self::$victim = new UserEntity();
        self::$victim->network_id = self::$net->id;
        self::$victim->name = 'victim';
        self::$victim->nameLowered = 'victim';
        self::$em->persist(self::$victim);
        self::$em->flush();
        $mask = new UserHostmask();
        $mask->user_id = self::$granter->id;
        $mask->mask = '*!i@h';
        $mask->addedBy = 'seed';
        self::$em->persist($mask);
        self::$em->flush();

        // EFnet-class bundle (hostmask engine only): the granter's nick
        // resolves through the seeded mask, targets come pre-seeded
        $users = new DoctrineUserRepo(self::$em);
        $repos = new UserRepos($users, new DoctrineUserHostmaskRepo(self::$em), new DoctrineChannelFlagRepo(self::$em));
        $repos->network = self::$net;
        $svc = new IdentityService(new IdentityCache(), [new HostmaskEngine($repos->masks)]);
        $bundle = new UserSystem(self::$net, $bot, $svc, $repos, new IdentityCache(), self::$em);
        self::$client = new CflagsHookClient();
        self::$client->userSystem = $bundle;

        self::$router = new Cmdr();
        require_once $root . '/scripts/user/user.php';
        self::$router->loadFuncs();
        Acl::register(self::$router);
    }

    public function setUp(): void
    {
        // clean grants so one test's persisted row can't leak into the
        // next, and (re)define the flag under test after a previous
        // test's Flags::reset()
        self::$em->createQuery('DELETE FROM lolbot\entities\ChannelFlag cf')->execute();
        Flags::define('quotes', []);
    }

    public function tearDown(): void
    {
        // reset global Access state so the before-hook doesn't leak into other tests
        Access::before(null);
        Flags::reset();
    }

    /**
     * Drive .cflags through the real router like the bot would. Returns
     * the bot's channel output (plus a middleware short-circuit string,
     * should one ever fire).
     *
     * @return list<string>
     */
    private static function chat(string $nick, string $text): array
    {
        self::$client->sent = [];
        $ev = new ChatEvent(time(), 'privmsg', self::$client, $nick, 'i', 'h', 'i@h', "$nick!i@h", '#gate', $text, null);
        // await() is untyped (mixed) on the Future; the closure's return
        // shape is the truth — re-narrow for the declared return
        /** @var list<string> $out */
        $out = \Amp\async(function () use ($ev, $text): array {
            $ret = self::$router->call('cflags', $text, $ev, self::$client);
            $lines = array_map(fn (array $s): string => $s[1], self::$client->sent);
            if (is_string($ret)) {
                $lines[] = $ret;
            }
            return $lines;
        })->await();
        return $out;
    }

    public function test_before_hook_lets_a_flagless_granter_change_cflags(): void
    {
        // #140: Acl::middleware's channel path checks the before-hook
        // first, so a superadmin hook covers channel-scoped gates; the
        // cflags granter gate must do the same — the owner can never be
        // locked out of .cflags changes they can pass everywhere else
        Access::before(fn (object ...$args): bool => true);
        $out = self::chat('hookboss', '*victim +quotes');
        $this->assertContains('flags for victim in #gate: quotes', $out);
        // the grant actually persisted, not just a success reply
        $row = self::$em->getRepository(ChannelFlag::class)->findOneBy([
            'channel_id' => self::$chan->id,
            'user_id' => self::$victim->id,
        ]);
        $this->assertInstanceOf(ChannelFlag::class, $row);
        $this->assertSame(['quotes'], $row->flags);
    }

    public function test_without_hook_flagless_granter_still_denied(): void
    {
        // regression side: no hook registered, granter with no flags
        // and no channel grant — the channel-union gate must deny
        // exactly as before the hook support
        $out = self::chat('hookboss', '*victim +quotes');
        $this->assertContains("you can't change quotes here", $out);
        $row = self::$em->getRepository(ChannelFlag::class)->findOneBy([
            'channel_id' => self::$chan->id,
            'user_id' => self::$victim->id,
        ]);
        $this->assertNull($row);
    }
}
