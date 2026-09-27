<?php

namespace library\user;

use Doctrine\ORM\EntityManager;
use lolbot\entities\Network;

/*
 * Everything one network's user system needs, handed to the bot's
 * Irc\Client (Client->userSystem) so the PM commands and the acl
 * resolver reach the RIGHT network's service even with several
 * networks wired in one process — two networks never cross-resolve
 * because each client carries its own bundle.
 */

final class UserSystem
{
    public function __construct(
        public readonly Network $network,
        public readonly IdentityService $svc,
        public readonly UserRepos $repos,
        /** exposed for the nickname lifecycle wiring (flush/carry/drop) */
        public readonly IdentityCache $cache,
        public readonly EntityManager $em,
    ) {
    }

    public function netId(): int
    {
        return $this->network->id;
    }
}
