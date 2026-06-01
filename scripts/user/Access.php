<?php

namespace scripts\user;

/*
 * ACL system
 */


class Access {
    /** @var array<string, callable> */
    protected static array $acls = [];

    static function allowed(string $name, object ...$args): mixed
    {
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
}