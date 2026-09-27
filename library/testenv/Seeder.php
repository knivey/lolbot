<?php

namespace library\testenv;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
use Doctrine\Migrations\Configuration\Migration\YamlFile;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use lolbot\entities\Bot;
use lolbot\entities\Channel;
use lolbot\entities\Network;
use lolbot\entities\Server;
use lolbot\entities\User;
use lolbot\entities\UserHostmask;

/**
 * Builds a fresh run database for a profile: wipes any previous file,
 * constructs a throwaway EntityManager on the run path (never the global
 * bootstrap / dev db), applies ALL migrations, then inserts the profile's
 * networks, servers, bots, channels and seed users.
 */
class Seeder
{
    /**
     * @return array{users: int, networks: int}
     */
    public static function seed(Profile $profile): array
    {
        EnvStore::wipe($profile->name());
        $em = self::entityManager($profile->name());
        try {
            self::migrate($em);

            $networks = 0;
            $users = 0;
            foreach ($profile->networks() as $net) {
                $network = self::seedNetwork($em, $net);
                $networks++;
                $users += self::seedUsers($em, $network, $profile->seedUsers(self::reqString($net, 'name')));
            }
            $em->flush();

            return ['users' => $users, 'networks' => $networks];
        } finally {
            $em->close();
        }
    }

    /**
     * EntityManager on the run db, mirroring bootstrap's entity paths.
     * Must not require bootstrap.php or touch the dev sqlite.
     */
    private static function entityManager(string $profile): EntityManager
    {
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
            'path' => EnvStore::dbPath($profile),
        ], $ormConfig);

        // match bootstrap.php's sqlite branch
        if ($conn->getDatabasePlatform()::class === SqlitePlatform::class) {
            $conn->executeStatement('PRAGMA foreign_keys=ON');
        }

        return new EntityManager($conn, $ormConfig);
    }

    /**
     * Apply every migration via the doctrine-migrations machinery
     * (same recipe as bootstrap/admin-cli, proven against pg in scratch).
     */
    private static function migrate(EntityManager $em): void
    {
        $root = dirname(__DIR__, 2);
        $df = DependencyFactory::fromEntityManager(
            new YamlFile($root . '/migrations.yml'),
            new ExistingEntityManager($em)
        );
        $df->getMetadataStorage()->ensureInitialized();
        $plan = $df->getMigrationPlanCalculator()->getPlanUntilVersion(
            $df->getVersionAliasResolver()->resolveVersionAlias('latest')
        );
        $df->getMigrator()->migrate($plan, new MigratorConfiguration());
    }

    /**
     * @param array<string, mixed> $net
     */
    private static function seedNetwork(EntityManager $em, array $net): Network
    {
        $network = new Network();
        $network->name = self::reqString($net, 'name');
        $network->auth_engines = self::optStringList($net, 'auth_engines');
        $network->admin_hostmask_auth = self::optBool($net, 'admin_hostmask_auth');
        $em->persist($network);

        foreach (self::listOf($net, 'servers') as $srv) {
            $server = new Server();
            $server->address = self::reqString($srv, 'address');
            $server->port = self::optInt($srv, 'port', 6667);
            $server->ssl = self::optBool($srv, 'ssl');
            $server->throttle = self::optBool($srv, 'throttle', true);
            $server->password = self::optString($srv, 'password');
            $server->setNetwork($network);
            $em->persist($server);
        }

        foreach (self::listOf($net, 'bots') as $botSpec) {
            $bot = new Bot();
            $bot->name = self::reqString($botSpec, 'name');
            $bot->trigger = self::optString($botSpec, 'trigger');
            $bot->sasl_user = self::optString($botSpec, 'sasl_user');
            $bot->sasl_pass = self::optString($botSpec, 'sasl_pass');
            $bot->onConnect = self::optString($botSpec, 'on_connect') ?? '';
            // addBot() only appends to the inverse collection; the owning
            // side must be set explicitly (same as ConfigService)
            $bot->network = $network;
            $network->addBot($bot);
            $em->persist($bot);

            foreach (self::optStringList($botSpec, 'channels') ?? [] as $chanName) {
                $chan = new Channel();
                $chan->name = $chanName;
                $bot->addChannel($chan);
                $em->persist($chan);
            }
        }

        // materialize the generated network id for the users' network_id
        $em->flush();
        return $network;
    }

    /**
     * @param array<int, array<string, mixed>> $seedUsers
     */
    private static function seedUsers(EntityManager $em, Network $network, array $seedUsers): int
    {
        $count = 0;
        foreach ($seedUsers as $entry) {
            $user = new User();
            $user->network_id = $network->id;
            $user->name = self::reqString($entry, 'name');
            $user->nameLowered = mb_strtolower($user->name);
            $user->flags = self::optStringList($entry, 'flags') ?? [];
            $user->paranoid = self::optBool($entry, 'paranoid');
            $em->persist($user);

            // materialize the generated user id before wiring hostmasks
            $em->flush();
            foreach (self::optStringList($entry, 'hostmasks') ?? [] as $mask) {
                $hostmask = new UserHostmask();
                $hostmask->user_id = $user->id;
                $hostmask->mask = $mask;
                $hostmask->addedBy = 'seeder';
                $em->persist($hostmask);
            }
            $count++;
        }
        return $count;
    }

    /**
     * @param array<string, mixed> $arr
     * @return array<int, array<string, mixed>>
     */
    private static function listOf(array $arr, string $key): array
    {
        $list = $arr[$key] ?? null;
        if (!is_array($list)) {
            return [];
        }
        /** @var array<int, array<string, mixed>> $list */
        return array_values($list);
    }

    /**
     * @param array<string, mixed> $arr
     */
    private static function reqString(array $arr, string $key): string
    {
        $val = $arr[$key] ?? null;
        if (!is_string($val) || $val === '') {
            throw new \InvalidArgumentException(sprintf("expected non-empty string for key '%s'", $key));
        }
        return $val;
    }

    /**
     * @param array<string, mixed> $arr
     * @return list<string>|null
     */
    private static function optStringList(array $arr, string $key): ?array
    {
        $list = $arr[$key] ?? null;
        if ($list === null) {
            return null;
        }
        if (!is_array($list)) {
            return null;
        }
        /** @var list<string> $strings */
        $strings = array_values(array_filter($list, 'is_string'));
        return $strings;
    }

    /**
     * @param array<string, mixed> $arr
     */
    private static function optString(array $arr, string $key): ?string
    {
        $val = $arr[$key] ?? null;
        return is_string($val) ? $val : null;
    }

    /**
     * @param array<string, mixed> $arr
     */
    private static function optBool(array $arr, string $key, bool $default = false): bool
    {
        $val = $arr[$key] ?? null;
        return is_bool($val) ? $val : $default;
    }

    /**
     * @param array<string, mixed> $arr
     */
    private static function optInt(array $arr, string $key, int $default): int
    {
        $val = $arr[$key] ?? null;
        return is_int($val) ? $val : $default;
    }
}
