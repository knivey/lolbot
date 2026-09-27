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
     * could match the ident@host is returned.
     *
     * @param int $netId network id
     * @param string $identHost the ident@host portion (no nick)
     * @return list<array{mask: string, user_id: int}>
     */
    public function findForHost(int $netId, string $identHost): array;
}
