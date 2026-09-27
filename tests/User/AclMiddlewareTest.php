<?php
namespace Tests\User;

use Doctrine\ORM\EntityManager;
use Irc\Client;
use Irc\Event\ChatEvent;
use Irc\Event\PmEvent;
use knivey\cmdr\Cmdr;
use knivey\cmdr\Request;
use library\user\Access;
use library\user\Acl;
use library\user\ChannelFlagRepo;
use library\user\Flags;
use library\user\IdentityCache;
use library\user\IdentityService;
use library\user\UserHostmaskRepo;
use library\user\UserRepo;
use library\user\UserRepos;
use library\user\UserSystem;
use lolbot\entities\Channel;
use lolbot\entities\Network;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../library/Irc/Consts.php';

/**
 * Minimal Irc\Client stand-in: the channel middleware path reads only
 * ->userSystem off the event's sender, so the connection machinery
 * stays unconstructed (parent constructor deliberately not called).
 */
final class AclFakeClient extends Client
{
    public function __construct()
    {
    }
}

class AclMiddlewareTest extends \PHPUnit\Framework\TestCase
{
    private Cmdr $router;

    public function setUp(): void
    {
        $this->router = new Cmdr();
        Acl::register($this->router);
    }

    public function tearDown(): void
    {
        // reset global Access state so the hook/resolver doesn't leak into other tests
        Access::before(null);
        Access::userResolver(null);
        Flags::reset();
    }

    /** @param array<int, mixed> $extraArgs */
    private function makeRequest(array $extraArgs = []): Request
    {
        $req = new Request(new \knivey\cmdr\Args(''), new \knivey\cmdr\Cmd('test', fn() => null, [], [], '', []));
        $req->extraArgs = $extraArgs;
        return $req;
    }

    /**
     * Real final UserSystem with a stubbed EntityManager: channelByName()'s
     * DQL is the only EM touch in the middleware path, stubbed at
     * createQuery() with a per-test channel row, while the repos carry a
     * per-test channel-flags grant (fakes for the untouched users/masks
     * repos). Production wiring (UserSystemFactory) is exercised by the
     * scratch-DB end-to-end instead.
     */
    private function makeBundle(?object $channel, ?object $grant): UserSystem
    {
        $query = $this->createStub(\Doctrine\ORM\Query::class);
        $query->method('getOneOrNullResult')->willReturn($channel);
        $em = $this->createStub(EntityManager::class);
        $em->method('createQuery')->willReturn($query);
        $channelFlags = $this->createStub(ChannelFlagRepo::class);
        $channelFlags->method('findForChannelUser')->willReturn($grant);
        $network = new Network();
        $network->name = 'TestNet';
        return new UserSystem(
            $network,
            new IdentityService(new IdentityCache(), []),
            new UserRepos(
                $this->createStub(UserRepo::class),
                $this->createStub(UserHostmaskRepo::class),
                $channelFlags,
            ),
            new IdentityCache(),
            $em,
        );
    }

    private function makeChatEvent(Client $sender, string $chan): ChatEvent
    {
        return new ChatEvent(time(), 'privmsg', $sender, 'tester', 'i', 'h', 'i@h', 'tester!i@h', $chan, 'hello');
    }

    /** @param array<int, mixed> $extraArgs */
    private function runChannelMiddleware(array $extraArgs, string $flag): mixed
    {
        $next = function (Request $req): mixed {
            return 'NEXT';
        };
        return ($this->router->middlewareAliases['acl'])(
            $this->makeRequest($extraArgs),
            $next,
            ...['flag' => $flag, 'channel' => true],
        );
    }

    public function test_acl_attribute_maps_name_and_args(): void
    {
        $attr = new Acl('botadmin');
        $this->assertSame('acl', $attr->name());
        $this->assertSame(['flag' => 'botadmin', 'channel' => false], $attr->args());
        // channel-scoped form always reports both keys
        $chanAttr = new Acl('quotes', channel: true);
        $this->assertSame(['flag' => 'quotes', 'channel' => true], $chanAttr->args());
    }

