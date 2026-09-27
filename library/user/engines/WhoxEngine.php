<?php

namespace library\user\engines;

use Amp\Future;
use library\user\Engine;
use library\user\ResolveContext;
use library\user\UserRepo;

/*
 * Resolves via a WHOX query (IRCv3 WHO extension): asks the server for
 * the nick's account name ('a' field) and treats it like account-tag,
 * keyed by (network, mb_strtolower(account)).
 *
 * resolve() awaits the client's whox() Future synchronously; the
 * bot only ever resolves identities inside async contexts, and the
 * Future's own timeout bounds the wait (it resolves with whatever
 * entries arrived).
 */

class WhoxEngine implements Engine
{
    public const PROVENANCE = 'whox';

    public function __construct(
        private object $client,
        private UserRepo $repo,
    ) {
    }

    public function resolve(ResolveContext $ctx): ?int
    {
        // ResolveContext types the client loosely; engines that need it
        // check the surface they call (also re-narrows for phpstan).
        // A context snapshot without the WHOX surface falls back to the
        // engine's own injected client.
        $client = $ctx->client;
        if (!method_exists($client, 'hasOption') || !method_exists($client, 'whox')) {
            $client = $this->client;
            if (!method_exists($client, 'hasOption') || !method_exists($client, 'whox')) {
                return null;
            }
        }
        if (!$client->hasOption('WHOX')) {
            return null;
        }
        $future = $client->whox($ctx->nick, 'a');
        if (!$future instanceof Future) {
            return null;
        }
        $entries = $future->await();
        if (!is_array($entries)) {
            return null;
        }
        $first = $entries[0] ?? null;
        $account = is_array($first) ? ($first['a'] ?? null) : null;
        // the client maps the logged-out sentinels 0 and * to null; the
        // engine tolerates them slipping through too
        if (!is_string($account) || $account === '' || $account === '0' || $account === '*') {
            return null;
        }
        $user = $this->repo->findForNetwork($ctx->networkId, mb_strtolower($account));
        if ($user !== null) {
            return (int) $user->id;
        }
        if (!$ctx->allowCreate) {
            return null;
        }
        return (int) $this->repo->createFromAccount($ctx->networkId, $account)->id;
    }
}
