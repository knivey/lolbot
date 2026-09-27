<?php

namespace library\user;

/*
 * Resolves nicks to user ids: cache first, then the engine chain
 * (first hit wins and is written back to the cache).
 */

class IdentityService
{
    /** @var list<Engine> */
    private array $engines;

    /**
     * @param list<Engine> $engines
     * @param callable(): int|null $now accepted to keep the wiring signature
     *   stable; the cache owns the clock that stamps refreshed_at
     */
    public function __construct(private IdentityCache $cache, array $engines, ?callable $now = null) // @phpstan-ignore constructor.unusedParameter (pinned signature)
    {
        $this->engines = $engines;
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