    public function test_register_registers_acl_alias_on_router(): void
    {
        $this->assertArrayHasKey('acl', $this->router->middlewareAliases);
        $this->assertInstanceOf(\Closure::class, $this->router->middlewareAliases['acl']);
    }

    public function test_before_hook_true_short_circuits_allowed(): void
    {
        Access::define('testflag', fn(object $user) => false);
        $user = new \stdClass();
        $seen = null;
        Access::before(function (object ...$args) use (&$seen): bool {
            $seen = $args;
            return true;
        });
        $this->assertSame(true, Access::allowed('testflag', $user));
        // the hook receives the same args the ACL callable would
        $this->assertSame([$user], $seen);
    }

    public function test_before_hook_null_falls_through_to_acl(): void
    {
        Access::define('testflag', fn(object $user) => false);
        $user = new \stdClass();
        Access::before(fn(object ...$args): ?bool => null);
        $this->assertSame(false, Access::allowed('testflag', $user));
    }

    public function test_before_null_clears_hook(): void
    {
        Access::define('testflag', fn(object $user) => false);
        $user = new \stdClass();
        Access::before(fn(object ...$args): bool => true);
        Access::before(null);
        $this->assertSame(false, Access::allowed('testflag', $user));
    }

    public function test_resolve_user_without_resolver_returns_null(): void
    {
        $this->assertNull(Access::resolveUser(['whatever']));
    }

    public function test_resolve_user_returns_resolver_result_and_receives_extra_args(): void
    {
        $user = new \stdClass();
        $seen = null;
        Access::userResolver(function (array $extraArgs) use ($user, &$seen): object {
            $seen = $extraArgs;
            return $user;
        });
        $extra = [new \stdClass(), 'bot'];
        $this->assertSame($user, Access::resolveUser($extra));
        $this->assertSame($extra, $seen);
    }

    public function test_middleware_denies_without_user_resolver(): void
    {
        $nextCalls = 0;
        $next = function (Request $req) use (&$nextCalls): mixed {
            $nextCalls++;
            return 'NEXT';
        };
        $result = ($this->router->middlewareAliases['acl'])($this->makeRequest(['ctx']), $next, ...['flag' => 'testflag']);
        $this->assertIsString($result);
        $this->assertStringContainsString('auth', $result);
        $this->assertSame(0, $nextCalls);
    }

    public function test_middleware_denies_when_acl_false_and_passes_resolved_user(): void
    {
        $user = new \stdClass();
        Access::userResolver(fn(array $extraArgs): object => $user);
        $seen = null;
        Access::define('testflag', function (object $u) use (&$seen): bool {
            $seen = $u;
            return false;
        });
        $nextCalls = 0;
        $next = function (Request $req) use (&$nextCalls): mixed {
            $nextCalls++;
            return 'NEXT';
        };
        $result = ($this->router->middlewareAliases['acl'])($this->makeRequest(['ctx']), $next, ...['flag' => 'testflag']);
        $this->assertIsString($result);
        $this->assertStringNotContainsString('NEXT', $result);
        $this->assertSame(0, $nextCalls);
        // prove the resolved user is what Access::allowed receives
        $this->assertSame($user, $seen);
    }

    public function test_middleware_allows_and_returns_next_result_when_acl_true(): void
    {
        $user = new \stdClass();
        Access::userResolver(fn(array $extraArgs): object => $user);
        Access::define('testflag', fn(object $u): bool => true);
        $nextCalls = 0;
        $next = function (Request $req) use (&$nextCalls): mixed {
            $nextCalls++;
            return 'NEXT';
        };
        $result = ($this->router->middlewareAliases['acl'])($this->makeRequest(['ctx']), $next, ...['flag' => 'testflag']);
        $this->assertSame('NEXT', $result);
        $this->assertSame(1, $nextCalls);
    }

