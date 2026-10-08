<?php
use library\testenv\EnvStore;
use library\testenv\Profile;
use library\testenv\Seeder;
use PHPUnit\Framework\TestCase;

class SeederTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        EnvStore::wipe('fixture_test');
    }

    public function test_db_path_is_confined_and_validated(): void
    {
        $this->assertStringEndsWith('testenv/run/fixture_test.sqlite', EnvStore::dbPath('fixture_test'));
        $this->expectException(\InvalidArgumentException::class);
        EnvStore::dbPath('../evil');
    }

    public function test_seed_migrates_then_inserts(): void
    {
        $p = Profile::load('fixture_test');
        $counts = Seeder::seed($p);
        $this->assertSame(3, $counts['networks']); // fixture_test has 3 networks
        // query the run db directly:
        $pdo = new PDO('sqlite:' . EnvStore::dbPath('fixture_test'));
        $migrated = $pdo->query("SELECT COUNT(*) FROM doctrine_migration_versions")->fetchColumn(); // @phpstan-ignore-line
        $this->assertGreaterThan(0, (int)$migrated);
        $users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(); // @phpstan-ignore-line
        $this->assertSame(3, (int)$users); // Tester (default) + Helper + ShyOne; net3 opted out
        $admin = $pdo->query("SELECT flags FROM users WHERE name = 'Tester'")->fetchColumn(); // @phpstan-ignore-line
        $this->assertSame('["admin"]', $admin);
        $bots = $pdo->query("SELECT COUNT(*) FROM Bots")->fetchColumn(); // @phpstan-ignore-line
        $this->assertSame(3, (int)$bots);
        $chans = $pdo->query("SELECT COUNT(*) FROM Channels")->fetchColumn(); // @phpstan-ignore-line
        $this->assertSame(1, (int)$chans);
        // issue #142 item 5: Helper's declared hostmasks materialize as
        // UserHostmask rows — linked to the right user, attributed to the
        // seeder (ORDER BY mask: '*' sorts before 'H' in BINARY collation)
        $masks = $pdo->query(
            "SELECT m.mask, m.added_by, u.name AS user_name"
            . " FROM user_hostmasks m JOIN users u ON u.id = m.user_id"
            . " WHERE u.name = 'Helper' ORDER BY m.mask"
        )->fetchAll(PDO::FETCH_ASSOC); // @phpstan-ignore-line
        $this->assertSame([
            ['mask' => '*!helper@irc2.fixture.example', 'added_by' => 'seeder', 'user_name' => 'Helper'],
            ['mask' => 'Helper!h@irc2.fixture.example', 'added_by' => 'seeder', 'user_name' => 'Helper'],
        ], $masks);
    }

    public function test_seed_rejects_scalar_auth_engines(): void
    {
        // issue #142 item 3: a typo'd scalar must fail fast instead of
        // silently seeding empty auth engines
        $p = new Profile('seeder_bad_scalar', [
            'driver' => ['nick' => 'Tester'],
            'networks' => [
                ['name' => 'BadNet', 'auth_engines' => 'account-tag',
                    'servers' => [['address' => 'irc.bad.example']],
                    'bots' => [['name' => 'BadBot']]],
            ],
        ]);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage("Seeder: 'auth_engines' must be a list of strings");
            Seeder::seed($p);
        } finally {
            EnvStore::wipe('seeder_bad_scalar');
        }
    }

    public function test_seed_rejects_non_string_list_entry(): void
    {
        // same fail-fast for a list holding a non-string element (flags)
        $p = new Profile('seeder_bad_entry', [
            'driver' => ['nick' => 'Tester'],
            'networks' => [
                ['name' => 'BadNet',
                    'servers' => [['address' => 'irc.bad.example']],
                    'bots' => [['name' => 'BadBot']],
                    'seed_users' => [['name' => 'TypoUser', 'flags' => ['admin', 5]]]],
            ],
        ]);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage("Seeder: 'flags' must be a list of strings");
            Seeder::seed($p);
        } finally {
            EnvStore::wipe('seeder_bad_entry');
        }
    }

    public function test_wipe_clears(): void
    {
        // capture the path first — dbPath() now ensures the db file exists
        // (touch + 0600), so asking for it inside the assertion would
        // recreate the file wipe() just removed
        $path = EnvStore::dbPath('fixture_test');
        EnvStore::wipe('fixture_test');
        $this->assertFileDoesNotExist($path);
    }
}
