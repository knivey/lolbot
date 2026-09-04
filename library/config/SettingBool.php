<?php
namespace lolbot\config;

/**
 * Parses boolean setting values for the *:set CLI commands. Accepts
 * true/false/1/0/on/off/yes/no/y/n (case-insensitive, "" as false);
 * anything else is rejected with an honest error message.
 */
final class SettingBool
{
    private function __construct() {}

    public static function parse(string $value, string $what = 'Value'): bool
    {
        $trimmed = trim($value);
        $parsed = filter_var($trimmed, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed !== null) {
            return $parsed;
        }
        return match (strtolower($trimmed)) {
            'y' => true,
            'n' => false,
            default => throw new \InvalidArgumentException("$what must be a boolean value (true/false/1/0/on/off/yes/no)"),
        };
    }
}
