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
        // reset the Task 5 static service locators so nothing leaks between
        // tests; Task 6 owns setting them in the real bot
        IdentityService::$instance = null;
        UserRepos::$instance = null;
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

    // ---- static service locators (Task 6 owns setting these) ----

    public function test_identity_service_instance_static_defaults_to_null(): void
    {
        $this->assertNull(IdentityService::$instance);
    }

    public function test_user_repos_instance_static_defaults_to_null(): void
    {
        $this->assertNull(UserRepos::$instance);
    }

    public function test_user_repos_container_holds_its_repos(): void
    {
        $users = new FakeManualUserRepo();
        $masks = new FakeManualMaskRepo();
        $repos = new UserRepos($users, $masks);
        UserRepos::$instance = $repos;
        $this->assertSame($repos, UserRepos::$instance);
        $this->assertSame($users, $repos->users);
        $this->assertSame($masks, $repos->masks);
        $this->assertNull($repos->network, 'network context starts unset; Task 6 wires it');
    }

    // ---- IdentityService manual-bind surface used by the PM commands ----

    public function test_bind_and_binding_round_trip(): void
    {
        $svc = new IdentityService(new IdentityCache(), []);
        IdentityService::$instance = $svc;
        $svc->bind(1, 'knivey', 7, ManualEngine::PROVENANCE);
        $hit = $svc->binding(1, 'knivey');
        $this->assertNotNull($hit);
        $this->assertSame(7, $hit['user_id']);
        $this->assertSame('manual', $hit['provenance']);
    }

    public function test_binding_is_read_from_cache_only(): void
    {
        // a nick with no binding must read null WITHOUT consulting engines:
        // pass an engine that would auto-register if consulted
        $svc = new IdentityService(new IdentityCache(), [new ManualEngine()]);
        $this->assertNull($svc->binding(1, 'nobody'));
        // scoping: other nick and other network do not see the binding
        $svc->bind(1, 'knivey', 7, ManualEngine::PROVENANCE);
        $this->assertNull($svc->binding(1, 'othernick'));
        $this->assertNull($svc->binding(2, 'knivey'));
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
