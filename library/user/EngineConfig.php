<?php

namespace library\user;

/*
 * Resolves the engine chain for a network: either the pinned
 * auth_engines list from config (honored exactly, unknown names are a
 * configuration error) or the auto-detect order filtered by the
 * capabilities the network actually advertised.
 */

class EngineConfig
{
    private const AUTO_ORDER = ['account-tag', 'vhost', 'whox', 'hostmask', 'manual'];

    /**
     * @param list<string>|null $authEngines pinned engine names, null to auto-detect
     * @param array<string, bool> $caps capability flags ('account-tag' hasCap, 'vhost' patterns configured, 'whox' isupport)
     * @return list<string>
     * @throws \UnexpectedValueException when a pinned engine name is unknown
     */
    public static function chain(?array $authEngines, array $caps): array
    {
        if ($authEngines !== null) {
            foreach ($authEngines as $name) {
                if (!in_array($name, self::AUTO_ORDER, true)) {
                    throw new \UnexpectedValueException("unknown auth engine '$name'");
                }
            }
            return $authEngines;
        }
        $chain = [];
        foreach (self::AUTO_ORDER as $name) {
            $enabled = match ($name) {
                'account-tag', 'vhost', 'whox' => !empty($caps[$name]),
                default => true,
            };
            if ($enabled) {
                $chain[] = $name;
            }
        }
        return $chain;
    }

    /**
     * Default vhost patterns, keyed by lowered network name.
     * @return array<string, string>
     */
    public static function defaultPatterns(): array
    {
        return ['gamesurge' => '/^(?P<name>[^.]+)\.[^.]+\.gamesurge$/i'];
    }
}
