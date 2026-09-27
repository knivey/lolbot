<?php

namespace library\user;

/*
 * Resolves nicks to user ids: cache first, then the engine chain
 * (first hit wins and is written back to the cache).
 */

class IdentityService
{
    /**
     * Service locator for the scripts/user/user.php PM commands until
     * Task 6 wires per-bot instances. Null (commands reply "user
     * system not ready") whenever the bot has not been wired.
     */
    public static ?IdentityService $instance = null;

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
}
