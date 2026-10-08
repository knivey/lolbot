<?php

// tests/Settings/StoreTest.php — SettingsStore tiered resolution, persistence,
// and adapter routing, driven by a scratch EntityManager. The scratch DB is
// built from scratch under testenv/run/ (gitignored) via EnvStore::dbPath()
// plus the Seeder migration recipe; the dev sqlite is never touched.
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
use Doctrine\Migrations\Configuration\Migration\YamlFile;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use library\settings\Setting;
use library\settings\SettingsRegistry;
use library\settings\SettingsStore;
use library\settings\SettingStorage;
use library\settings\UnknownSettingException;
use library\testenv\EnvStore;
use lolbot\entities\Bot;
use lolbot\entities\Channel;
use lolbot\entities\Network;
use lolbot\entities\User;
use PHPUnit\Framework\TestCase;

/**
 * Recording fake for adapter-backed settings: get() returns $getValue (null
 * models the adapter's NOT_FOUND sentinel), set()/clear() record their calls.
 */
class StoreFakeStorage implements SettingStorage
{
    /** @var list<array<string, mixed>> */
    public array $setCalls = [];

    /** @var list<array<string, mixed>> */
    public array $clearCalls = [];

    public function __construct(private mixed $getValue = 'from-adapter') {}

    public function get(string $name, ?int $networkId = null, ?int $channelId = null, ?int $userId = null): mixed
    {
        return $this->getValue;
    }

    public function set(string $name, mixed $value, ?int $networkId = null, ?int $channelId = null, ?int $userId = null): void
    {
        $this->setCalls[] = [
            'name' => $name, 'value' => $value,
            'networkId' => $networkId, 'channelId' => $channelId, 'userId' => $userId,
        ];
    }

    public function clear(string $name, ?int $networkId = null, ?int $channelId = null, ?int $userId = null): void
    {
        $this->clearCalls[] = [
            'name' => $name,
            'networkId' => $networkId, 'channelId' => $channelId, 'userId' => $userId,
        ];
    }

    public function returnOnGet(mixed $value): void
    {
        $this->getValue = $value;
    }
}

class StoreTest extends TestCase
{
    private const PROFILE = 'settings_test';

    private static EntityManager $em;
    private static PDO $pdo;
    private static int $net = 0;
    private static int $chan = 0;
    private static int $user = 0;

    private SettingsStore $store;
    private StoreFakeStorage $fake;

    public static function setUpBeforeClass(): void
    {
        // Fresh run DB (never the dev sqlite), all migrations applied —
        // the same recipe Seeder uses for its throwaway EntityManager.
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
        // match bootstrap.php's sqlite branch
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

        // Seed real FK targets so channel_settings/user_settings writes
        // satisfy the CASCADE foreign keys enabled above.
        $network = new Network();
        $network->name = 'store-test-net';
        self::$em->persist($network);
        $bot = new Bot();
        $bot->name = 'StoreTestBot';
        $bot->network = $network;
        $network->addBot($bot);
        self::$em->persist($bot);
        $channel = new Channel();
        $channel->name = '#storetest';
        $bot->addChannel($channel);
        self::$em->persist($channel);
        self::$em->flush();
        $user = new User();
        $user->network_id = $network->id;
        $user->name = 'StoreTester';
        $user->nameLowered = 'storetester';
        self::$em->persist($user);
        self::$em->flush();

        self::$net = $network->id;
        self::$chan = $channel->id;
        self::$user = $user->id;
        self::$pdo = new PDO('sqlite:' . EnvStore::dbPath(self::PROFILE));
    }

    protected function setUp(): void
    {
        // 'store.' prefix keeps these fixtures clear of anything the lazy
        // attribute scan picks up from other test files' fixtures (test.*).
        SettingsRegistry::reset();
        SettingsRegistry::define(new Setting('store.plain', type: 'bool', default: false, scope: 'channel'));
        SettingsRegistry::define(new Setting('store.uacct', type: 'string', default: '', scope: 'account'));
        SettingsRegistry::define(new Setting('store.adapted', type: 'string', default: 'fallback', scope: 'channel'));
        $this->fake = new StoreFakeStorage();
        SettingsRegistry::attachStorage('store.adapted', $this->fake);

        // every test starts from empty settings tables
        self::$em->clear();
        self::$em->getConnection()->executeStatement('DELETE FROM channel_settings');
        self::$em->getConnection()->executeStatement('DELETE FROM user_settings');

        $this->store = new SettingsStore(self::$em);
    }

