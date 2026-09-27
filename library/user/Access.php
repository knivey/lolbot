<?php

namespace library\user;

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
        if (self::beforeAllows(...$args) === true) {
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
     * Public read of the before-hook's verdict: true iff a registered hook
     * explicitly allowed these args. The acl middleware's channel path uses
     * this so the superadmin bypass covers channel-scoped checks too.
     */
    public static function beforeAllows(object ...$args): bool
    {
        return self::$before !== null && (self::$before)(...$args) === true;
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

    /**
     * Defensive raw-flag extraction: the single place $user->flags is read.
     * Missing property or non-array value yields [], non-string entries are
     * filtered out.
     *
     * @return array<int, string>
     */
    public static function flagArray(object $user): array
    {
        if (!property_exists($user, 'flags')) {
            return [];
        }
        $flags = $user->flags;
        if (!is_array($flags)) {
            return [];
        }
        return array_values(array_filter($flags, 'is_string'));
    }

    /**
     * Check whether a user object carries a flag. Reads $user->flags
     * defensively via flagArray(): a missing flags property or a non-array
     * value (e.g. a legacy string shape) denies, non-string entries are
     * filtered out before a strict in_array so scalars never satisfy by
     * coercion. The check runs through the Flags registry, so it is
     * group-aware: a held flag grants its defined grants, and 'admin'
     * grants '*' (everything).
     */
    public static function userHasFlag(object $user, string $flag): bool
    {
        return Flags::passes(self::flagArray($user), $flag);
    }
}