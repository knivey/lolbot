<?php

namespace library\user\engines;

use library\user\Engine;
use library\user\ResolveContext;

/*
 * Manual (password) auth. Unlike the other engines this one can never
 * resolve an identity from context: manual auth is command-driven, the
 * auth/register commands in scripts/user/user.php verify the password
 * and bind the nick into the IdentityCache directly (provenance
 * 'manual'). This engine still implements Engine so the chain built by
 * EngineConfig (whose AUTO_ORDER names 'manual') can include it as a
 * harmless never-resolving member and pinned auth_engines lists may
 * name it for future use.
 */

class ManualEngine implements Engine
{
    public const PROVENANCE = 'manual';

    public function resolve(ResolveContext $ctx): ?int
    {
        return null;
    }

    /**
     * Hash a password for storage. Argon2id when the runtime has it
     * (constant exists per the PHP docs pattern), PASSWORD_DEFAULT
     * otherwise.
     */
    public static function hashPassword(string $pass): string
    {
        $algo = \defined('PASSWORD_ARGON2ID') ? \PASSWORD_ARGON2ID : \PASSWORD_DEFAULT;
        return password_hash($pass, $algo);
    }

    /**
     * Verify a password against a stored hash. Users created via
     * services auto-registration have no hash (null) and can never
     * verify.
     */
    public static function verifyPassword(string $pass, ?string $hash): bool
    {
        if ($hash === null) {
            return false;
        }
        return password_verify($pass, $hash);
    }

    /**
     * Gate deciding whether a successful manual auth may remember the
     * nick's current hostmask: paranoid users never store (they auth
     * every connect); admins store only on networks that opted into
     * admin_hostmask_auth (the relax flag for nets where host faking
     * is not a concern); everyone else always stores.
     */
    public static function shouldStoreHostmask(bool $paranoid, bool $isAdmin, bool $networkAdminHostmaskAuth): bool
    {
        if ($paranoid) {
            return false;
        }
        if ($isAdmin && !$networkAdminHostmaskAuth) {
            return false;
        }
        return true;
    }
}