    protected function tearDown(): void
    {
        SettingsRegistry::reset();
    }

    private static function countRows(string $sql): int
    {
        $stmt = self::$pdo->query($sql);
        if ($stmt === false) {
            self::fail('query failed: ' . $sql);
        }
        return (int) $stmt->fetchColumn();
    }

    private static function fetchColumn(string $sql): string
    {
        $stmt = self::$pdo->query($sql);
        if ($stmt === false) {
            self::fail('query failed: ' . $sql);
        }
        $val = $stmt->fetchColumn();
        if ($val === false || $val === null) {
            self::fail('no rows for: ' . $sql);
        }
        return (string) $val;
    }

    // 1. channel default
    public function test_channel_setting_resolves_to_default_when_no_rows(): void
    {
        $got = $this->store->getChannelSetting(self::$net, self::$chan, 'store.plain');
        $this->assertSame(['value' => false, 'source' => 'default'], $got);
    }

    // 2. channel tier
    public function test_channel_row_wins(): void
    {
        $this->store->setChannelSetting(self::$net, self::$chan, 'store.plain', true);
        $got = $this->store->getChannelSetting(self::$net, self::$chan, 'store.plain');
        $this->assertSame(['value' => true, 'source' => 'channel'], $got);
        // writes stamp `updated`
        $sql = 'SELECT updated FROM channel_settings WHERE network_id = ' . self::$net
            . ' AND channel_id = ' . self::$chan . " AND setting_key = 'store.plain'";
        $this->assertNotSame('', self::fetchColumn($sql));
    }

    // 3. network tier
    public function test_network_row_used_when_channel_row_absent_channel_wins_when_present(): void
    {
        $this->store->setNetworkSetting(self::$net, 'store.plain', true);
        $got = $this->store->getChannelSetting(self::$net, self::$chan, 'store.plain');
        $this->assertSame(['value' => true, 'source' => 'network'], $got);

        $this->store->setChannelSetting(self::$net, self::$chan, 'store.plain', false);
        $got = $this->store->getChannelSetting(self::$net, self::$chan, 'store.plain');
        $this->assertSame(['value' => false, 'source' => 'channel'], $got);
    }

    // 4. NULL uniqueness
    public function test_set_network_setting_twice_yields_exactly_one_null_channel_row(): void
    {
        $this->store->setNetworkSetting(self::$net, 'store.plain', true);
        $this->store->setNetworkSetting(self::$net, 'store.plain', false);
        $sql = 'SELECT COUNT(*) FROM channel_settings WHERE network_id = ' . self::$net
            . " AND channel_id IS NULL AND setting_key = 'store.plain'";
        $this->assertSame(1, self::countRows($sql));
    }

    // 5. clear fallbacks
    public function test_clear_channel_falls_back_to_network_then_default(): void
    {
        $this->store->setNetworkSetting(self::$net, 'store.plain', true);
        $this->store->setChannelSetting(self::$net, self::$chan, 'store.plain', false);

        $this->store->clearChannelSetting(self::$net, self::$chan, 'store.plain');
        $this->assertSame(
            ['value' => true, 'source' => 'network'],
            $this->store->getChannelSetting(self::$net, self::$chan, 'store.plain')
        );

        $this->store->clearNetworkSetting(self::$net, 'store.plain');
        $this->assertSame(
            ['value' => false, 'source' => 'default'],
            $this->store->getChannelSetting(self::$net, self::$chan, 'store.plain')
        );
        $this->assertSame(0, self::countRows("SELECT COUNT(*) FROM channel_settings WHERE setting_key = 'store.plain'"));
    }

    // 6. scalar fidelity
    public function test_scalar_fidelity_survives_json_roundtrip(): void
    {
        $this->store->setChannelSetting(self::$net, self::$chan, 'store.plain', false);
        self::$em->clear(); // force reload from storage, not the identity map
        $got = $this->store->getChannelSetting(self::$net, self::$chan, 'store.plain');
        $this->assertSame(false, $got['value']);

        $this->store->setChannelSetting(self::$net, self::$chan, 'store.plain', 7);
        self::$em->clear();
        $got = $this->store->getChannelSetting(self::$net, self::$chan, 'store.plain');
        $this->assertSame(7, $got['value']);

        $this->store->setUserSetting(self::$user, 'store.uacct', '');
        self::$em->clear();
        $got = $this->store->getUserSetting(self::$user, 'store.uacct');
        $this->assertSame('', $got['value']);
    }

