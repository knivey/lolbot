<?php

namespace library\user;

/*
 * Storage backend for per-channel flag grants. Implemented by Doctrine
 * in wiring and by fakes in tests.
 */

interface ChannelFlagRepo
{
    /**
     * Grant row for one user in one channel (exact match on the unique
     * index); null when the user holds nothing in the channel.
     *
     * @return object{id: int, flags: list<mixed>}|null
     */
    public function findForChannelUser(int $channelId, int $userId): ?object;
}
