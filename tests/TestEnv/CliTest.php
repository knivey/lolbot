<?php
// tests/TestEnv/CliTest.php — subprocess-driven tests for the testenv.php CLI
use library\testenv\EnvStore;
use PHPUnit\Framework\TestCase;

class CliTest extends TestCase
{
    protected function tearDown(): void
    {
        // failed assertions must not leak run artifacts (run db + generated config)
        $this->runCli(['fixture_test', 'reset']);
    }

    /**
     * Run testenv.php as a subprocess; stderr is merged into stdout.
     *
     * @param array<int, string> $argv
     * @return array{0: string, 1: int}
     */
    private function runCli(array $argv): array
    {
        $cmd = 'php ' . escapeshellarg(dirname(__DIR__, 2) . '/testenv.php')
            . ' ' . implode(' ', array_map('escapeshellarg', $argv)) . ' 2>&1';
        exec($cmd, $out, $code);
        return [implode("\n", $out), $code];
    }

    public function test_no_args_usage(): void
    {
        [$out, $code] = $this->runCli([]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Usage:', $out);
    }

    public function test_unknown_profile_exits_1_with_message(): void
    {
        [$out, $code] = $this->runCli(['nope', 'prepare']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString("nope", $out);
    }

    public function test_prepare_creates_db_and_config_and_boots_bootstrap(): void
    {
        [$out, $code] = $this->runCli(['fixture_test', 'prepare']);
        $this->assertSame(0, $code, $out);
        $cfg = dirname(__DIR__, 2) . '/testenv/run/fixture_test.config.yaml';
        $this->assertFileExists($cfg);
        // Review Focus #4: the generated config boots bootstrap in a subprocess.
        // bootstrap.php uses relative paths internally (vendor/autoload.php,
        // migrations.yml), so the child must run from the repo root cwd.
        // restore to the repo root if getcwd() somehow failed (harmless here)
        $cwd = getcwd() ?: dirname(__DIR__, 2);
        chdir(dirname(__DIR__, 2));
        try {
            exec('LOLBOT_CONFIG=' . escapeshellarg($cfg) . ' php -r '
                . escapeshellarg('require "bootstrap.php"; global $entityManager; $entityManager->getConnection()->connect(); echo "BOOTOK";'),
                $bootOut, $bootCode);
        } finally {
            chdir($cwd);
        }
        $this->assertSame(0, $bootCode, implode("\n", $bootOut));
        $this->assertStringContainsString('BOOTOK', implode("\n", $bootOut));
        // cleanup happens in tearDown() so failed assertions can't leak artifacts
    }

    /**
     * The config path must be as confined as the db path: a profile name
     * with path characters may never make `down` unlink outside testenv/run/.
     */
    public function test_down_refuses_unconfined_profile_names(): void
    {
        // one directory above testenv/run/ — where '../evil_probe' used to land
        $sentinel = dirname(__DIR__, 2) . '/testenv/evil_probe.config.yaml';
        file_put_contents($sentinel, "sentinel\n");
        try {
            [$out, $code] = $this->runCli(['../evil_probe', 'down']);
            $this->assertSame(1, $code, $out);
            $this->assertStringContainsString('../evil_probe', $out);
            $this->assertFileExists($sentinel);
        } finally {
            if (is_file($sentinel)) {
                unlink($sentinel);
            }
        }
    }

    public function test_reset_refuses_unconfined_profile_names(): void
    {
        // same invariant via reset (EnvStore::wipe rejects the name first)
        $sentinel = dirname(__DIR__, 2) . '/testenv/evil_probe.config.yaml';
        file_put_contents($sentinel, "sentinel\n");
        try {
            [$out, $code] = $this->runCli(['../evil_probe', 'reset']);
            $this->assertSame(1, $code, $out);
            $this->assertStringContainsString('../evil_probe', $out);
            $this->assertFileExists($sentinel);
        } finally {
            if (is_file($sentinel)) {
                unlink($sentinel);
            }
        }
    }

    /**
     * Issue #146: reset/down used to leave a 0-byte sqlite behind —
     * unlink_config resolves the config path through EnvStore::dbPath()
     * (create mode), which re-touches the run db that was just removed.
     * The end state of both commands must be: no sqlite file at all.
     */
    public function test_reset_leaves_no_run_db_artifact(): void
    {
        // seed a real db + generated config first so reset has something
        // to remove (and tearDown re-resets regardless of assertion outcome)
        [$out, $code] = $this->runCli(['fixture_test', 'prepare']);
        $this->assertSame(0, $code, $out);
        [$out, $code] = $this->runCli(['fixture_test', 'reset']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString("reset profile 'fixture_test'", $out);
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root . '/testenv/run/fixture_test.sqlite');
        $this->assertFileDoesNotExist($root . '/testenv/run/fixture_test.config.yaml');
    }

    public function test_down_leaves_no_run_db_artifact(): void
    {
        // db gone but config present: down's config removal re-touches the
        // db path via testenv_config_path() — that artifact must be cleared
        $root = dirname(__DIR__, 2);
        EnvStore::wipe('fixture_test');
        file_put_contents($root . '/testenv/run/fixture_test.config.yaml', "stale\n");
        [$out, $code] = $this->runCli(['fixture_test', 'down']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('removed generated config', $out);
        $this->assertFileDoesNotExist($root . '/testenv/run/fixture_test.sqlite');
        $this->assertFileDoesNotExist($root . '/testenv/run/fixture_test.config.yaml');
    }
}
