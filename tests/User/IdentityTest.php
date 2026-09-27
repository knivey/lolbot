<?php

namespace Tests\User;

use library\user\Engine;
use library\user\EngineConfig;
use library\user\engines\AccountTagEngine;
use library\user\IdentityCache;
use library\user\IdentityService;
use library\user\ResolveContext;
use library\user\UserRepo;

require_once __DIR__ . '/../../vendor/autoload.php';

class IdentityTest extends \PHPUnit\Framework\TestCase
{
    // ---- IdentityCache ----

    public function test_cache_set_then_get_returns_binding_with_clock_refreshed_at(): void
    {
        $cache = new IdentityCache(static fn (): int => 123456);
        $cache->set(1, 'knivey', 7, 'account-tag');
        $this->assertSame([
            'user_id' => 7,
            'provenance' => 'account-tag',
            'refreshed_at' => 123456,
        ], $cache->get(1, 'knivey'));
    }

    public function test_cache_default_clock_uses_wall_time(): void
    {
        $start = time();
        $cache = new IdentityCache();
        $cache->set(1, 'knivey', 7, 'account-tag');
        $hit = $cache->get(1, 'knivey');
        $this->assertNotNull($hit);
        $this->assertGreaterThanOrEqual($start, $hit['refreshed_at']);
        $this->assertLessThanOrEqual(time(), $hit['refreshed_at']);
    }

    public function test_cache_get_unknown_nick_returns_null(): void
    {
        $cache = new IdentityCache(static fn (): int => 1);
        $this->assertNull($cache->get(1, 'nobody'));
    }

    public function test_cache_drop_then_get_returns_null_leak_case(): void
    {
        $cache = new IdentityCache(static fn (): int => 1);
        $cache->set(1, 'knivey', 7, 'account-tag');
        $cache->drop(1, 'knivey');
        $this->assertNull($cache->get(1, 'knivey'));
    }

    public function test_cache_carry_nick_moves_binding_to_new_nick(): void
    {
        $cache = new IdentityCache(static fn (): int => 123456);
        $cache->set(1, 'knivey', 7, 'account-tag');
        $cache->carryNick(1, 'knivey', 'knivey_');
        $this->assertNull($cache->get(1, 'knivey'));
        $this->assertSame([
            'user_id' => 7,
            'provenance' => 'account-tag',
            'refreshed_at' => 123456,
        ], $cache->get(1, 'knivey_'));
    }

    public function test_cache_carry_nick_absent_binding_is_noop(): void
    {
        $cache = new IdentityCache(static fn (): int => 1);
        $cache->set(1, 'other', 9, 'hostmask');
        $cache->carryNick(1, 'ghost', 'ghost_');
        $this->assertNull($cache->get(1, 'ghost_'));
        $this->assertNull($cache->get(1, 'ghost'));
    }

    public function test_cache_carry_nick_overwrites_existing_binding_at_target(): void
    {
        $cache = new IdentityCache(static fn (): int => 123456);
        $cache->set(1, 'old', 7, 'account-tag');
        $cache->set(1, 'new', 9, 'hostmask');
        $cache->carryNick(1, 'old', 'new');
        $hit = $cache->get(1, 'new');
        $this->assertNotNull($hit);
        $this->assertSame(7, $hit['user_id']);
        $this->assertSame('account-tag', $hit['provenance']);
    }

    public function test_cache_carry_nick_to_same_lowered_nick_keeps_binding(): void
    {
        // case-only renames ('Knivey' -> 'KNIVEY') lower to identical nicks
        $cache = new IdentityCache(static fn (): int => 123456);
        $cache->set(1, 'knivey', 7, 'account-tag');
        $cache->carryNick(1, 'knivey', 'knivey');
        $this->assertSame([
            'user_id' => 7,
            'provenance' => 'account-tag',
            'refreshed_at' => 123456,
        ], $cache->get(1, 'knivey'));
    }

    public function test_cache_flush_network_clears_only_that_network(): void
    {
        $cache = new IdentityCache(static fn (): int => 1);
        $cache->set(1, 'knivey', 7, 'account-tag');
        $cache->set(2, 'knivey', 9, 'hostmask');
        $cache->flushNetwork(1);
        $this->assertNull($cache->get(1, 'knivey'));
        $this->assertNotNull($cache->get(2, 'knivey'));
    }

    public function test_cache_networks_and_nicks_are_isolated(): void
    {
        $cache = new IdentityCache(static fn (): int => 1);
        $cache->set(1, 'a', 10, 'account-tag');
        $cache->set(2, 'a', 20, 'hostmask');
        $hit1 = $cache->get(1, 'a');
        $hit2 = $cache->get(2, 'a');
        $this->assertNotNull($hit1);
        $this->assertNotNull($hit2);
        $this->assertSame(10, $hit1['user_id']);
        $this->assertSame(20, $hit2['user_id']);
    }

