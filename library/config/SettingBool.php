<?php
namespace lolbot\config;

/**
 * Parses boolean setting values for the *:set CLI commands. Accepts the
 * FILTER_VALIDATE_BOOLEAN forms (true/false/1/0/on/off/yes/no/y/n, "" as
 * false); anything else is rejected with an honest error message.
 */
final class SettingBool
{
    public static function parse(string $value, string $what = 'Value'): bool
    {
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed !== null) {
            return $parsed;
        }
        return match (strtolower($value)) {
            'y' => true,
            'n' => false,
            default => throw new \InvalidArgumentException("$what must be a boolean value (true/false/1/0/on/off/yes/no)"),
        };
    }
}
