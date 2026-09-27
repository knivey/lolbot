<?php

namespace library\user;

use library\user\engines\AccountTagEngine;

/*
 * Resolves nicks to user ids: cache first, then the engine chain
 * (first hit wins and is written back to the cache).
 */

class IdentityService
{
    /**
     * Per-network service locator keyed by network id, kept current by
     * UserSystemFactory::create(). Bots are per-network and several
     * networks can be wired in one process, so a single static would
     * cross-resolve; the real path is the client's UserSystem bundle
     * (Client->userSystem), this map is the registry/back-compat
     * surface. Empty whenever no network is wired.
     *
     * @var array<int, IdentityService>
     */
    public static array $instances = [];

    public static function forNetwork(int $netId): ?self
    {
        return self::$instances[$netId] ?? null;
    }

    /** @var list<Engine> */
    private array $engines;

    /**
     * @param list<Engine> $engines
     */
    public function __construct(private IdentityCache $cache, array $engines)
    {
        $this->engines = $engines;
    }

    /**
     * Provenance names of the wired engine chain, in chain order. The
     * register command uses this to refuse password registration on
     * services-capable networks: an account-tag/whox chain means
     * identities are automatic, and a password row created under a
     * stranger's future services name would squat it.
     *
     * @return list<string>
     */
    public function engineNames(): array
    {
        return array_map(static fn (Engine $engine): string => $engine::PROVENANCE, $this->engines);
    }

    /**
     * Force a binding for a nick (manual auth path): the auth/register
     * commands verify the password and call this with the manual
     * provenance instead of going through the engine chain.
     */
    public function bind(int $netId, string $nickLowered, int $userId, string $provenance): void
    {
        $this->cache->set($netId, $nickLowered, $userId, $provenance);
    }

    /**
     * Read the cached binding for a nick without consulting the engine
     * chain (the pass/paranoid commands gate on an existing manual
     * binding; running engines there could auto-register strangers).
     *
     * @return array{user_id: int, provenance: string, refreshed_at: int}|null
     */
    public function binding(int $netId, string $nickLowered): ?array
    {
        return $this->cache->get($netId, $nickLowered);
    }

    /**
     * @return array{user_id: int, provenance: string}|null
     */
    public function resolve(ResolveContext $ctx): ?array
    {
        $hit = $this->cache->get($ctx->networkId, $ctx->nickLowered);
        if ($hit !== null) {
            $upgraded = $this->upgradeToAccountTag($ctx, $hit['provenance']);
            if ($upgraded !== null) {
                return $upgraded;
            }
            return ['user_id' => $hit['user_id'], 'provenance' => $hit['provenance']];
        }
        foreach ($this->engines as $engine) {
            $uid = $engine->resolve($ctx);
            if ($uid === null) {
                continue;
            }
            $this->cache->set($ctx->networkId, $ctx->nickLowered, $uid, $engine::PROVENANCE);
            return ['user_id' => $uid, 'provenance' => $engine::PROVENANCE];
        }
        return null;
    }

    /**
     * Cache-hit refresh path: the account-tag engine is the
     * authoritative identity on services networks, so a cached binding
     * from a weaker engine (hostmask, vhost, manual) must not keep
     * shadowing it — an over-broad stored mask that bound the nick
     * keeps resolving even after the user logs in to services. When
     * the context carries a services account and the cached
     * provenance is not account-tag, run the account-tag engine once
     * and rebind the cache on a hit. Cost: at most one extra engine
     * pass per nick per session — once rebound the provenance is
     * account-tag and this path skips. A miss keeps the cached
     * binding; a chain without the account-tag engine has nothing to
     * upgrade to.
     *
     * @return array{user_id: int, provenance: string}|null
     */
    private function upgradeToAccountTag(ResolveContext $ctx, string $cachedProvenance): ?array
    {
        if ($ctx->account === null || $cachedProvenance === AccountTagEngine::PROVENANCE) {
            return null;
        }
        foreach ($this->engines as $engine) {
            if ($engine::PROVENANCE !== AccountTagEngine::PROVENANCE) {
                continue;
            }
            $uid = $engine->resolve($ctx);
            if ($uid === null) {
                return null;
            }
            $this->cache->set($ctx->networkId, $ctx->nickLowered, $uid, AccountTagEngine::PROVENANCE);
            return ['user_id' => $uid, 'provenance' => AccountTagEngine::PROVENANCE];
        }
        return null;
    }
}
