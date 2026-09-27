<?php

namespace library\user;

/*
 * Pure in-memory cache of nick -> user_id bindings, one namespace per
 * network. No Doctrine, no TTLs; identity freshness is enforced by the
 * nickname lifecycle wiring (carry on NICK, drop on QUIT, flush on
 * welcome) which calls into these methods.
 */

class IdentityCache
{
    /** @var array<int, array<string, array{user_id: int, provenance: string, refreshed_at: int}>> */
    private array $bindings = [];

    /** @var callable(): int */
    private $now;

    public function __construct(?callable $now = null)
    {
        $this->now = $now ?? static fn (): int => time();
    }

    /**
     * @return array{user_id: int, provenance: string, refreshed_at: int}|null
     */
    public function get(int $netId, string $nickLowered): ?array
    {
        return $this->bindings[$netId][$nickLowered] ?? null;
    }

    public function set(int $netId, string $nickLowered, int $userId, string $provenance): void
    {
        $this->bindings[$netId][$nickLowered] = [
            'user_id' => $userId,
            'provenance' => $provenance,
            'refreshed_at' => ($this->now)(),
        ];
    }

    public function drop(int $netId, string $nickLowered): void
    {
        unset($this->bindings[$netId][$nickLowered]);
    }

    /**
     * Move a binding to a new lowered nick (NICK events). Absent source
     * binding is a no-op; an existing binding at the target nick is
     * overwritten by the carried one.
     */
    public function carryNick(int $netId, string $oldLowered, string $newLowered): void
    {
        if ($oldLowered === $newLowered) {
            // case-only renames lower to identical nicks; nothing to move
            return;
        }
        if (!isset($this->bindings[$netId][$oldLowered])) {
            return;
        }
        $this->bindings[$netId][$newLowered] = $this->bindings[$netId][$oldLowered];
        unset($this->bindings[$netId][$oldLowered]);
    }

    public function flushNetwork(int $netId): void
    {
        unset($this->bindings[$netId]);
    }
}
