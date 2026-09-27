<?php

namespace Tests\User;

use library\user\engines\ManualEngine;
use library\user\IdentityCache;
use library\user\IdentityService;
use library\user\ResolveContext;
use library\user\UserHostmaskRepo;
use library\user\UserRepo;
use library\user\UserRepos;

require_once __DIR__ . '/../../vendor/autoload.php';

class ManualAuthTest extends \PHPUnit\Framework\TestCase
{
    public function tearDown(): void
    {
        // reset the per-network service locator maps so nothing leaks
        // between tests; the real wiring (UserSystemFactory, Task 6)
        // populates them per network at spawn time
        IdentityService::$instances = [];
        UserRepos::$instances = [];
    }

    // ---- ManualEngine PROVENANCE + chain membership ----

    public function test_manual_engine_declares_manual_provenance(): void
    {
        $this->assertSame('manual', ManualEngine::PROVENANCE);
    }

    public function test_manual_engine_satisfies_the_engine_contract(): void
    {
        $this->assertInstanceOf(\library\user\Engine::class, new ManualEngine());
    }

    // ---- ManualEngine::resolve never resolves ----

    public function test_resolve_always_returns_null_even_with_full_context(): void
    {
        $engine = new ManualEngine();
        $ctx = new ResolveContext(
            networkId: 1,
            nick: 'Knivey',
            nickLowered: 'knivey',
            identHost: 'user@host.example',
            account: 'knivey',
            client: new \stdClass(),
            allowCreate: true,
        );
        $this->assertNull($engine->resolve($ctx));
    }

    public function test_resolve_returns_null_with_empty_context(): void
    {
        $engine = new ManualEngine();
        $ctx = new ResolveContext(1, 'x', 'x', null, null, new \stdClass(), false);
        $this->assertNull($engine->resolve($ctx));
    }

    // ---- password hashing ----

    public function test_hash_verify_round_trip(): void
    {
        $hash = ManualEngine::hashPassword('hunter2');
        $this->assertNotSame('hunter2', $hash, 'hash must not leak the plaintext');
        $this->assertTrue(ManualEngine::verifyPassword('hunter2', $hash));
    }

    public function test_verify_wrong_password_is_false(): void
    {
        $hash = ManualEngine::hashPassword('hunter2');
        $this->assertFalse(ManualEngine::verifyPassword('hunter3', $hash));
    }

    public function test_verify_null_hash_is_false(): void
    {
        $this->assertFalse(ManualEngine::verifyPassword('hunter2', null));
    }

    public function test_hash_uses_argon2id_when_available(): void
    {
        $hash = ManualEngine::hashPassword('hunter2');
        if (\defined('PASSWORD_ARGON2ID')) {
            $this->assertStringStartsWith('$argon2id$', $hash);
        } else {
            $this->assertTrue(password_verify('hunter2', $hash), 'falls back to PASSWORD_DEFAULT');
        }
    }

    public function test_hash_is_salted_not_deterministic(): void
    {
        $this->assertNotSame(ManualEngine::hashPassword('x'), ManualEngine::hashPassword('x'));
    }

    // ---- shouldStoreHostmask gate ----

    public function test_paranoid_never_stores(): void
    {
        foreach ([[false, false], [false, true], [true, false], [true, true]] as [$isAdmin, $netFlag]) {
            $this->assertFalse(
                ManualEngine::shouldStoreHostmask(true, $isAdmin, $netFlag),
                "paranoid must never store (isAdmin=$isAdmin netFlag=$netFlag)",
            );
        }
    }

    public function test_admin_stores_only_when_network_relaxed(): void
    {
        $this->assertFalse(ManualEngine::shouldStoreHostmask(false, true, false), 'admin on strict network never stores');
        $this->assertTrue(ManualEngine::shouldStoreHostmask(false, true, true), 'admin on relaxed network stores');
    }

    public function test_everyone_else_always_stores(): void
    {
        $this->assertTrue(ManualEngine::shouldStoreHostmask(false, false, false));
        $this->assertTrue(ManualEngine::shouldStoreHostmask(false, false, true));
    }

    // ---- per-network service locator maps (UserSystemFactory populates them) ----

