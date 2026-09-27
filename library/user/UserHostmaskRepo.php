<?php

namespace library\user;

/*
 * Storage backend for admin-managed hostmask globs used by the
 * hostmask engine. Implemented by Doctrine in wiring (Task 6) and by
 * fakes in tests.
 */

interface UserHostmaskRepo
{
    /**
     * Candidate masks for a host on a network. The engine matches each
     * mask against the full nick!ident@host string itself, so repos may
     * prefilter freely (e.g. host LIKE) as long as every mask that
     * could match the ident@host is returned. The paranoid flag says
     * whether the owning user has paranoid on — the engine skips those
     * rows (paranoid users must auth manually each connect, so their
     * stored masks never resolve).
     *
     * @param int $netId network id
     * @param string $identHost the ident@host portion (no nick)
     * @return list<array{mask: string, user_id: int, paranoid: bool}>
     */
    public function findForHost(int $netId, string $identHost): array;

    /**
     * Delete every stored mask owned by a user. The paranoid PM command
     * calls this when turning paranoid on so no leftover mask keeps
     * resolving on the user's next connect.
     */
    public function deleteForUser(int $userId): void;
}
