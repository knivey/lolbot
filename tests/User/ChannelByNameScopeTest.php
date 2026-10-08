<?php
namespace Tests\User;

// tests/User/ChannelByNameScopeTest.php — #139: channelByName() must
// resolve the Channel row scoped to the bundle's OWN bot. Channel rows
// are per-bot (Channels.bot FK), so two bots on one network configured
// for the same channel name make the old network-wide lookup throw
// NonUniqueResultException — and every channel-scoped gate in that
// channel failed closed. The scratch DB is built fresh under
// testenv/run/ (gitignored) via EnvStore::dbPath(), same recipe as
// tests/Settings/MinAccessTest.php; the dev sqlite is never touched.

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
use Doctrine\Migrations\Configuration\Migration\YamlFile;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\ORMSetup;
use library\testenv\EnvStore;
use library\user\DoctrineChannelFlagRepo;
use library\user\DoctrineUserHostmaskRepo;
use library\user\DoctrineUserRepo;
use library\user\IdentityCache;
use library\user\IdentityService;
use library\user\UserRepos;
use library\user\UserSystem;
use lolbot\entities\Bot;
use lolbot\entities\Channel;
use lolbot\entities\Network;
use PHPUnit\Framework\TestCase;

class ChannelByNameScopeTest extends TestCase
{
    private const PROFILE = 'chanbyscope';

    private static EntityManager $em;
    private static Network $net;
    private static Bot $b1;
    private static Bot $b2;
    private static Channel $b1chan;
    private static Channel $b2chan;

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

        // the #139 world: ONE network, TWO bots, both configured for a
        // channel with the SAME name — the old network-wide lookup hit
        // both rows and threw NonUniqueResultException
        self::$net = new Network();
        self::$net->name = 'chanbyscope-test-net';
        self::$em->persist(self::$net);
        self::$b1 = new Bot();
        self::$b1->name = 'ChanByScopeBot1';
        self::$b1->network = self::$net;
        self::$net->addBot(self::$b1);
        self::$em->persist(self::$b1);
        self::$b2 = new Bot();
        self::$b2->name = 'ChanByScopeBot2';
        self::$b2->network = self::$net;
        self::$net->addBot(self::$b2);
        self::$em->persist(self::$b2);
        self::$b1chan = new Channel();
        self::$b1chan->name = '#dupe';
        self::$b1->addChannel(self::$b1chan);
        self::$em->persist(self::$b1chan);
        self::$b2chan = new Channel();
        self::$b2chan->name = '#dupe';
        self::$b2->addChannel(self::$b2chan);
        self::$em->persist(self::$b2chan);
        self::$em->flush();
    }

    private function makeBundle(Bot $bot): UserSystem
    {
        // channelByName()'s DQL is the only EM touch, so the engine chain
        // can stay empty — this pins the lookup, not identity resolution
        $users = new DoctrineUserRepo(self::$em);
        $repos = new UserRepos($users, new DoctrineUserHostmaskRepo(self::$em), new DoctrineChannelFlagRepo(self::$em));
        $repos->network = self::$net;
        $svc = new IdentityService(new IdentityCache(), []);
        return new UserSystem(self::$net, $bot, $svc, $repos, new IdentityCache(), self::$em);
    }

    public function testReturnsTheBundlesOwnBotsChannelRowForADuplicateName(): void
    {
        $bundle1 = $this->makeBundle(self::$b1);
        try {
            $got = $bundle1->channelByName('#dupe');
        } catch (NonUniqueResultException $e) {
            // the pre-#139 behavior: the lookup crossed every bot on the
            // network, so two rows for one name blew up the one-or-null
            $this->fail('channelByName() must be scoped to the bundle\'s bot: ' . $e->getMessage());
        }
        $this->assertSame(self::$b1chan, $got);
        $this->assertNotSame(self::$b2chan, $got);
        // lowered compare still applies inside the per-bot scope
        $this->assertSame(self::$b1chan, $bundle1->channelByName('#DUPE'));
    }

    public function testTheScopeFollowsTheBundlesBotNotJustAnyBot(): void
    {
        // the mirror bundle: same network, same channel name, but built
        // for bot2 — it must get bot2's row, proving the bundle's bot is
        // what scopes the lookup
        $bundle2 = $this->makeBundle(self::$b2);
        $this->assertSame(self::$b2chan, $bundle2->channelByName('#dupe'));
    }

    public function testUnknownChannelIsNull(): void
    {
        $bundle1 = $this->makeBundle(self::$b1);
        $this->assertNull($bundle1->channelByName('#missing'));
    }
}