    public function test_channel_mode_denies_pm_with_channel_only(): void
    {
        // a PM (no chan) can never satisfy a channel-scoped gate
        Access::userResolver(fn(): object => (object)['id' => 3, 'flags' => []]);
        $client = new AclFakeClient();
        $client->userSystem = $this->makeBundle(null, null);
        $event = new PmEvent(time(), 'privmsg', $client, 'tester', 'i', 'h', 'i@h', 'tester!i@h', 'bot', 'hello');
        $nextCalls = 0;
        $next = function (Request $req) use (&$nextCalls): mixed {
            $nextCalls++;
            return 'NEXT';
        };
        $result = ($this->router->middlewareAliases['acl'])(
            $this->makeRequest([$event]),
            $next,
            ...['flag' => 'admin', 'channel' => true],
        );
        $this->assertSame('channel only', $result);
        $this->assertSame(0, $nextCalls);
    }

    public function test_channel_mode_denies_when_channel_unknown(): void
    {
        Access::userResolver(fn(): object => (object)['id' => 3, 'flags' => []]);
        $client = new AclFakeClient();
        $client->userSystem = $this->makeBundle(null, null); // channelByName yields null
        $event = $this->makeChatEvent($client, '#nope');
        $this->assertSame(
            'not configured for this channel',
            $this->runChannelMiddleware([$event], 'admin'),
        );
    }

    public function test_channel_mode_passes_on_channel_grant(): void
    {
        $user = (object)['id' => 3, 'flags' => []];
        Access::userResolver(fn(): object => $user);
        $channel = new Channel();
        $channel->id = 7;
        $channel->name = '#test';
        $grant = (object)['id' => 9, 'flags' => ['quotes']];
        $client = new AclFakeClient();
        $client->userSystem = $this->makeBundle($channel, $grant);
        $event = $this->makeChatEvent($client, '#test');
        $this->assertSame('NEXT', $this->runChannelMiddleware([$event], 'quotes'));
    }

    public function test_channel_mode_network_flag_falls_through(): void
    {
        // no grant row, but the user's network flags alone satisfy the flag
        $user = (object)['id' => 3, 'flags' => ['quotes']];
        Access::userResolver(fn(): object => $user);
        $channel = new Channel();
        $channel->id = 7;
        $channel->name = '#test';
        $client = new AclFakeClient();
        $client->userSystem = $this->makeBundle($channel, null);
        $event = $this->makeChatEvent($client, '#test');
        $this->assertSame('NEXT', $this->runChannelMiddleware([$event], 'quotes'));
    }

    public function test_channel_mode_uses_only_the_event_senders_bundle(): void
    {
        // two bundles present (two sender clients); the event's sender is
        // bundle A whose channelByName/grants say deny, bundle B would
        // allow — resolution must never consult bundle B
        $user = (object)['id' => 3, 'flags' => []];
        Access::userResolver(fn(): object => $user);
        $channel = new Channel();
        $channel->id = 7;
        $channel->name = '#test';
        $clientA = new AclFakeClient();
        $clientA->userSystem = $this->makeBundle($channel, null);
        $clientB = new AclFakeClient();
        $clientB->userSystem = $this->makeBundle($channel, (object)['id' => 11, 'flags' => ['admin']]);
        $event = $this->makeChatEvent($clientA, '#test');
        $this->assertSame(
            'access denied',
            $this->runChannelMiddleware([$clientB, $event], 'admin'),
        );
    }

    public function test_channel_mode_superadmin_before_hook_still_bypasses(): void
    {
        // owner can never be locked out: before-hook true passes even with
        // NO flags and no channel grant
        Access::userResolver(fn(): object => (object)['id' => 3, 'flags' => []]);
        $channel = new Channel();
        $channel->id = 7;
        $channel->name = '#test';
        $client = new AclFakeClient();
        $client->userSystem = $this->makeBundle($channel, null);
        Access::before(fn(object ...$args): bool => true);
        $event = $this->makeChatEvent($client, '#test');
        $this->assertSame('NEXT', $this->runChannelMiddleware([$event], 'admin'));
    }
}
