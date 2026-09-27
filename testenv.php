<?php
// Test-environment CLI: manages throwaway run databases and generated bot
// configs for profiles in testenv/profiles/<name>.yaml.
//
//   php testenv.php <profile> up|prepare|client|reset|down
//
// This tool only needs the autoloader — it must NEVER require bootstrap.php,
// so it never boots against a real bot config or the dev database. The child
// bot process gets its config exclusively via the LOLBOT_CONFIG env prefix
// on the exec'd command (Task 1's override).

require_once __DIR__ . '/vendor/autoload.php';

use library\testenv\EnvStore;
use library\testenv\Profile;
use library\testenv\ProfileException;
use library\testenv\Seeder;

// Relative child paths below (`php lolbot.php`, `testenv/client.php`) must
// resolve, so run from the repo root no matter where the tool was invoked.
chdir(dirname(__FILE__));

function testenv_usage(int $code = 1): never
{
    fwrite(STDERR, "Usage: php testenv.php <profile> up|prepare|client|reset|down\n");
    exit($code);
}

/**
 * Path of the generated bot config for a profile
 * (testenv/run/<profile>.config.yaml, written next to the run db).
 * Derived through EnvStore::dbPath() so the profile name carries the same
 * [A-Za-z0-9_-]+ confinement check as the db path itself — `down`/`reset`
 * never unlink outside testenv/run/.
 */
function testenv_config_path(string $profile): string
{
    return dirname(EnvStore::dbPath($profile)) . '/' . $profile . '.config.yaml';
}

/**
 * Load the profile, seed a fresh run db, and write the generated config.
 * Prints the db and config paths. Returns the seed counts.
 *
 * @return array{users: int, networks: int}
 */
function testenv_prepare(string $profile): array
{
    $p = Profile::load($profile);
    $counts = Seeder::seed($p);
    $cfg = testenv_config_path($profile);
    file_put_contents($cfg, EnvStore::configFor($profile));
    printf("db: %s\n", EnvStore::dbPath($profile));
    printf("config: %s\n", $cfg);
    printf("seeded %d network(s), %d user(s)\n", $counts['networks'], $counts['users']);
    return $counts;
}

/**
 * Remove the generated config if present; a missing file is a no-op.
 */
function testenv_unlink_config(string $profile): bool
{
    $cfg = testenv_config_path($profile);
    if (is_file($cfg)) {
        unlink($cfg);
        return true;
    }
    return false;
}

/**
 * Dispatch table: command name => handler returning the process exit code.
 *
 * @var array<string, callable(string): int>
 */
$commands = [
    'prepare' => static function (string $profile): int {
        testenv_prepare($profile);
        return 0;
    },
    'up' => static function (string $profile): int {
        testenv_prepare($profile);
        // explicit `env` prefix (no putenv) so the child's environment is exact
        passthru('env LOLBOT_CONFIG=' . escapeshellarg(testenv_config_path($profile)) . ' php lolbot.php', $code);
        return $code;
    },
    'client' => static function (string $profile): int {
        // testenv/client.php is built in Task 5; until then fail friendly
        // instead of spewing a PHP file-not-found warning.
        if (!is_file(dirname(__FILE__) . '/testenv/client.php')) {
            fwrite(STDERR, "client not built yet (Task 5)\n");
            return 1;
        }
        passthru('php testenv/client.php ' . escapeshellarg($profile), $code);
        return $code;
    },
    'reset' => static function (string $profile): int {
        EnvStore::wipe($profile);
        testenv_unlink_config($profile);
        printf("reset profile '%s' (run db + generated config removed)\n", $profile);
        return 0;
    },
    'down' => static function (string $profile): int {
        echo "note: `up` runs lolbot.php in the foreground — Ctrl-C stops it, there is no daemon to take down\n";
        if (testenv_unlink_config($profile)) {
            echo "removed generated config: " . testenv_config_path($profile) . "\n";
        } else {
            echo "no generated config present\n";
        }
        return 0;
    },
];

// CLI-only tool: $_SERVER['argv'] is a list of argument strings; narrow it
// defensively instead of trusting the auto-globals' types.
$argv = $_SERVER['argv'] ?? null;
if (!is_array($argv)) {
    testenv_usage();
}
$profile = $argv[1] ?? '';
$command = $argv[2] ?? '';
if (!is_string($profile) || $profile === '' || !is_string($command) || !isset($commands[$command])) {
    testenv_usage();
}

try {
    exit($commands[$command]($profile));
} catch (ProfileException | InvalidArgumentException $e) {
    // user-facing tool: print the clean message (Profile::load names the
    // profile; EnvStore also validates profile names for path confinement)
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
