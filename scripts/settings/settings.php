<?php
namespace scripts\settings;

use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Desc;
use knivey\cmdr\attributes\Option;
use knivey\cmdr\attributes\PrivCmd;
use knivey\cmdr\attributes\Syntax;
use library\settings\Setting;
use library\settings\SettingsRegistry;
use library\settings\SettingsStore;
use library\settings\SettingValue;
use library\user\Access;
use library\user\ChannelAccess;
use library\user\ResolveContext;
use library\user\UserSystem;
use lolbot\entities\User as UserEntity;

require_once __DIR__ . '/../../library/paste.php';

/*
 * The .set / .unset commands over the central settings registry
 * (library/settings). One command pair, two contexts:
 *
 *  - in a channel: channel-scoped settings, resolved channel-tier first,
 *    then the network tier, then the definition default. Writes are
 *    gated by the setting definition's flag in THAT channel
 *    (ChannelAccess: network flags union channel grants);
 *    network_only definitions instead gate on NETWORK flags alone —
 *    channel admins can read but never write those.
 *  - via PM: account-scoped settings for the speaking user (any known
 *    user may set their own).
 *
 * Which context the command ran in decides which scope is addressable —
 * a channel key via PM (or an account key in chat) is refused with a
 * pointer to the right place rather than a bare error.
 */

/**
 * The calling bot's user-system bundle (identity service, repos,
 * network, entity manager) read off the Irc\Client the router hands the
 * command. Null when the client's network has not been wired
 * (UserSystemFactory at spawn time); callers reply "user system not
 * ready" in that case. Local re-implementation of the
 * scripts\user\resolveUserSystem lookup so this script does not have to
 * require user.php (and its stub PM commands) to reach the bundle.
 */
function settingsUserSystem(\Irc\Client $bot): ?UserSystem
{
    $us = $bot->userSystem;
    if (!$us instanceof UserSystem) {
        return null;
    }
    return $us;
}

/**
 * Resolve the speaking nick to its user entity the way the user.php
 * granter resolution does (identity service first, entity second),
 * allowCreate true so gated commands auto-register services
 * identities. Null when no identity resolves.
 */
function settingsSpeaker(UserSystem $sys, \Irc\Event\UserEvent $args, \Irc\Client $bot): ?UserEntity
{
    $hit = $sys->svc->resolve(new ResolveContext(
        networkId: $sys->netId(),
        nick: $args->nick,
        nickLowered: mb_strtolower($args->nick),
        identHost: $args->identhost,
        account: $args->account,
        client: $bot,
        allowCreate: true,
    ));
    if ($hit === null) {
        return null;
    }
    return $sys->em->find(UserEntity::class, $hit['user_id']);
}

/**
 * Chat vs PM: ChatEvent carries a chan property, PmEvent does not (and
 * a malformed empty chan is treated as PM so nothing is ever sent to a
 * target the event did not name). The ?? is isset semantics — an absent
 * property yields '' instead of a dynamic-property read.
 */
function settingsChan(\Irc\Event\UserEvent $args): string
{
    $chan = $args->chan ?? null;
    return is_string($chan) ? $chan : '';
}

/**
 * Args offset access is mixed-typed; the commands want plain strings.
 * Parsed arg values are string|null by construction (null = optional
 * arg not passed), so the assert only re-narrows for static analysis
 * (the user.php argString pattern).
 */
function settingsArgString(\knivey\cmdr\Args $cmdArgs, string $name): ?string
{
    $val = $cmdArgs[$name];
    \assert(is_string($val) || $val === null);
    return $val;
}

/**
 * Emit the registry's irc-visible settings for one scope, each as
 * "<key>: <display> (source: X)", behind a header naming the context.
 * Over 20 lines (or --web) the list goes to the paste service when one
 * is configured, falling back to inline lines on paste failure (the
 * alias.php pattern). Reply lines are fully templated — keys come from
 * the code-defined registry and values from SettingValue::display(), so
 * unlike user-content paths (alias values, codesand output) there is no
 * leading user-controlled text to guard with the anti-bot \2\2 marker.
 *
 * @param \Closure(string): void $reply
 */
