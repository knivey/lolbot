<?php

// tests/Settings/MinAccessTest.php — the minmode setting (#128): the
// channel-status ladder (MinAccess), its registry definition, and the
// tiered store resolution (channel over network over default). The scratch
// DB is built fresh under testenv/run/ (gitignored) via EnvStore::dbPath(),
// same recipe as StoreTest; the dev sqlite is never touched.
require_once __DIR__ . '/../../library/Nicks.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
use Doctrine\Migrations\Configuration\Migration\YamlFile;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use library\settings\MinAccess;
use library\settings\SettingsRegistry;
use library\settings\SettingsStore;
use library\testenv\EnvStore;
use lolbot\entities\Bot;
use lolbot\entities\Channel;
use lolbot\entities\Network;
use PHPUnit\Framework\TestCase;

/**
 * Nicks stub with a canned rank per nick (0 none, 1 voice, 2 halfop,
 * 3 op, 4 admin, 5 owner). The ladder's job is dispatch onto the five
 * Nicks predicates, so the stub implements exactly their OrHigher
 * semantics: rank(nick) >= rank(required mode).
 */
class MinAccessFakeNicks extends \Nicks
{
    private const RANKS = ['+' => 1, '%' => 2, '@' => 3, '&' => 4, '~' => 5];

    /** @param array<string, int> $ranks */
    public function __construct(private readonly array $ranks = [])
    {
    }

    private function rank(string $nick): int
    {
        return $this->ranks[$nick] ?? 0;
    }

    public function isVoiceOrHigher(string $nick, string $chan): bool
    {
        return $this->rank($nick) >= self::RANKS['+'];
    }

    public function isHalfOpOrHigher(string $nick, string $chan): bool
    {
        return $this->rank($nick) >= self::RANKS['%'];
    }

    public function isOpOrHigher(string $nick, string $chan): bool
    {
        return $this->rank($nick) >= self::RANKS['@'];
    }

    public function isAdminOrHigher(string $nick, string $chan): bool
    {
        return $this->rank($nick) >= self::RANKS['&'];
    }

    public function isOwner(string $nick, string $chan): bool
    {
        return $this->rank($nick) >= self::RANKS['~'];
    }
}

class MinAccessTest extends TestCase
{
    private const PROFILE = 'minaccess_test';

    private static EntityManager $em;
    private static Network $net;
    private static Channel $chan;

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

        self::$net = new Network();
        self::$net->name = 'minaccess-test-net';
        self::$em->persist(self::$net);
        $bot = new Bot();
        $bot->name = 'MinAccessTestBot';
        $bot->network = self::$net;
        self::$net->addBot($bot);
        self::$em->persist($bot);
        self::$chan = new Channel();
        self::$chan->name = '#minaccess';
        $bot->addChannel(self::$chan);
        self::$em->persist(self::$chan);
        self::$em->flush();
    }

    public function setUp(): void
    {
        SettingsRegistry::reset();
    }

    public function testValidRecognizesModeSymbolsAndOpen(): void
    {
        foreach (['', '+', '%', '&', '@', '~'] as $mode) {
            $this->assertTrue(MinAccess::valid($mode), " '{$mode}' should be valid");
        }
        // words, doubled symbols, the whole ladder as one string, blanks —
        // the old YAML misconfiguration class — must all be refused
        foreach (['v', 'op', 'voice', '++', '~&@%+', 'x', ' ', '@@'] as $mode) {
            $this->assertFalse(MinAccess::valid($mode), "'{$mode}' should be invalid");
        }
    }

    public function testOpenModeAllowsAnyone(): void
    {
        $nicks = new MinAccessFakeNicks([]);
        // even a nick the bot has never seen: '' is the documented open gate
        $this->assertTrue(MinAccess::met($nicks, 'stranger', '#c', ''));
    }

    public function testLadderRequiresMinimumStatus(): void
    {
        $nicks = new MinAccessFakeNicks([
            'voiced' => 1, 'halfop' => 2, 'opped' => 3, 'adminned' => 4, 'owned' => 5,
        ]);
        // mode => statuses that pass / fail (ranks: + 1, % 2, @ 3, & 4, ~ 5)
        $cases = [
            '+' => ['voiced', 'halfop', 'opped', 'adminned', 'owned'],
            '%' => ['halfop', 'opped', 'adminned', 'owned'],
            '@' => ['opped', 'adminned', 'owned'],
            '&' => ['adminned', 'owned'],
            '~' => ['owned'],
        ];
        $all = ['voiced', 'halfop', 'opped', 'adminned', 'owned'];
        foreach ($cases as $mode => $passing) {
            foreach ($all as $nick) {
                $this->assertSame(
                    in_array($nick, $passing, true),
                    MinAccess::met($nicks, $nick, '#c', $mode),
                    "nick '{$nick}' vs minmode '{$mode}'",
                );
            }
            $this->assertFalse(
                MinAccess::met($nicks, 'plainuser', '#c', $mode),
                "statusless nick must fail minmode '{$mode}'",
            );
        }
    }

    public function testMetRejectsInvalidMode(): void
    {
        $nicks = new MinAccessFakeNicks(['voiced' => 1]);
        $this->expectException(\InvalidArgumentException::class);
        MinAccess::met($nicks, 'voiced', '#c', 'op');
    }

    public function testDefineSettingRegistersDefinition(): void
    {
        MinAccess::defineSetting();
        $def = SettingsRegistry::get('minmode');
        $this->assertNotNull($def);
        $this->assertSame('minmode', $def->name);
        $this->assertSame('enum', $def->type);
        $this->assertSame(['', '+', '%', '@', '&', '~'], $def->enum_of);
        $this->assertSame('', $def->default);
        $this->assertSame('channel', $def->scope);
        $this->assertSame('admin', $def->flag);
        $this->assertFalse($def->network_only);
        $this->assertNotSame('', $def->description);
        // a core-setting row in channel_settings, never adapter-backed
        $this->assertNull(SettingsRegistry::storage('minmode'));
    }

    public function testStoreResolvesMinmodeTiers(): void
    {
        MinAccess::defineSetting();
        $store = new SettingsStore(self::$em);
        $netId = self::$net->id;
        $chanId = self::$chan->id;

        $got = $store->getChannelSetting($netId, $chanId, 'minmode');
        $this->assertSame('', $got['value']);
        $this->assertSame('default', $got['source']);

        // network tier is the old per-network restriction, one command
        $store->setNetworkSetting($netId, 'minmode', '+');
        $got = $store->getChannelSetting($netId, $chanId, 'minmode');
        $this->assertSame('+', $got['value']);
        $this->assertSame('network', $got['source']);

        // channel tier overrides the network tier per channel
        $store->setChannelSetting($netId, $chanId, 'minmode', '%');
        $got = $store->getChannelSetting($netId, $chanId, 'minmode');
        $this->assertSame('%', $got['value']);
        $this->assertSame('channel', $got['source']);

        // clearing the channel tier falls back to the network tier
        $store->clearChannelSetting($netId, $chanId, 'minmode');
        $got = $store->getChannelSetting($netId, $chanId, 'minmode');
        $this->assertSame('+', $got['value']);
        $this->assertSame('network', $got['source']);

        // clearing the network tier falls back to the open default
        $store->clearNetworkSetting($netId, 'minmode');
        $got = $store->getChannelSetting($netId, $chanId, 'minmode');
        $this->assertSame('', $got['value']);
        $this->assertSame('default', $got['source']);
    }
}
