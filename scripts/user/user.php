<?php
namespace scripts\user;
/*
 * user authentication system
 *
 * users will be logged in by just matching a hostmask or authing everytime they connect to irc, (or the bot does)
 * * on networks with services try to use irc caps to auth maybe? will require changing irc lib
 *
 * need a hostmask generator just make it *!*ident@fullhost
 *
 * user accounts will not have centralized settings, scripts can do their own set cmds etc and just have a user_id
 *
 * admin acl
 *
 * channel acl - later keeping it simple for now
 *
 * look at laravel gates for better idea on doing things with modular scripts
 *
 *
 * later modify Irc\Client to support ircv3 account-tag, add that ass optional auth engine per network
 *
 *
 * update other scripts that can use a user system
 *
 * todo: artbot put unauthed user arts in a guest dir
 * todo: cmdr needs to be able to do private msg commands, attr PrivCmd -done
 * possibly later add proper middlewares to cmdr, then those can be used for auth checks
 */

use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\PrivCmd;
use knivey\cmdr\attributes\Options;
use knivey\cmdr\attributes\Syntax;
use library\user\Access;
use library\user\Acl;
use library\user\engines\ManualEngine;
use library\user\UserSystem;
use lolbot\entities\User as UserEntity;
use lolbot\entities\UserHostmask;

global $config;

class User {

}

//function auth($request) : User {
//
//}

/**
 * Shared context for the PM commands: the calling bot's user-system
 * bundle (identity service, repos, network, entity manager) read off
 * the Irc\Client the router hands the command. Null when the client's
 * network has not been wired (UserSystemFactory at spawn time);
 * callers reply "user system not ready" in that case.
 */
function resolveUserSystem(\Irc\Client $bot): ?UserSystem
{
    $us = $bot->userSystem;
    if (!$us instanceof UserSystem) {
        return null;
    }
    return $us;
}

/**
 * Args offset access is mixed-typed; the commands want plain strings.
 * Parsed arg values are string|null by construction (null = optional
 * arg not passed), so the assert only re-narrows for static analysis.
 */
function argString(\knivey\cmdr\Args $cmdArgs, string $name): ?string
{
    $val = $cmdArgs[$name];
    \assert(is_string($val) || $val === null);
    return $val;
}

/**
 * Store the sending nick's current hostmask for $user unless the
 * shouldStoreHostmask gate says not to (paranoid, or an admin on a
 * network that has not relaxed the rule). Mask shape *!*ident@host per
 * the notes header (the * before the ident absorbs a server-added ~
 * prefix); already-known masks are skipped so the
 * (user_id, mask) unique constraint holds.
 */
function storeHostmask(UserSystem $sys, UserEntity $user, \Irc\Event\UserEvent $args): void
{
    if (!ManualEngine::shouldStoreHostmask(
        $user->paranoid,
        Access::userHasFlag($user, 'admin'),
        $sys->network->admin_hostmask_auth,
    )) {
        return;
    }
    $mask = '*!*' . $args->identhost;
    $known = $sys->em->getRepository(UserHostmask::class)->findOneBy(['user_id' => $user->id, 'mask' => $mask]);
    if ($known !== null) {
        return;
    }
    $hostmask = new UserHostmask();
    $hostmask->user_id = $user->id;
    $hostmask->mask = $mask;
    $hostmask->addedBy = 'self';
    $sys->em->persist($hostmask);
    $sys->em->flush();
}

/**
 * Look up the full user entity the nick's services account maps to on
 * this network, or null when the nick carries no services account or
 * the account maps to no user. register/auth refuse when this returns
 * a user other than the one being authed: services identities are
 * automatic and must not be shadowed by a password account.
 *
 * @param UserSystem $sys
 */
function servicesAccountUser(UserSystem $sys, ?string $account): ?UserEntity
{
    if ($account === null || $account === '') {
        return null;
    }
    $user = $sys->em->getRepository(UserEntity::class)->findOneBy([
        'network_id' => $sys->network->id,
        'nameLowered' => mb_strtolower($account),
    ]);
    return $user;
}

/**
 * Password registration (services-less networks only): creates a users
 * row with an argon2id hash and binds the nick manually. Refused
 * outright when the network's engine chain includes account-tag or
 * whos: identities there are automatic, and a password row created
 * under a name the registrant does not own would squat it — the real
 * owner's later account-tag arrival would auto-bind to the attacker's
 * row and pick up any `user:flags` bootstrap grant on that name. Name
 * and pass are sanity-checked before any branch (bad names could
 * otherwise smuggle control chars into rows and replies).
 */