    // ---- EngineConfig ----

    public function test_chain_null_with_all_caps_returns_full_auto_order(): void
    {
        $this->assertSame(
            ['account-tag', 'vhost', 'whox', 'hostmask', 'manual'],
            EngineConfig::chain(null, ['account-tag' => true, 'vhost' => true, 'whox' => true])
        );
    }

    public function test_chain_null_with_no_caps_keeps_only_hostmask_and_manual(): void
    {
        $this->assertSame(
            ['hostmask', 'manual'],
            EngineConfig::chain(null, [])
        );
    }

    public function test_chain_null_filters_each_capability_independently(): void
    {
        $this->assertSame(
            ['account-tag', 'hostmask', 'manual'],
            EngineConfig::chain(null, ['account-tag' => true])
        );
        $this->assertSame(
            ['vhost', 'hostmask', 'manual'],
            EngineConfig::chain(null, ['vhost' => true])
        );
        $this->assertSame(
            ['whox', 'hostmask', 'manual'],
            EngineConfig::chain(null, ['whox' => true])
        );
    }

    public function test_chain_pinned_list_is_returned_exactly_in_given_order(): void
    {
        $caps = ['account-tag' => true, 'vhost' => true, 'whox' => true];
        $this->assertSame(['manual', 'hostmask'], EngineConfig::chain(['manual', 'hostmask'], $caps));
        // pinned ignores caps entirely: whox stays pinned even with no whox capability
        $this->assertSame(['whox'], EngineConfig::chain(['whox'], []));
    }

    public function test_chain_pinned_empty_list_is_honored_exactly(): void
    {
        $this->assertSame([], EngineConfig::chain([], ['account-tag' => true]));
    }

    public function test_chain_unknown_pinned_engine_name_throws_with_name_in_message(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('bogus-engine');
        EngineConfig::chain(['account-tag', 'bogus-engine'], []);
    }

    public function test_default_patterns_returns_gamesurge_vhost_pattern(): void
    {
        $this->assertSame(
            ['gamesurge' => '/^(?P<name>[^.]+)\.[^.]+\.gamesurge$/i'],
            EngineConfig::defaultPatterns()
        );
    }

    // ---- AccountTagEngine ----

    public function test_account_tag_engine_finds_existing_user_by_lowered_account(): void
    {
        $repo = new FakeUserRepo();
        $repo->users['1:zen'] = new FakeUser(7);
        $engine = new AccountTagEngine($repo);
        $this->assertSame(7, $engine->resolve($this->ctx(1, 'ZEN')));
        $this->assertSame([['netId' => 1, 'nameLowered' => 'zen']], $repo->findCalls);
        $this->assertSame([], $repo->createCalls);
    }

    public function test_account_tag_engine_lowering_is_multibyte(): void
    {
        $repo = new FakeUserRepo();
        $repo->users['1:' . mb_strtolower('ÀÉÎ')] = new FakeUser(11);
        $engine = new AccountTagEngine($repo);
        $this->assertSame(11, $engine->resolve($this->ctx(1, 'ÀÉÎ')));
    }

    public function test_account_tag_engine_creates_user_when_allowed(): void
    {
        $repo = new FakeUserRepo();
        $engine = new AccountTagEngine($repo);
        $id = $engine->resolve($this->ctx(1, 'NewGuy', true));
        $this->assertNotNull($id);
        $this->assertSame([['netId' => 1, 'account' => 'NewGuy']], $repo->createCalls);
        // created user is findable afterwards under the lowered account name
        $this->assertSame($id, $engine->resolve($this->ctx(1, 'newguy')));
    }

    public function test_account_tag_engine_does_not_create_when_not_allowed(): void
    {
        $repo = new FakeUserRepo();
        $engine = new AccountTagEngine($repo);
        $this->assertNull($engine->resolve($this->ctx(1, 'NewGuy', false)));
        $this->assertSame([], $repo->createCalls);
    }

    public function test_account_tag_engine_null_account_returns_null_without_repo_call(): void
    {
        $repo = new FakeUserRepo();
        $engine = new AccountTagEngine($repo);
        $this->assertNull($engine->resolve($this->ctx(1, null)));
        $this->assertSame([], $repo->findCalls);
        $this->assertSame([], $repo->createCalls);
    }

