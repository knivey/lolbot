<?php

namespace library\user;

use knivey\cmdr\Cmdr;
use knivey\cmdr\MiddlewareAttribute;
use knivey\cmdr\Request;

/**
 * Command attribute that attaches the acl middleware, gating the command
 * behind an Access flag: #[Acl("botadmin")]. The channel-scoped form
 * #[Acl("flag", channel: true)] instead resolves the channel from the
 * command's ChatEvent and checks the user's network flags UNION that
 * channel's grants, so a flag can be handed out in one channel only.
 *
 * Fail-closed: until the user system exists no resolver is registered with
 * Access::userResolver(), so no user resolves and every gated command denies.
 */
#[\Attribute(\Attribute::IS_REPEATABLE | \Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]
class Acl implements MiddlewareAttribute
{
    public function __construct(public string $flag, public bool $channel = false)
    {
    }

    public function name(): string
    {
        return 'acl';
    }

    /** @return array{flag: string, channel: bool} */
    public function args(): array
    {
        return ['flag' => $this->flag, 'channel' => $this->channel];
    }

    /**
     * Register the acl middleware alias on a router (per-bot, from
     * BotManager::spawn()). Defined here instead of user.php so it is
     * reachable via the PSR-4 autoloader without loading user.php's stub
     * commands into the bot.
     */
    public static function register(Cmdr $router): void
    {
        $router->aliasMiddleware('acl', self::middleware(...));
    }

    static function middleware(Request $req, callable $next, mixed ...$mwArgs): mixed
    {
        $flag = $mwArgs['flag'] ?? '';
        if (!is_string($flag)) {
            $flag = '';
        }
        $channelMode = ($mwArgs['channel'] ?? false) === true;

        $event = null;
        foreach ($req->extraArgs as $extra) {
            if ($extra instanceof \Irc\Event\UserEvent) {
                $event = $extra;
                break;
            }
        }

        if ($channelMode) {
            if (!$event instanceof \Irc\Event\ChatEvent || $event->chan === '') {
                return "channel only";
            }
            $user = Access::resolveUser($req->extraArgs);
            if (!is_object($user)) {
                return "auth required";
            }
            $sender = $event->sender;
            $us = ($sender instanceof \Irc\Client && $sender->userSystem instanceof UserSystem)
                ? $sender->userSystem : null;
            if ($us === null) {
                return "user system not ready";
            }
            $chanEntity = $us->channelByName($event->chan);
            if ($chanEntity === null) {
                return "not configured for this channel";
            }
            $grant = $us->repos->channelFlags->findForChannelUser($chanEntity->id, $user->id ?? 0);
            $union = array_merge(Access::flagArray($user), is_object($grant) && is_array($grant->flags ?? null) ? $grant->flags : []);
            // the superadmin before-hook bypasses channel checks too (owner
            // can never be locked out) — same gate rule as network scope
            if (!Access::beforeAllows($user, $req) && !Flags::passes($union, $flag)) {
                return "access denied";
            }
            return $next($req);
        }

        $user = Access::resolveUser($req->extraArgs);
        if (!is_object($user)) {
            return "auth required";
        }
        if (!Access::allowed($flag, $user, $req)) {
            return "access denied";
        }
        return $next($req);
    }
}