function settingsList(
    string $scope,
    SettingsStore $store,
    \Closure $reply,
    int $networkId,
    ?int $channelId,
    ?int $userId,
    bool $web,
    string $header,
): void {
    $lines = [];
    foreach (SettingsRegistry::all(scope: $scope, irc: true) as $name => $definition) {
        if ($scope === 'channel') {
            $got = $store->getChannelSetting($networkId, $channelId, $name);
        } else {
            // the account branch is only entered with a resolved user
            \assert($userId !== null);
            $got = $store->getUserSetting($userId, $name);
        }
        $lines[] = "$name: " . SettingValue::display($got['value']) . " (source: {$got['source']})";
    }
    if ($lines === []) {
        $reply("no settings registered");
        return;
    }
    $usePaste = $web || count($lines) > 20;
    $paste = null;
    if ($usePaste) {
        global $entityManager;
        if ($entityManager instanceof \Doctrine\ORM\EntityManager) {
            $paste = (new \lolbot\config\ServiceLocator($entityManager))->getServiceConfig('paste');
        }
    }
    if ($usePaste
        && $paste instanceof \lolbot\entities\PasteServiceConfig
        && $paste->host !== null
        && $paste->key !== null
    ) {
        try {
            $url = \createPaste($header . "\n" . implode("\n", $lines), $header, $paste->host, $paste->key);
            $reply($url);
            return;
        } catch (\Throwable $e) {
            echo "Paste error for settings: " . $e->getMessage() . "\n";
        }
    }
    $reply($header);
    foreach ($lines as $line) {
        $reply($line);
    }
}

/**
 * Shared body for .set and .unset: context split (chat = channel scope,
 * PM = account scope), unknown/wrong-scope refusals, then the per-scope
 * show / write / clear behaviors. $isUnset selects the clear path.
 */
