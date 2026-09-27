<?php

namespace library\testenv;

/**
 * Owns the per-profile run database files under testenv/run/: path
 * resolution with strict profile-name validation, wiping, and generated
 * bot config text pointing at the run db.
 */
class EnvStore
{
    private static function baseDir(): string
    {
        // library/testenv -> repo root -> testenv/run
        return dirname(__DIR__, 2) . '/testenv/run';
    }

    /**
     * Absolute path of the run db for a profile. The profile name is
     * restricted to [A-Za-z0-9_-]+ so the returned path can never escape
     * testenv/run/. Creates the run directory on demand.
     */
    public static function dbPath(string $profile): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $profile)) {
            throw new \InvalidArgumentException(
                sprintf("profile name '%s' is invalid (allowed: letters, digits, '_', '-')", $profile)
            );
        }
        $dir = self::baseDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir . '/' . $profile . '.sqlite';
    }

    /**
     * Remove the run db for a profile; a missing file is a no-op.
     */
    public static function wipe(string $profile): void
    {
        $path = self::dbPath($profile);
        if (file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * YAML text for a bot config whose database key targets this run db
     * (written by the harness to testenv/run/<profile>.config.yaml).
     */
    public static function configFor(string $profile): string
    {
        return "database:\n  driver: pdo_sqlite\n  path: " . self::dbPath($profile) . "\n";
    }
}
