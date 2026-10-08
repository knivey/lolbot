<?php

namespace library\settings;

/*
 * Input coercion and display formatting for setting values.
 *
 * coerce() is what a .set command runs BEFORE anything persists: a
 * refused input throws and the whole operation is abandoned (no row is
 * written). display() renders a stored/default value back to IRC.
 */
class SettingValue
{
    /** Boolean spellings accepted on the wire, lowercased before compare. */
    private const TRUE_FORMS = ['true', 'on', '1', 'yes'];
    private const FALSE_FORMS = ['false', 'off', '0', 'no'];

    /**
     * Coerce one user-supplied input string to the setting's type.
     * Strings are returned as-is (the command layer owns trimming) —
     * coercing garbage must throw rather than guess.
     *
     * @return bool|int|string
     * @throws \InvalidArgumentException when the input is not a valid
     *         value of the setting's type
     */
    public static function coerce(Setting $s, string $input): bool|int|string
    {
        return match ($s->type) {
            'bool' => self::coerceBool($s, $input),
            'int' => self::coerceInt($s, $input),
            'enum' => self::coerceEnum($s, $input),
            default => $input,
        };
    }

    /**
     * Render a value for IRC: bools as on/off, everything else through
     * a plain string cast. Values are bool|int|string by contract;
     * anything else is a caller bug worth failing loudly on (same
     * philosophy as SettingsStore::scalar()).
     */
    public static function display(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'on' : 'off';
        }
        if (is_int($v) || is_string($v) || $v instanceof \Stringable) {
            return (string) $v;
        }
        throw new \InvalidArgumentException('setting value must be bool|int|string, got ' . get_debug_type($v));
    }

    private static function coerceBool(Setting $s, string $input): bool
    {
        $lower = mb_strtolower($input);
        if (in_array($lower, self::TRUE_FORMS, true)) {
            return true;
        }
        if (in_array($lower, self::FALSE_FORMS, true)) {
            return false;
        }
        throw new \InvalidArgumentException(
            "{$s->name} expects a bool (on/off/true/false/1/0/yes/no), got '{$input}'"
        );
    }

    private static function coerceInt(Setting $s, string $input): int
    {
        $int = filter_var($input, FILTER_VALIDATE_INT);
        if ($int === false) {
            throw new \InvalidArgumentException("{$s->name} expects an int, got '{$input}'");
        }
        return $int;
    }

    private static function coerceEnum(Setting $s, string $input): string
    {
        if (!in_array($input, $s->enum_of, true)) {
            throw new \InvalidArgumentException(
                "{$s->name} expects one of (" . implode(', ', $s->enum_of) . "), got '{$input}'"
            );
        }
        return $input;
    }
}
