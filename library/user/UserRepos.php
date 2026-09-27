<?php

namespace library\user;

use lolbot\entities\Network;

/*
 * Tiny container for the user repos plus the network context the
 * scripts/user/user.php PM commands need. The commands reach the
 * network's container through the client's UserSystem bundle
 * (Client->userSystem, set by UserSystemFactory at spawn time); the
 * per-network static map below is the registry/back-compat surface.
 */

class UserRepos
{
    /**
     * Per-network containers keyed by network id, kept current by
     * UserSystemFactory::create(). Empty whenever no network is wired.
     *
     * @var array<int, UserRepos>
     */
    public static array $instances = [];

    public static function forNetwork(int $netId): ?self
    {
        return self::$instances[$netId] ?? null;
    }

    /**
     * Network the current wiring serves. Null whenever the wiring has
     * not set it (e.g. in tests constructing the container directly).
     */
    public ?Network $network = null;

    public function __construct(public UserRepo $users, public UserHostmaskRepo $masks, public ChannelFlagRepo $channelFlags)
    {
    }
}