#[PrivCmd("register")]
#[Syntax("<name> <pass>")]
function register(\Irc\Event\UserEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
{
    $sys = resolveUserSystem($bot);
    if ($sys === null) {
        $bot->pm($args->nick, "user system not ready");
        return;
    }
    $name = argString($cmdArgs, 'name') ?? '';
    $pass = argString($cmdArgs, 'pass') ?? '';
    $netId = $sys->network->id;
    $lowered = mb_strtolower($name);

    if (!preg_match('/^[A-Za-z0-9_\[\]{}^`|-]{1,30}$/', $name)) {
        $bot->pm($args->nick, "invalid name (1-30 chars, only letters, digits and _[]{}^`|-)");
        return;
    }
    if (strlen($pass) < 8) {
        $bot->pm($args->nick, "password must be at least 8 characters");
        return;
    }

    $chain = $sys->svc->engineNames();
    if (in_array('account-tag', $chain, true) || in_array('whox', $chain, true)) {
        $bot->pm($args->nick, "identities on this network are automatic (services) — no registration needed; just use a gated command");
        return;
    }

    if ($sys->repos->users->findForNetwork($netId, $lowered) !== null) {
        $bot->pm($args->nick, "that name is taken");
        return;
    }

    $known = servicesAccountUser($sys, $args->account);
    if ($known !== null) {
        $bot->pm($args->nick, "you are already known as {$known->name} — services identities are automatic");
        return;
    }

    $user = new UserEntity();
    $user->network_id = $netId;
    $user->name = $name;
    $user->nameLowered = $lowered;
    $user->passHash = ManualEngine::hashPassword($pass);
    $sys->em->persist($user);
    $sys->em->flush();

    storeHostmask($sys, $user, $args);
    $sys->svc->bind($netId, mb_strtolower($args->nick), $user->id, ManualEngine::PROVENANCE);
    $bot->pm($args->nick, "registered");
}

#[PrivCmd("auth")]
#[Syntax("<name> <pass>")]
function cmd_auth(\Irc\Event\UserEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
{
    $sys = resolveUserSystem($bot);
    if ($sys === null) {
        $bot->pm($args->nick, "user system not ready");
        return;
    }
    $name = argString($cmdArgs, 'name') ?? '';
    $pass = argString($cmdArgs, 'pass') ?? '';
    $netId = $sys->network->id;

    $user = $sys->em->getRepository(UserEntity::class)->findOneBy([
        'network_id' => $netId,
        'nameLowered' => mb_strtolower($name),
    ]);
    if ($user === null || !ManualEngine::verifyPassword($pass, $user->passHash)) {
        echo "auth failed for '" . mb_strtolower($name) . "' from {$args->fullhost}\n";
        $bot->pm($args->nick, "auth failed");
        return;
    }

    $known = servicesAccountUser($sys, $args->account);
    if ($known !== null && $known->id !== $user->id) {
        $bot->pm($args->nick, "you are already known as {$known->name} — services identities are automatic");
        return;
    }

    $sys->svc->bind($netId, mb_strtolower($args->nick), $user->id, ManualEngine::PROVENANCE);
    storeHostmask($sys, $user, $args);
    $bot->pm($args->nick, "authed as {$user->name}");
}

//a setting for if hostmask should be remembered or they need to auth every upon connection
#[PrivCmd("paranoid")]
#[Syntax("[state]")]
function paranoid(\Irc\Event\UserEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
{
    $sys = resolveUserSystem($bot);
    if ($sys === null) {
        $bot->pm($args->nick, "user system not ready");
        return;
    }
    $binding = $sys->svc->binding($sys->network->id, mb_strtolower($args->nick));
    if ($binding === null) {
        $bot->pm($args->nick, "auth first");
        return;
    }
    $user = $sys->em->find(UserEntity::class, $binding['user_id']);
    if ($user === null) {
        $bot->pm($args->nick, "auth first");
        return;
    }
    $state = argString($cmdArgs, 'state');
    if ($state === null) {
        $user->paranoid = !$user->paranoid;
    } elseif (strtolower($state) === 'on') {
        $user->paranoid = true;
    } elseif (strtolower($state) === 'off') {
        $user->paranoid = false;
    } else {
        $bot->pm($args->nick, "usage: paranoid [on|off]");
        return;
    }
    $sys->em->flush();
    if ($user->paranoid) {
        // paranoid forces manual auth each connect: any stored mask
        // would keep resolving through the hostmask engine, so drop it
        // now (the engine also skips paranoid-owned rows as a second
        // line of defense for masks stored before the toggle)
        $sys->repos->masks->deleteForUser($user->id);
        $bot->pm($args->nick, "paranoid is now on (stored hostmasks removed)");
    } else {
        $bot->pm($args->nick, "paranoid is now off");
    }
}

#[PrivCmd("pass")]
#[Syntax("<old> <new>")]
function pass(\Irc\Event\UserEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
{
    $sys = resolveUserSystem($bot);
    if ($sys === null) {
        $bot->pm($args->nick, "user system not ready");
        return;
    }
    $binding = $sys->svc->binding($sys->network->id, mb_strtolower($args->nick));
    if ($binding === null) {
        $bot->pm($args->nick, "auth first");
        return;
    }
    $user = $sys->em->find(UserEntity::class, $binding['user_id']);
    if ($user === null) {
        $bot->pm($args->nick, "auth first");
        return;
    }
    if (!ManualEngine::verifyPassword(argString($cmdArgs, 'old') ?? '', $user->passHash)) {
        $bot->pm($args->nick, "auth failed");
        return;
    }
    $user->passHash = ManualEngine::hashPassword(argString($cmdArgs, 'new') ?? '');
    $sys->em->flush();
    $bot->pm($args->nick, "password updated");
}

//flags are acls in Access
#[PrivCmd("setflags")]
#[Acl("admin")]
#[Syntax("<name> <ops>...")]
function addadmin(\Irc\Event\UserEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
{
    applyFlagOps('set', $args, $bot, $cmdArgs);
}

#[PrivCmd("addflags")]
#[Acl("admin")]
#[Syntax("<name> <ops>...")]
function addflags(\Irc\Event\UserEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
{
    applyFlagOps('add', $args, $bot, $cmdArgs);
}

#[PrivCmd("delflags")]
#[Acl("admin")]
#[Syntax("<name> <ops>...")]
function delflags(\Irc\Event\UserEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
{
    applyFlagOps('del', $args, $bot, $cmdArgs);
}

/**
 * Shared body for setflags/addflags/delflags: resolves the target
 * user, applies User::applyFlag per token and replies with the
 * resulting flags. set-mode honors per-token +/- (plus the CLI's
 * ^/! removal spellings, normalized to '-'), bare tokens add; add/del
 * modes force their op on every token.
 */
function applyFlagOps(string $mode, \Irc\Event\UserEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
{
    $sys = resolveUserSystem($bot);
    if ($sys === null) {
        $bot->pm($args->nick, "user system not ready");
        return;
    }
    $name = argString($cmdArgs, 'name') ?? '';
    $user = $sys->em->getRepository(UserEntity::class)->findOneBy([
        'network_id' => $sys->network->id,
        'nameLowered' => mb_strtolower($name),
    ]);
    if ($user === null) {
        $bot->pm($args->nick, "no such user");
        return;
    }
    /** @var list<string> $tokens */
    $tokens = preg_split('/\s+/', trim(argString($cmdArgs, 'ops') ?? '')) ?: [];
    $tokens = array_values(array_filter($tokens, static fn (string $t): bool => $t !== ''));
    if ($tokens === []) {
        $bot->pm($args->nick, "no flags given");
        return;
    }
    try {
        foreach ($tokens as $token) {
            [$op, $flag] = flagOp($mode, $token);
            $user->flags = UserEntity::applyFlag($user->flags, $op, $flag);
        }
    } catch (\InvalidArgumentException $e) {
        $bot->pm($args->nick, $e->getMessage());
        return;
    }
    $sys->em->flush();
    $flags = implode(',', $user->flags);
    $bot->pm($args->nick, "flags for {$user->name}: " . ($flags === '' ? '(none)' : $flags));
}

/**
 * Turn one user-supplied token into an applyFlag op pair. set-mode
 * honors +/-/^/! signs (bare token adds, like the user:flags CLI),
 * add/del modes force their op and strip any leading signs.
 *
 * @return array{0: '+'|'-', 1: string}
 */
function flagOp(string $mode, string $token): array
{
    if ($mode === 'add') {
        return ['+', ltrim($token, '+')];
    }
    if ($mode === 'del') {
        return ['-', ltrim($token, "-^!")];
    }
    $sign = substr($token, 0, 1);
    if ($sign === '+' || $sign === '-' || $sign === '^' || $sign === '!') {
        return [$sign === '+' ? '+' : '-', substr($token, 1)];
    }
    return ['+', $token];
}
