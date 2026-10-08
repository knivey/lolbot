<?php

namespace library\user;

use lolbot\entities\User as UserEntity;

/*
 * Resolves command target specs to user rows (issue #143):
 *   '<nick>'    — identity cache binding, else lazy engine resolution
 *                 (WHOX answers for any online nick on capable nets;
 *                 account-tag/hostmask need prior contact). Services
 *                 chains auto-create from the resolved account.
 *   '*account'  — exact row lookup by account name, NO resolution.
 *                 Missing row: services-capable chains create it (the
 *                 account name is authoritative; typo-ghosts accepted);
 *                 hostmask/manual-only networks refuse (rows there come
 *                 from register/talk, not names).
 */
final class TargetResolver
{
    public static function resolve(UserSystem $us, \Irc\Client $client, string $spec): UserEntity
    {
        $spec = trim($spec);
        if ($spec === '' || $spec === '*') {
            throw new TargetResolutionException('no user given');
        }
        if ($spec[0] === '*') {
            $name = substr($spec, 1);
            // same sanity bound register uses (1-30, no control chars) —
            // keeps masks/junk out of rows and replies
            if ($name === '' || mb_strlen($name) > 30
                || preg_match('/[\x00-\x1f\x7f]/', $name) === 1
            ) {
                throw new TargetResolutionException('no user given');
            }
            $ref = $us->repos->users->findForNetwork($us->netId(), mb_strtolower($name));
            if ($ref !== null) {
                $full = $us->em->find(UserEntity::class, $ref->id);
                if ($full instanceof UserEntity) {
                    return $full;
                }
                throw new TargetResolutionException(
                    "user unknown (they must talk or auth first)",
                );
            }
            if (self::servicesCapable($us)) {
                // createFromAccount's interface only promises {id}; hydrate
                // the typed entity (identity-map hit, no query)
                $created = $us->repos->users->createFromAccount($us->netId(), $name);
                $full = $us->em->find(UserEntity::class, $created->id);
                if ($full instanceof UserEntity) {
                    return $full;
                }
                throw new TargetResolutionException(
                    "could not create user '{$name}'",
                );
            }
            throw new TargetResolutionException(
                "no user named '{$name}' — they must register or talk first",
            );
        }

        // bare nick: no identhost/account of the TARGET is knowable here,
        // so the context carries nulls and engines degrade by design —
        // account-tag/vhost/hostmask can't fire, whox answers for online
        // nicks, and the cache serves anyone previously resolved.
        // Nick-shape gate first: a spec like '#chan', '&x', 'a*,b' or
        // 'nick*' is a WHOX mask (channel/wildcard/list), not a nick —
        // sending it would query whatever matches first (wrong-target
        // grants) and lets anyone fire wildcard WHO queries
        if (preg_match('/^[#&+~]/', $spec) === 1
            || preg_match('/[*,?#\s]/', $spec) === 1
        ) {
            throw new TargetResolutionException(
                "user unknown (they must talk or auth first)",
            );
        }
        $hit = $us->svc->resolve(new ResolveContext(
            networkId: $us->netId(),
            nick: $spec,
            nickLowered: mb_strtolower($spec),
            identHost: null,
            account: null,
            client: $client,
            allowCreate: true,
        ));
        $user = $hit === null ? null : $us->em->find(UserEntity::class, $hit['user_id']);
        if (!$user instanceof UserEntity) {
            throw new TargetResolutionException(
                "user unknown (they must talk or auth first)",
            );
        }
        return $user;
    }

    private static function servicesCapable(UserSystem $us): bool
    {
        $engines = $us->svc->engineNames();
        return $engines !== [] && array_intersect(['account-tag', 'whox', 'vhost'], $engines) !== [];
    }
}
