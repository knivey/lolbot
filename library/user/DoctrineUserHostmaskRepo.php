<?php

namespace library\user;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query\Expr\Join;
use lolbot\entities\User as UserEntity;
use lolbot\entities\UserHostmask as UserHostmaskEntity;

/*
 * Doctrine-backed UserHostmaskRepo. SQL globs are not regex globs and
 * Doctrine has no LIKE-free glob helper, so the repo intentionally
 * OVER-RETURNS: every mask row of every user on the network is handed
 * back as a candidate and HostmaskEngine does the final globToRegex
 * match (the UserHostmaskRepo interface contract explicitly allows
 * this). Masks are per-network via the owning user's network_id.
 */

final class DoctrineUserHostmaskRepo implements UserHostmaskRepo
{
    public function __construct(private EntityManager $em)
    {
    }

    /**
     * @param int $netId network id
     * @param string $identHost the ident@host portion (no nick)
     * @return list<array{mask: string, user_id: int, paranoid: bool}>
     */
    public function findForHost(int $netId, string $identHost): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('m.mask', 'm.user_id', 'u.paranoid')
            ->from(UserHostmaskEntity::class, 'm')
            ->innerJoin(UserEntity::class, 'u', Join::WITH, 'u.id = m.user_id')
            ->where('u.network_id = :netId')
            ->setParameter('netId', $netId)
            ->getQuery()
            ->getArrayResult();
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mask = $row['mask'] ?? null;
            $userId = $row['user_id'] ?? null;
            $paranoid = $row['paranoid'] ?? null;
            // paranoid may hydrate as bool or as 0/1 depending on driver
            if (is_string($mask) && is_int($userId) && (is_bool($paranoid) || is_int($paranoid))) {
                $out[] = ['mask' => $mask, 'user_id' => $userId, 'paranoid' => (bool) $paranoid];
            }
        }
        return $out;
    }

    public function deleteForUser(int $userId): void
    {
        $this->em->createQueryBuilder()
            ->delete(UserHostmaskEntity::class, 'm')
            ->where('m.user_id = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }
}