    public function test_account_tag_engine_empty_account_returns_null_without_repo_call(): void
    {
        $repo = new FakeUserRepo();
        $engine = new AccountTagEngine($repo);
        $this->assertNull($engine->resolve($this->ctx(1, '')));
        $this->assertSame([], $repo->findCalls);
        $this->assertSame([], $repo->createCalls);
    }

    // ---- IdentityService ----

    public function test_service_cache_hit_returns_cached_identity_without_calling_engines(): void
    {
        $cache = new IdentityCache(static fn (): int => 1);
        $cache->set(1, 'knivey', 7, 'hostmask');
        $engine = new FakeEngineOne(42);
        $service = new IdentityService($cache, [$engine]);
        $this->assertSame(
            ['user_id' => 7, 'provenance' => 'hostmask'],
            $service->resolve($this->ctx(1))
        );
        $this->assertSame(0, $engine->calls);
    }

    public function test_service_first_engine_hit_wins_and_is_cached_with_its_provenance(): void
    {
        $cache = new IdentityCache(static fn (): int => 1);
        $miss = new FakeEngineOne(null);
        $hit = new FakeEngineTwo(5);
        $service = new IdentityService($cache, [$miss, $hit]);
        $this->assertSame(
            ['user_id' => 5, 'provenance' => 'fake-two'],
            $service->resolve($this->ctx(1, null, false, 'someone'))
        );
        $cached = $cache->get(1, 'someone');
        $this->assertNotNull($cached);
        $this->assertSame(5, $cached['user_id']);
        $this->assertSame('fake-two', $cached['provenance']);
        $this->assertSame(1, $miss->calls);
        $this->assertSame(1, $hit->calls);
    }

    public function test_service_all_engines_miss_returns_null_and_writes_no_cache(): void
    {
        $cache = new IdentityCache(static fn (): int => 1);
        $service = new IdentityService($cache, [new FakeEngineOne(null), new FakeEngineTwo(null)]);
        $this->assertNull($service->resolve($this->ctx(1, null, false, 'someone')));
        $this->assertNull($cache->get(1, 'someone'));
    }

    public function test_service_with_no_engines_returns_null(): void
    {
        $cache = new IdentityCache(static fn (): int => 1);
        $service = new IdentityService($cache, []);
        $this->assertNull($service->resolve($this->ctx(1, null, false, 'someone')));
    }

    public function test_service_engine_hit_is_cached_so_second_resolve_skips_engines(): void
    {
        $cache = new IdentityCache(static fn (): int => 1);
        $engine = new FakeEngineTwo(5);
        $service = new IdentityService($cache, [$engine]);
        $first = $service->resolve($this->ctx(1, null, false, 'someone'));
        $second = $service->resolve($this->ctx(1, null, false, 'someone'));
        $this->assertSame($first, $second);
        $this->assertSame(1, $engine->calls);
    }

    private function ctx(
        int $netId = 1,
        ?string $account = null,
        bool $allowCreate = false,
        string $nickLowered = 'knivey',
    ): ResolveContext {
        return new ResolveContext(
            networkId: $netId,
            nick: 'Knivey',
            nickLowered: $nickLowered,
            identHost: 'user@host',
            account: $account,
            client: new \stdClass(),
            allowCreate: $allowCreate,
        );
    }
}

final class FakeUser
{
    public function __construct(public int $id) {}
}

final class FakeUserRepo implements UserRepo
{
    /** @var array<string, FakeUser> keyed "<netId>:<nameLowered>" */
    public array $users = [];

    /** @var list<array{netId: int, nameLowered: string}> */
    public array $findCalls = [];

    /** @var list<array{netId: int, account: string}> */
    public array $createCalls = [];

    private int $nextId = 100;

    public function findForNetwork(int $netId, string $nameLowered): ?object
    {
        $this->findCalls[] = ['netId' => $netId, 'nameLowered' => $nameLowered];
        return $this->users[$netId . ':' . $nameLowered] ?? null;
    }

    public function createFromAccount(int $netId, string $account): object
    {
        $this->createCalls[] = ['netId' => $netId, 'account' => $account];
        $user = new FakeUser($this->nextId++);
        $this->users[$netId . ':' . mb_strtolower($account)] = $user;
        return $user;
    }
}

final class FakeEngineOne implements Engine
{
    public const PROVENANCE = 'fake-one';
    public int $calls = 0;

    public function __construct(private readonly ?int $result) {}

    public function resolve(ResolveContext $ctx): ?int
    {
        $this->calls++;
        return $this->result;
    }
}

final class FakeEngineTwo implements Engine
{
    public const PROVENANCE = 'fake-two';
    public int $calls = 0;

    public function __construct(private readonly ?int $result) {}

    public function resolve(ResolveContext $ctx): ?int
    {
        $this->calls++;
        return $this->result;
    }
}