function settingsApply(bool $isUnset, \Irc\Event\UserEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
{
    $chan = settingsChan($args);
    $isChat = $chan !== '';
    $reply = function (string $msg) use ($isChat, $chan, $args, $bot): void {
        $bot->pm($isChat ? $chan : $args->nick, $msg);
    };
    $sys = settingsUserSystem($bot);
    if ($sys === null) {
        $reply("user system not ready");
        return;
    }
    // the store is built per call from this bot's bundle EM so commands
    // never share flush state across networks
    $store = new SettingsStore($sys->em);
    $key = trim(settingsArgString($cmdArgs, 'key') ?? '');
    $useNet = $cmdArgs->optEnabled('--net');
    // unset's syntax has no value arg; reading a name the syntax never
    // defined would throw, so probe the definition first
    $hasValueArg = $cmdArgs->getUnparsedArg('value') !== null;
    $value = $hasValueArg ? trim(settingsArgString($cmdArgs, 'value') ?? '') : '';

    if (!$isChat) {
        if ($useNet) {
            $reply("--net is only valid in a channel");
            return;
        }
        $user = settingsSpeaker($sys, $args, $bot);
        if ($user === null) {
            $reply("auth required");
            return;
        }
        if ($key === '') {
            settingsList(
                'account',
                $store,
                $reply,
                $sys->netId(),
                null,
                $user->id,
                $cmdArgs->optEnabled('--web'),
                'your account settings',
            );
            return;
        }
        $definition = SettingsRegistry::get($key);
        if ($definition === null) {
            $reply("unknown setting '$key' — try .set to list");
            return;
        }
        if ($definition->scope !== 'account') {
            $reply("'$key' is a channel setting — set it in the channel");
            return;
        }
        if ($isUnset) {
            $current = $store->getUserSetting($user->id, $key);
            if ($current['source'] === 'default') {
                $reply("'$key' is already at its default");
                return;
            }
            $store->clearUserSetting($user->id, $key);
            $after = $store->getUserSetting($user->id, $key);
            $reply("setting $key cleared (now " . SettingValue::display($after['value']) . " from {$after['source']})");
            return;
        }
        if ($value === '') {
            $got = $store->getUserSetting($user->id, $key);
            $reply("$key: " . SettingValue::display($got['value']) . " (source: {$got['source']})");
            return;
        }
        $coerced = settingsCoerce($definition, $value, $reply);
        if ($coerced === null) {
            return;
        }
        $store->setUserSetting($user->id, $key, $coerced);
        $reply("setting $key set to " . SettingValue::display($coerced));
        return;
    }

    // chat context: channel scope. The Channel row supplies the channel
    // id for reads/writes (and ChannelAccess re-resolves it for gates)
    $chanEntity = $sys->channelByName($chan);
    if ($chanEntity === null) {
        $reply("I'm not configured for this channel");
        return;
    }
    if ($key === '') {
        // <key> is required for unset; the syntax would have refused the
        // command before reaching here, so this is the set-list branch
        settingsList(
            'channel',
            $store,
            $reply,
            $sys->netId(),
            $chanEntity->id,
            null,
            $cmdArgs->optEnabled('--web'),
            "settings for $chan (channel and network tiers)",
        );
        return;
    }
    $definition = SettingsRegistry::get($key);
    if ($definition === null) {
        $reply("unknown setting '$key' — try .set to list");
        return;
    }
    if ($definition->scope !== 'channel') {
        $reply("'$key' is an account setting — set it via PM");
        return;
    }
    if ($isUnset || $value !== '') {
        // writes (set and unset alike) resolve the speaker the same way
        // the PM path does — identity comes from the event — and are
        // gated by the definition's flag in THIS channel
        $user = settingsSpeaker($sys, $args, $bot);
        if ($user === null) {
            $reply("auth required");
            return;
        }
        // network_only settings gate on NETWORK flags alone (plus the
        // superadmin before-hook) — a channel admin's in-channel grant
        // must not satisfy them (operator money/steering, e.g. the
        // vision model); everything else gates in-channel as before
        $passes = $definition->network_only
            ? (Access::beforeAllows($user) || Access::userHasFlag($user, $definition->flag))
            : ChannelAccess::passesInChannel($sys, $user, $chan, $definition->flag);
        if (!$passes) {
            $reply($definition->network_only
                ? "access denied — '{$definition->name}' can only be changed by network admins"
                : "access denied");
            return;
        }
    }
    // reads and writes address the channel tier, or the network tier
    // with --net (getChannelSetting with a null channel id reads/writes
    // the (network, NULL) row directly)
    $readTier = fn(): array => $store->getChannelSetting($sys->netId(), $useNet ? null : $chanEntity->id, $key);
    if ($isUnset) {
        $current = $readTier();
        if ($current['source'] === 'default') {
            $reply("'$key' is already at its default");
            return;
        }
        if ($useNet) {
            $store->clearNetworkSetting($sys->netId(), $key);
        } else {
            $store->clearChannelSetting($sys->netId(), $chanEntity->id, $key);
        }
        $after = $readTier();
        $reply("setting $key cleared (now " . SettingValue::display($after['value']) . " from {$after['source']})");
        return;
    }
    if ($value === '') {
        $got = $readTier();
        $reply("$key: " . SettingValue::display($got['value']) . " (source: {$got['source']})");
        return;
    }
    $coerced = settingsCoerce($definition, $value, $reply);
    if ($coerced === null) {
        return;
    }
    if ($useNet) {
        $store->setNetworkSetting($sys->netId(), $key, $coerced);
    } else {
        $store->setChannelSetting($sys->netId(), $chanEntity->id, $key, $coerced);
    }
    $reply("setting $key set to " . SettingValue::display($coerced));
}

/**
 * Coerce with the registry's value rules, surfacing a refusal as the
 * exception's message on $reply. Returns null when the input was
 * refused (the caller must abandon the operation — nothing persisted).
 *
 * @param \Closure(string): void $reply
 * @return bool|int|string|null
 */
function settingsCoerce(Setting $definition, string $value, \Closure $reply): bool|int|string|null
{
    try {
        return SettingValue::coerce($definition, $value);
    } catch (\InvalidArgumentException $e) {
        $reply($e->getMessage());
        return null;
    }
}

#[Cmd("set")]
#[PrivCmd("set")]
#[Desc("Show or change settings: channel settings in the channel, account settings via PM")]
// cmdr's multiword marker goes OUTSIDE the optional bracket ([value]...),
// so the arg is addressable as 'value' and swallows the rest of the line
#[Syntax("[key] [value]...")]
#[Option("--net", "in a channel: set the network tier instead of the channel tier")]
#[Option("--web", "paste the setting list to the web paste service")]
function set(\Irc\Event\UserEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
{
    settingsApply(false, $args, $bot, $cmdArgs);
}

// the irc name is "unset" but that spelling is reserved in php, so the
// function carries a different name and the attribute binds the command
#[Cmd("unset")]
#[PrivCmd("unset")]
#[Desc("Clear a setting back to its default: channel settings in the channel, account settings via PM")]
#[Syntax("<key>")]
#[Option("--net", "in a channel: clear the network tier instead of the channel tier")]
function unsetSetting(\Irc\Event\UserEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
{
    settingsApply(true, $args, $bot, $cmdArgs);
}
