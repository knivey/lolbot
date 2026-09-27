<?php
namespace Tests\User;

use knivey\cmdr\Cmdr;
use knivey\cmdr\Request;
use library\user\Access;
use library\user\Acl;

require_once __DIR__ . '/../../vendor/autoload.php';

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
    }

    /** @param array<int, mixed> $extraArgs */
    private function makeRequest(array $extraArgs = []): Request
    {
        $req = new Request(new \knivey\cmdr\Args(''), new \knivey\cmdr\Cmd('test', fn() => null, [], [], '', []));
        $req->extraArgs = $extraArgs;
        return $req;
    }

    public function test_acl_attribute_maps_name_and_args(): void
    {
        $attr = new Acl('botadmin');
        $this->assertSame('acl', $attr->name());
        $this->assertSame(['flag' => 'botadmin'], $attr->args());
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
}
