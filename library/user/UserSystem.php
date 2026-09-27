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

    /**
     * The bot's Channel row for a channel name on THIS network (lowered
     * compare), null when the bot isn't configured for it. Channel rows are
     * per-bot (Channels.bot FK) — that is the deliberate scoping for
     * channel_flags grants (owner decision 2026-09-27).
     */
    public function channelByName(string $chan): ?\lolbot\entities\Channel
    {
        $q = $this->em->createQuery(
            'SELECT c FROM lolbot\entities\Channel c JOIN c.bot b'
            . ' WHERE b.network = :net AND LOWER(c.name) = :name',
        );
        $q->setParameter('net', $this->network);
        $q->setParameter('name', mb_strtolower($chan));
        // getOneOrNullResult() is untyped (mixed) in doctrine/orm 3; narrow
        // for the declared return — the query can only yield Channel|null
        $result = $q->getOneOrNullResult();
        return $result instanceof \lolbot\entities\Channel ? $result : null;
    }
}