    public function test_identity_service_instance_map_starts_empty(): void
    {
        $this->assertSame([], IdentityService::$instances);
        $this->assertNull(IdentityService::forNetwork(1));
    }

    public function test_user_repos_instance_map_starts_empty(): void
    {
        $this->assertSame([], UserRepos::$instances);
        $this->assertNull(UserRepos::forNetwork(1));
    }

    public function test_user_repos_container_holds_its_repos(): void
    {
        $users = new FakeManualUserRepo();
        $masks = new FakeManualMaskRepo();
        $repos = new UserRepos($users, $masks);
        UserRepos::$instances[3] = $repos;
        $this->assertSame($repos, UserRepos::forNetwork(3));
        $this->assertSame($users, $repos->users);
        $this->assertSame($masks, $repos->masks);
        $this->assertNull($repos->network, 'network context starts unset; the factory wiring sets it');
    }

    public function test_locator_maps_are_keyed_per_network(): void
    {
        $svcA = new IdentityService(new IdentityCache(), []);
        $svcB = new IdentityService(new IdentityCache(), []);
        IdentityService::$instances[1] = $svcA;
        IdentityService::$instances[2] = $svcB;
        // two networks wired in one process never see each other's service
        $this->assertSame($svcA, IdentityService::forNetwork(1));
        $this->assertSame($svcB, IdentityService::forNetwork(2));
        $this->assertNull(IdentityService::forNetwork(3));
    }

    // ---- IdentityService manual-bind surface used by the PM commands ----

    public function test_bind_and_binding_round_trip(): void
    {
        $svc = new IdentityService(new IdentityCache(), []);
        IdentityService::$instances[1] = $svc;
        $svc->bind(1, 'knivey', 7, ManualEngine::PROVENANCE);
        $hit = $svc->binding(1, 'knivey');
        $this->assertNotNull($hit);
        $this->assertSame(7, $hit['user_id']);
        $this->assertSame('manual', $hit['provenance']);
    }

    public function test_binding_is_read_from_cache_only(): void
    {
        // a nick with no binding must read null WITHOUT consulting engines:
        // pass a recording engine that would resolve if consulted
        $engine = new RecordingSpyEngine();
        $svc = new IdentityService(new IdentityCache(), [$engine]);
        $this->assertNull($svc->binding(1, 'nobody'));
        $this->assertSame(0, $engine->resolveCalls, 'binding() must never run the engine chain');
        // scoping: other nick and other network do not see the binding
        $svc->bind(1, 'knivey', 7, ManualEngine::PROVENANCE);
        $this->assertNull($svc->binding(1, 'othernick'));
        $this->assertNull($svc->binding(2, 'knivey'));
        $this->assertSame(0, $engine->resolveCalls);
    }

    public function test_bind_overwrites_previous_binding_for_nick(): void
    {
        $svc = new IdentityService(new IdentityCache(), []);
        $svc->bind(1, 'knivey', 7, ManualEngine::PROVENANCE);
        $svc->bind(1, 'knivey', 9, ManualEngine::PROVENANCE);
        $hit = $svc->binding(1, 'knivey');
        $this->assertNotNull($hit);
        $this->assertSame(9, $hit['user_id']);
    }
}

final class FakeManualUser
{
    public function __construct(public int $id)
    {
    }
}

final class FakeManualUserRepo implements UserRepo
{
    public function findForNetwork(int $netId, string $nameLowered): ?object
    {
        return null;
    }

    public function createFromAccount(int $netId, string $account): object
    {
        return new FakeManualUser(1);
    }
}

final class FakeManualMaskRepo implements UserHostmaskRepo
{
    public function findForHost(int $netId, string $identHost): array
    {
        return [];
    }
}

/**
 * Records resolve() calls and would resolve if consulted; used to prove
 * IdentityService::binding() reads the cache only and never runs the
 * engine chain (the Task 5 canary was the always-null ManualEngine,
 * which could not distinguish consulted from not-consulted).
 */
final class RecordingSpyEngine implements \library\user\Engine
{
    public int $resolveCalls = 0;

    public function resolve(\library\user\ResolveContext $ctx): int
    {
        $this->resolveCalls++;
        return 42;
    }
}
