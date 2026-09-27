<?php

namespace library\user;

use knivey\cmdr\Cmdr;
use knivey\cmdr\MiddlewareAttribute;
use knivey\cmdr\Request;

/**
 * Command attribute that attaches the acl middleware, gating the command
 * behind an Access flag: #[Acl("botadmin")].
 *
 * Fail-closed: until the user system exists no resolver is registered with
 * Access::userResolver(), so no user resolves and every gated command denies.
 */
#[\Attribute(\Attribute::IS_REPEATABLE | \Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]
class Acl implements MiddlewareAttribute
{
    public function __construct(public string $flag)
    {
    }

    public function name(): string
    {
        return 'acl';
    }

    /** @return array{flag: string} */
    public function args(): array
    {
        return ['flag' => $this->flag];
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
