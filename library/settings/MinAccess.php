<?php

namespace library\settings;

/*
 * The minmode channel setting (#128): the minimum channel status a
 * caller must hold for restricted commands to run in that channel.
 * Values are the IRC PREFIX symbols ('' anyone, '+' voice, '%'
 * halfop, '@' op, '&' admin, '~' owner); the tiered read (channel
 * row over network row) replaces the old per-network YAML keys
 * (codesandMinAccess / artMinAccess) with '.set minmode <mode>' in
 * the channel and '.set --net minmode <mode>' network-wide.
 */
final class MinAccess
{
    /** Recognized modes, weakest to strongest ('' = the open gate). */
    public const MODES = ['', '+', '%', '@', '&', '~'];

    public static function valid(string $mode): bool
    {
        return in_array($mode, self::MODES, true);
    }

    /**
     * Whether $nick holds at least the $mode status on $chan. '' always
     * passes; an unrecognized mode is a caller bug (a corrupt store row
     * reaching here) and throws rather than silently opening the gate.
     */
    public static function met(\Nicks $nicks, string $nick, string $chan, string $mode): bool
    {
        return match ($mode) {
            '' => true,
            '+' => $nicks->isVoiceOrHigher($nick, $chan),
            '%' => $nicks->isHalfOpOrHigher($nick, $chan),
            '@' => $nicks->isOpOrHigher($nick, $chan),
            '&' => $nicks->isAdminOrHigher($nick, $chan),
            '~' => $nicks->isOwner($nick, $chan),
            default => throw new \InvalidArgumentException("unknown minmode '{$mode}'"),
        };
    }

    /**
     * Register the setting definition. Unguarded on purpose, matching
     * linktitles_register_settings(): double registration without an
     * intervening SettingsRegistry::reset() fails loudly instead of
     * silently dropping the (empty) storage binding.
     */
    public static function defineSetting(): void
    {
        SettingsRegistry::define(new Setting(
            'minmode', type: 'enum', default: '', scope: 'channel', flag: 'admin',
            enum_of: self::MODES,
            description: 'minimum channel status to run restricted commands (empty = anyone)',
        ));
    }
}
