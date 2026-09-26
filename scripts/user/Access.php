<?php

namespace scripts\user;

/*
 * ACL system
 */


class Access {
    /** @var array<string, callable> */
    protected static array $acls = [];
    /** @var callable|null */
    protected static $before = null;
    /** @var callable|null */
    protected static $userResolver = null;

    static function allowed(string $name, object ...$args): mixed
    {
        // before-hook runs first (laravel gates before semantics) so e.g. a
        // superadmin check can never be locked out by a broken/missing acl
        if (self::$before !== null && (self::$before)(...$args) === true) {
            return true;
        }
        if(!array_key_exists($name, self::$acls)) {
            throw new \Exception("acl $name is undefined");
        }
        return call_user_func_array(self::$acls[$name], $args);
    }

    // best way to give function args like $user?
    static function define(string $name, callable $function): void
    {
        self::$acls[$name] = $function;
    }

    /**
     * Set a hook run before every acl check; returning exactly true from it
     * allows immediately. Pass null to clear it.
     */
    static function before(?callable $hook): void
    {
        self::$before = $hook;
    }

    /**
     * Set the resolver the acl middleware uses to fetch the requesting user
     * from a command's extra args. Pass null to clear it (no user resolves,
     * every acl-gated command denies).
     */
    static function userResolver(?callable $resolver): void
    {
        self::$userResolver = $resolver;
    }

    /**
     * Resolve the requesting user via the registered resolver; null when no
     * resolver is set or the resolver yields no user.
     * @param array<int, mixed> $extraArgs
     */
    static function resolveUser(array $extraArgs): mixed
    {
        if (self::$userResolver === null) {
            return null;
        }
        return (self::$userResolver)($extraArgs);
    }
}