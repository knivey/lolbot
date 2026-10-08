<?php

namespace library\settings;

/**
 * Central registry of every known setting definition.
 *
 * Definitions come from two places:
 *  - programmatic define() calls (optionally with a SettingStorage adapter
 *    for scripts that keep their own tables)
 *  - the #[Setting] attribute on declared user functions, picked up by
 *    loadAttributeSettings() (attribute-defined settings never carry storage)
 */
class SettingsRegistry
{
    /** @var array<string, Setting> */
    private static array $definitions = [];

    /** @var array<string, SettingStorage> */
    private static array $storages = [];

    private static bool $scanned = false;

    public static function define(Setting $setting, ?SettingStorage $storage = null): void
    {
        if (isset(self::$definitions[$setting->name])) {
            throw new \InvalidArgumentException("Setting already defined: {$setting->name}");
        }
        self::$definitions[$setting->name] = $setting;
        if ($storage !== null) {
            self::$storages[$setting->name] = $storage;
        }
    }

    public static function attachStorage(string $name, SettingStorage $storage): void
    {
        if (!isset(self::$definitions[$name])) {
            throw new \InvalidArgumentException("Unknown setting: {$name}");
        }
        self::$storages[$name] = $storage;
    }

    public static function get(string $name): ?Setting
    {
        self::loadAttributeSettings();
        return self::$definitions[$name] ?? null;
    }

    public static function storage(string $name): ?SettingStorage
    {
        self::loadAttributeSettings();
        return self::$storages[$name] ?? null;
    }

    /**
     * All definitions, optionally filtered by scope and/or irc visibility.
     *
     * Reads lazily trigger the attribute scan so attribute-defined settings
     * are visible without an explicit loadAttributeSettings() call.
     *
     * @return array<string, Setting> name => Setting, insertion order
     */
    public static function all(?string $scope = null, ?bool $irc = null): array
    {
        self::loadAttributeSettings();
        return array_filter(self::$definitions, function (Setting $s) use ($scope, $irc): bool {
            if ($scope !== null && $s->scope !== $scope) {
                return false;
            }
            if ($irc !== null && $s->irc !== $irc) {
                return false;
            }
            return true;
        });
    }

    /**
     * Define every #[Setting]-attributed declared user function.
     *
     * Idempotent: names already defined (programmatically or by a previous
     * scan) are skipped so double calls and pre-existing defines are safe.
     *
     * The latch is permanent: functions declared after the first scan are
     * not picked up; call this only after all script files are loaded.
     */
    public static function loadAttributeSettings(): void
    {
        if (self::$scanned) {
            return;
        }
        foreach (get_defined_functions()['user'] as $function) {
            foreach ((new \ReflectionFunction($function))->getAttributes(Setting::class) as $attribute) {
                /** @var Setting $setting */
                $setting = $attribute->newInstance();
                if (isset(self::$definitions[$setting->name])) {
                    continue;
                }
                self::$definitions[$setting->name] = $setting;
            }
        }
        self::$scanned = true;
    }

    public static function reset(): void
    {
        self::$definitions = [];
        self::$storages = [];
        self::$scanned = false;
    }
}
