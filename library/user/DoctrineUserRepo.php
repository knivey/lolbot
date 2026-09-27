<?php

namespace library\user;

use Doctrine\ORM\EntityManager;
use lolbot\entities\User as UserEntity;

/*
 * Doctrine-backed UserRepo: lookups hit the (network_id, nameLowered)
 * unique index exactly; services auto-registration creates the row with
 * the account name as seen on the wire and its mb_strtolower twin, so
 * creation and lookup can never disagree on casing.
 *
 * Concurrent creations of the same account race on the unique index;
 * the loser's flush throws out of resolve (a command error notice), it
 * does not corrupt state.
 */

final class DoctrineUserRepo implements UserRepo
{
    public function __construct(private EntityManager $em)
    {
    }

    /**
     * @return object{id: int}|null
     */
    public function findForNetwork(int $netId, string $nameLowered): ?object
    {
        return $this->em->getRepository(UserEntity::class)->findOneBy([
            'network_id' => $netId,
            'nameLowered' => $nameLowered,
        ]);
    }

    /**
     * @return object{id: int}
     */
    public function createFromAccount(int $netId, string $account): object
    {
        $user = new UserEntity();
        $user->network_id = $netId;
        $user->name = $account;
        $user->nameLowered = mb_strtolower($account);
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }
}
