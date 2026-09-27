<?php

namespace library\user\engines;

use library\user\Engine;
use library\user\ResolveContext;
use library\user\UserRepo;

/*
 * Resolves via network vhost patterns (e.g. GameSurge's
 * <account>.<group>.gamesurge): the host portion of the nick's
 * ident@host is matched against the network's regex and the 'name'
 * capture is treated as the account name, keyed by
 * (network, mb_strtolower(name)) like account-tag.
 *
 * The patterns map is keyed by network ID. EngineConfig::defaultPatterns()
 * ships the defaults keyed by lowered network NAME; the wiring (Task 6)
 * translates names to network ids from the Networks entity before
 * constructing this engine.
 */

class VhostPatternEngine implements Engine
{
    public const PROVENANCE = 'vhost';

    /**
     * @param array<int, string> $patterns networkId => regex with a 'name' capture
     */
    public function __construct(
        private array $patterns,
        private UserRepo $repo,
    ) {
    }

    public function resolve(ResolveContext $ctx): ?int
    {
        $identHost = $ctx->identHost;
        if ($identHost === null) {
            return null;
        }
        $pattern = $this->patterns[$ctx->networkId] ?? null;
        if ($pattern === null) {
            return null;
        }
        $at = strrpos($identHost, '@');
        if ($at === false) {
            // identHost is ident@host by construction; no '@' means we
            // cannot isolate a host to match the pattern against
            return null;
        }
        $host = substr($identHost, $at + 1);
        if (!preg_match($pattern, $host, $m)) {
            return null;
        }
        $name = $m['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return null;
        }
        $user = $this->repo->findForNetwork($ctx->networkId, mb_strtolower($name));
        if ($user !== null) {
            return (int) $user->id;
        }
        if (!$ctx->allowCreate) {
            return null;
        }
        return (int) $this->repo->createFromAccount($ctx->networkId, $name)->id;
    }
}
