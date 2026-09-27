<?php

namespace library\user;

/*
 * Read-only snapshot of everything an identity engine may look at when
 * resolving a nick to a user id.
 */

final class ResolveContext
{
    public function __construct(
        public readonly int $networkId,
        public readonly string $nick,
        public readonly string $nickLowered,
        public readonly ?string $identHost,
        public readonly ?string $account,
        /** Irc\Client for engines that want to fire a lazy WHOX; typed loosely */
        public readonly object $client,
        /** auto-registration gate: engines may only create users when true */
        public readonly bool $allowCreate,
    ) {}
}
