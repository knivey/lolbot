<?php
// library/user/Flags.php
namespace library\user;

/*
 * Code-defined flag registry: flags are ACL names, optionally granting
 * other flags (groups). 'admin' grants '*' (everything). Definitions
 * live in code — admins GRANT flags, they don't invent them; scripts
 * may register their own via Flags::define() at load time.
 *
 * Check semantics (spec 2026-09-27): a check for flag X passes when
 * the holder's flag set (network flags UNION channel grants — the
 * fall-through) expanded through groups contains X or '*'.
 */

final class Flags
{
    /** @var array<string, list<string>> */
    public const DEFAULTS = [
        'admin' => ['*'],
    ];

    /** @var array<string, list<string>>|null */
    private static ?array $definitions = null;

    public static function defined(string $flag): bool
    {
        return array_key_exists($flag, self::definitions());
    }

    /** @return array<string, list<string>> */
    public static function definitions(): array
    {
        if (self::$definitions === null) {
            self::$definitions = self::DEFAULTS;
        }
        return self::$definitions;
    }

    /**
     * Non-string grants and '' are dropped (empty grants never surface in expand()).
     *
     * @param list<string> $grants
     */
    public static function define(string $name, array $grants): void
    {
        self::definitions(); // ensure initialized before write
        // drop non-strings ('is_string' callable keeps phpstan from
        // second-guessing the defensive check), then drop '' grants
        self::$definitions[$name] = array_values(array_filter(
            array_filter($grants, 'is_string'),
            fn ($g) => $g !== '',
        ));
    }

    /**
     * One place flag lists are formatted for display — every user-facing
     * flag list (user:flags, PM setflags, .cflags) renders through this.
     *
     * @param array<int, string> $flags
     */
    public static function formatList(array $flags): string
    {
        return implode(', ', $flags);
    }

    public static function reset(): void
    {
        self::$definitions = self::DEFAULTS;
    }

    /**
     * Transitive group expansion, cycle-guarded. Non-strings filtered,
     * order preserved (first-seen), '*' kept literally.
     *
     * @param array<int, mixed> $flags
     * @return list<string>
     */
    public static function expand(array $flags): array
    {
        $set = [];
        foreach ($flags as $f) {
            if (is_string($f) && $f !== '' && !isset($set[$f])) {
                $set[$f] = true;
            }
        }
        $defs = self::definitions();
        // worklist: a flag already-expanded never re-expands (cycle guard)
        $expanded = [];
        $queue = array_keys($set);
        while ($queue !== []) {
            $f = array_shift($queue);
            if (isset($expanded[$f])) {
                continue;
            }
            $expanded[$f] = true;
            foreach ($defs[$f] ?? [] as $granted) {
                if (!isset($set[$granted])) {
                    $set[$granted] = true;
                    $queue[] = $granted;
                }
            }
        }
        return array_keys($set);
    }

    /**
     * @param array<int, mixed> $flags
     */
    public static function passes(array $flags, string $flag): bool
    {
        $expanded = self::expand($flags);
        return in_array('*', $expanded, true) || in_array($flag, $expanded, true);
    }
}
