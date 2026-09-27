<?php

namespace library\user;

use lolbot\entities\Network;

/*
 * Tiny container for the user repos plus the network context the
 * scripts/user/user.php PM commands need. Until the per-bot wiring
 * lands (Task 6 owns this), the commands reach everything through the
 * static $instance locator; the bot sets it (and ->network) at spawn
 * time the same way it registers the acl middleware per router.
 */

class UserRepos
{
    public static ?UserRepos $instance = null;

    /**
     * Network the current wiring serves. Null until Task 6's wiring
     * sets it (and whenever the statics are unset, e.g. in tests).
     */
    public ?Network $network = null;

    public function __construct(public UserRepo $users, public UserHostmaskRepo $masks)
    {
    }
}
