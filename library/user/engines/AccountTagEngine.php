<?php

namespace library\user\engines;

use library\user\Engine;
use library\user\ResolveContext;
use library\user\UserRepo;

/*
 * Resolves via the ircv3 account-tag / extended-join account name:
 * users are keyed by (network, mb_strtolower(account)).
 */

class AccountTagEngine implements Engine
{
    public const PROVENANCE = 'account-tag';

    public function __construct(private UserRepo $repo) {}

    public function resolve(ResolveContext $ctx): ?int
    {
        $account = $ctx->account;
        if ($account === null || $account === '') {
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
