<?php

namespace library\user;

/*
 * Storage backend the identity engines resolve against. Implemented by
 * Doctrine in wiring (Task 6) and by fakes in tests.
 */

interface UserRepo
{
    /**
     * @return object{id: int}|null
     */
    public function findForNetwork(int $netId, string $nameLowered): ?object;

    /**
     * @return object{id: int}
     */
    public function createFromAccount(int $netId, string $account): object;
}
