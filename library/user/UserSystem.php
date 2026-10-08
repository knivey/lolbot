<?php

namespace library\user;

use Doctrine\ORM\EntityManager;
use lolbot\entities\Network;

/*
 * Everything one network's user system needs, handed to the bot's
 * Irc\Client (Client->userSystem) so the PM commands and the acl
 * resolver reach the RIGHT network's service even with several
 * networks wired in one process — two networks never cross-resolve
 * because each client carries its own bundle. The bundle is also
 * pinned to ONE bot of that network (channel rows are per-bot), so
 * two bots configured for the same channel name never cross-resolve
 * either (#139).
 */

final class UserSystem
{
    public function __construct(
        public readonly Network $network,
        public readonly \lolbot\entities\Bot $bot,
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
     * This bundle's bot's Channel row for a channel name on THIS network
     * (lowered compare), null when the bot isn't configured for it. The
     * lookup is scoped to the bundle's OWN bot, not every bot on the
     * network — Channel rows are per-bot (Channels.bot FK), so two bots
     * configured for the same channel name each resolve their own row
     * instead of tripping the one-or-null (#139) — that is the deliberate
     * scoping for channel_flags grants (owner decision 2026-09-27).
     */
    public function channelByName(string $chan): ?\lolbot\entities\Channel
    {
        $q = $this->em->createQuery(
            'SELECT c FROM lolbot\entities\Channel c JOIN c.bot b'
            . ' WHERE b.network = :net AND b = :bot AND LOWER(c.name) = :name',
        );
        $q->setParameter('net', $this->network);
        $q->setParameter('bot', $this->bot);
        $q->setParameter('name', mb_strtolower($chan));
        // getOneOrNullResult() is untyped (mixed) in doctrine/orm 3; narrow
        // for the declared return — the query can only yield Channel|null
        $result = $q->getOneOrNullResult();
        return $result instanceof \lolbot\entities\Channel ? $result : null;
    }
}
