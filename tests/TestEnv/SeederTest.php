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
    }

    public function test_wipe_clears(): void
    {
        EnvStore::wipe('fixture_test');
        $this->assertFileDoesNotExist(EnvStore::dbPath('fixture_test'));
    }
}
