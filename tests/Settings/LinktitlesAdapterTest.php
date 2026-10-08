<?php

// tests/Settings/LinktitlesAdapterTest.php — the linktitles storage
// adapter over its own linktitles_settings table, driven through the
// generic SettingsStore. Same scratch-EM bootstrap as StoreTest: fresh
// DB under testenv/run/ via EnvStore::dbPath() plus the Seeder
// migration recipe; the dev sqlite is never touched. Requiring the
// adapter file is what registers the linktitles.* definitions (the
// file-load registration the bot gets via linktitles.php's require),
// then the scratch EM is injected per test through
// linktitles_register_settings() after the registry reset.
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
use Doctrine\Migrations\Configuration\Migration\YamlFile;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use library\settings\SettingsRegistry;
use library\settings\SettingsStore;
use library\testenv\EnvStore;
use lolbot\config\LinktitlesDefaults;
use lolbot\entities\Bot;
use lolbot\entities\Channel;
use lolbot\entities\Network;
use PHPUnit\Framework\TestCase;
use scripts\linktitles\entities\linktitles_setting;

class LinktitlesAdapterTest extends TestCase
{
    private const PROFILE = 'linktitles_adapter';

    private static EntityManager $em;
    private static PDO $pdo;
    private static int $net = 0;
    private static int $chan = 0;

    private SettingsStore $store;

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

        // Seed real FK targets so linktitles_settings writes satisfy the
        // CASCADE foreign keys enabled above.
        $network = new Network();
        $network->name = 'lt-adapter-net';
        self::$em->persist($network);
        $bot = new Bot();
        $bot->name = 'LtAdapterBot';
        $bot->network = $network;
        $network->addBot($bot);
        self::$em->persist($bot);
        $channel = new Channel();
        $channel->name = '#ltadapter';
        $bot->addChannel($channel);
        self::$em->persist($channel);
        self::$em->flush();

        self::$net = $network->id;
        self::$chan = $channel->id;
        self::$pdo = new PDO('sqlite:' . EnvStore::dbPath(self::PROFILE));