    // 7. user tiers
    public function test_user_setting_default_set_clear(): void
    {
        $this->assertSame(
            ['value' => '', 'source' => 'default'],
            $this->store->getUserSetting(self::$user, 'store.uacct')
        );
        $this->store->setUserSetting(self::$user, 'store.uacct', 'bob');
        $this->assertSame(
            ['value' => 'bob', 'source' => 'user'],
            $this->store->getUserSetting(self::$user, 'store.uacct')
        );
        $this->store->clearUserSetting(self::$user, 'store.uacct');
        $this->assertSame(
            ['value' => '', 'source' => 'default'],
            $this->store->getUserSetting(self::$user, 'store.uacct')
        );
    }

    // 8. adapter routing
    public function test_adapter_backed_setting_routes_to_storage_and_never_touches_entities(): void
    {
        // get: non-null from the adapter
        $this->assertSame(
            ['value' => 'from-adapter', 'source' => 'adapter'],
            $this->store->getChannelSetting(self::$net, self::$chan, 'store.adapted')
        );
        $this->assertSame(
            ['value' => 'from-adapter', 'source' => 'adapter'],
            $this->store->getUserSetting(self::$user, 'store.adapted')
        );

        // get: null from the adapter falls through to the definition default
        $this->fake->returnOnGet(null);
        $this->assertSame(
            ['value' => 'fallback', 'source' => 'default'],
            $this->store->getChannelSetting(self::$net, self::$chan, 'store.adapted')
        );

        // set routes to the adapter with the tier ids passed through
        $this->store->setChannelSetting(self::$net, self::$chan, 'store.adapted', 'x');
        $this->assertSame([
            ['name' => 'store.adapted', 'value' => 'x', 'networkId' => self::$net, 'channelId' => self::$chan, 'userId' => null],
        ], $this->fake->setCalls);

        $this->store->setUserSetting(self::$user, 'store.adapted', 'y');
        $this->assertSame([
            ['name' => 'store.adapted', 'value' => 'x', 'networkId' => self::$net, 'channelId' => self::$chan, 'userId' => null],
            ['name' => 'store.adapted', 'value' => 'y', 'networkId' => null, 'channelId' => null, 'userId' => self::$user],
        ], $this->fake->setCalls);

        // clear routes to the adapter too
        $this->store->clearChannelSetting(self::$net, self::$chan, 'store.adapted');
        $this->assertSame([
            ['name' => 'store.adapted', 'networkId' => self::$net, 'channelId' => self::$chan, 'userId' => null],
        ], $this->fake->clearCalls);

        // the entity tables were never touched
        $this->assertSame(0, self::countRows("SELECT COUNT(*) FROM channel_settings WHERE setting_key = 'store.adapted'"));
        $this->assertSame(0, self::countRows("SELECT COUNT(*) FROM user_settings WHERE setting_key = 'store.adapted'"));
    }

    // 9. unknown setting
    public function test_unknown_setting_throws_from_every_method(): void
    {
        $calls = [
            'getChannelSetting' => fn() => $this->store->getChannelSetting(self::$net, self::$chan, 'nope'),
            'setChannelSetting' => fn() => $this->store->setChannelSetting(self::$net, self::$chan, 'nope', 1),
            'setNetworkSetting' => fn() => $this->store->setNetworkSetting(self::$net, 'nope', 1),
            'clearChannelSetting' => fn() => $this->store->clearChannelSetting(self::$net, self::$chan, 'nope'),
            'clearNetworkSetting' => fn() => $this->store->clearNetworkSetting(self::$net, 'nope'),
            'getUserSetting' => fn() => $this->store->getUserSetting(self::$user, 'nope'),
            'setUserSetting' => fn() => $this->store->setUserSetting(self::$user, 'nope', 1),
            'clearUserSetting' => fn() => $this->store->clearUserSetting(self::$user, 'nope'),
        ];
        foreach ($calls as $method => $call) {
            try {
                $call();
                $this->fail("{$method} accepted an unknown setting");
            } catch (UnknownSettingException $e) {
                $this->assertSame("unknown setting 'nope'", $e->getMessage(), $method);
            }
        }
    }
}