        // the adapter file registers its definitions at load; before it
        // existed this require was skipped and every test below red with
        // UnknownSettingException, which is why it stays guarded
        $adapter = $root . '/scripts/linktitles/settings_adapter.php';
        if (is_file($adapter)) {
            require_once $adapter;
        }
    }

    protected function setUp(): void
    {
        SettingsRegistry::reset();
        // the scratch EM replaces the lazy global-EntityManager lookup
        // for this process (no bootstrap.php here)
        if (function_exists('scripts\linktitles\linktitles_register_settings')) {
            \scripts\linktitles\linktitles_register_settings(self::$em);
        }

        // every test starts from empty settings tables
        self::$em->clear();
        self::$em->getConnection()->executeStatement('DELETE FROM linktitles_settings');
        self::$em->getConnection()->executeStatement('DELETE FROM channel_settings');

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

    // 1. store round trip lands in linktitles_settings
    public function test_set_channel_setting_round_trips_through_linktitles_row(): void
    {
        $this->store->setChannelSetting(self::$net, self::$chan, 'linktitles.enabled', false);
        $this->assertSame(
            ['value' => false, 'source' => 'adapter'],
            $this->store->getChannelSetting(self::$net, self::$chan, 'linktitles.enabled')
        );
        // the real table changed: a (network, channel) row holds enabled=false
        $sql = 'SELECT enabled FROM linktitles_settings WHERE network_id = ' . self::$net
            . ' AND channel_id = ' . self::$chan;
        $this->assertSame(0, (int) self::fetchColumn($sql));
    }

    // 2. adapter-backed writes never touch the generic table
    public function test_adapter_write_creates_no_channel_settings_row(): void
    {
        $this->store->setChannelSetting(self::$net, self::$chan, 'linktitles.enabled', false);
        $this->store->setChannelSetting(self::$net, self::$chan, 'linktitles.url_log_chan', '#log');
        $this->assertSame(0, self::countRows('SELECT COUNT(*) FROM channel_settings'));
    }

    // 3. the adapter's own tiering: channel read falls back to the network row
    public function test_channel_read_falls_back_to_network_tier_row(): void
    {
        // channelId null in adapter terms = the network row
        $this->store->setNetworkSetting(self::$net, 'linktitles.enabled', false);

        // the channel has no row of its own and still reads the network value
        $this->assertSame(
            ['value' => false, 'source' => 'adapter'],
            $this->store->getChannelSetting(self::$net, self::$chan, 'linktitles.enabled')
        );
        // reading the network tier directly sees the same row
        $this->assertSame(
            ['value' => false, 'source' => 'adapter'],
            $this->store->getChannelSetting(self::$net, null, 'linktitles.enabled')
        );
        // no channel-tier row was created by the reads
        $sql = 'SELECT COUNT(*) FROM linktitles_settings WHERE network_id = ' . self::$net
            . ' AND channel_id = ' . self::$chan;
        $this->assertSame(0, self::countRows($sql));
    }

    // 4. clear NULLs the channel-tier column and falls back to network
    public function test_clear_channel_setting_nulls_column_and_falls_back(): void
    {
        $this->store->setNetworkSetting(self::$net, 'linktitles.enabled', true);
        $this->store->setChannelSetting(self::$net, self::$chan, 'linktitles.enabled', false);

        $this->store->clearChannelSetting(self::$net, self::$chan, 'linktitles.enabled');

        // the channel row still exists with its column NULLed (inherit)
        $sql = 'SELECT COUNT(*) FROM linktitles_settings WHERE network_id = ' . self::$net
            . ' AND channel_id = ' . self::$chan . ' AND enabled IS NULL';
        $this->assertSame(1, self::countRows($sql));
        // the effective value falls back to the network tier
        $this->assertSame(
            ['value' => true, 'source' => 'adapter'],
            $this->store->getChannelSetting(self::$net, self::$chan, 'linktitles.enabled')
        );
    }

    // 5. the raw-JSON blob definition exists but stays off IRC lists
    public function test_reasoning_blob_definition_is_irc_invisible(): void
    {
        $definition = SettingsRegistry::get('linktitles.ai_vision_reasoning');
        $this->assertNotNull($definition);
        $this->assertFalse($definition->irc);

        // invisible to .set lists (all() with irc: true)…
        $this->assertArrayNotHasKey(
            'linktitles.ai_vision_reasoning',
            SettingsRegistry::all(scope: 'channel', irc: true)
        );
        // …while the IRC-surface settings stay listed
        $this->assertArrayHasKey(
            'linktitles.enabled',
            SettingsRegistry::all(scope: 'channel', irc: true)
        );

        // every definition carries the pinned shape. Defaults for fields
        // with a LinktitlesDefaults counterpart mirror the constant — the
        // runtime resolver's own cascade bottom — so `.set`-reported
        // defaults match live behavior; nullable-override fields keep ''
        // (the runtime cascade bottoms out at null for those).
        $expected = [
            'linktitles.enabled' => ['bool', LinktitlesDefaults::ENABLED, true],
            'linktitles.ai_vision_disabled' => ['bool', LinktitlesDefaults::AI_VISION_DISABLED, true],
            'linktitles.url_log_chan' => ['string', '', true],
            'linktitles.ai_vision_model' => ['string', LinktitlesDefaults::MODEL, true],
            'linktitles.ai_vision_prompt' => ['string', LinktitlesDefaults::PROMPT, true],
            'linktitles.ai_vision_reasoning_effort' => ['string', '', true],
            'linktitles.ai_vision_reasoning' => ['string', '', false],
        ];
        foreach ($expected as $name => [$type, $default, $irc]) {
            $def = SettingsRegistry::get($name);
            $this->assertNotNull($def, $name);
            $this->assertSame('channel', $def->scope, $name);
            $this->assertSame('admin', $def->flag, $name);
            $this->assertSame($type, $def->type, $name);
            $this->assertSame($default, $def->default, $name);
            $this->assertSame($irc, $def->irc, $name);
            $this->assertNotSame('', $def->description, $name);
        }
    }

    // 6. with no rows anywhere, the .set-level read reports the runtime
    // default (the LinktitlesDefaults constant) with source 'default' —
    // what `.set linktitles.enabled` shows must match what the live
    // resolver actually falls back to
    public function test_no_rows_read_reports_linktitles_default_with_default_source(): void
    {
        $this->assertSame(
            ['value' => LinktitlesDefaults::ENABLED, 'source' => 'default'],
            $this->store->getChannelSetting(self::$net, self::$chan, 'linktitles.enabled')
        );
        $this->assertSame(
            ['value' => LinktitlesDefaults::AI_VISION_DISABLED, 'source' => 'default'],
            $this->store->getChannelSetting(self::$net, self::$chan, 'linktitles.ai_vision_disabled')
        );
        $this->assertSame(
            ['value' => LinktitlesDefaults::MODEL, 'source' => 'default'],
            $this->store->getChannelSetting(self::$net, self::$chan, 'linktitles.ai_vision_model')
        );
        $this->assertSame(
            ['value' => LinktitlesDefaults::PROMPT, 'source' => 'default'],
            $this->store->getChannelSetting(self::$net, self::$chan, 'linktitles.ai_vision_prompt')
        );
    }

    // 7. an unknown network id must fail loud: resolve() used to hand
    // back a null Network for a missing row, silently degrading the
    // read (and any write) to the global tier (issue #144 item 4)
    public function test_get_with_unknown_network_id_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unknown network id 424242');
        $this->store->getChannelSetting(424242, null, 'linktitles.enabled');
    }

    // 8. the null-network-id path is the legitimate global tier, not an
    // error: a write lands on the (NULL, NULL) row and reads back —
    // only ids that name no Network row throw
    public function test_null_network_id_still_reaches_the_global_tier(): void
    {
        $storage = new \scripts\linktitles\LinktitlesSettingStorage(self::$em);
        $storage->set('linktitles.enabled', false);
        $this->assertFalse($storage->get('linktitles.enabled'));
    }

    // 9. WRITABLE_KEYS is the single writable-key list (ConfigService,
    // the adapter, and the linktitles:set CLI all reference it): every
    // entry must be a registered definition the adapter accepts, and
    // every registered channel-scope linktitles definition must pass
    // the const-backed key check — drift in either direction throws
    // through this store round
    public function test_writable_keys_const_matches_registered_definitions(): void
    {
        foreach (linktitles_setting::WRITABLE_KEYS as $key) {
            $got = $this->store->getChannelSetting(self::$net, null, "linktitles.$key");
            $this->assertArrayHasKey('source', $got, "linktitles.$key");
        }
        foreach (array_keys(SettingsRegistry::all(scope: 'channel')) as $name) {
            if (!str_starts_with($name, 'linktitles.')) {
                continue;
            }
            $got = $this->store->getChannelSetting(self::$net, null, $name);
            $this->assertArrayHasKey('source', $got, $name);
        }
    }
}
