<?php

namespace Tests\User;

use Amp\Future;
use library\user\Engine;
use library\user\EngineConfig;
use library\user\engines\AccountTagEngine;
use library\user\engines\HostmaskEngine;
use library\user\engines\VhostPatternEngine;
use library\user\engines\WhoxEngine;
use library\user\ResolveContext;
use library\user\UserHostmaskRepo;
use library\user\UserRepo;

require_once __DIR__ . '/../../vendor/autoload.php';

class EnginesTest extends \PHPUnit\Framework\TestCase
{
    // ---- engine PROVENANCE contract ----

    public function test_every_enumerated_engine_declares_its_provenance(): void
    {
        $expected = [
            AccountTagEngine::class => 'account-tag',
            VhostPatternEngine::class => 'vhost',
            WhoxEngine::class => 'whox',
            HostmaskEngine::class => 'hostmask',
        ];
        foreach (array_keys($expected) as $class) {
            $this->assertNotSame('', $class::PROVENANCE, "$class must declare PROVENANCE");
            $this->assertSame($expected[$class], $class::PROVENANCE);
        }
    }

    // ---- VhostPatternEngine ----

    private function gamesurgePattern(): string
    {
        // wiring (Task 6) translates EngineConfig's name-keyed defaults to
        // network ids; tests use the id-keyed form directly
        return EngineConfig::defaultPatterns()['gamesurge'];
    }

    public function test_vhost_match_finds_existing_user_by_captured_name(): void
    {
        $repo = new FakeEngineUserRepo();
        $repo->users['1:zen'] = new FakeEngineUser(7);
        $engine = new VhostPatternEngine([1 => $this->gamesurgePattern()], $repo);
        $this->assertSame(7, $engine->resolve($this->ctx(1, identHost: 'ident@zen.user.gamesurge')));
        $this->assertSame([['netId' => 1, 'nameLowered' => 'zen']], $repo->findCalls);
        $this->assertSame([], $repo->createCalls);
    }

    public function test_vhost_pattern_matches_host_case_insensitively_and_lowers_capture(): void
    {
        $repo = new FakeEngineUserRepo();
        $repo->users['1:knivey'] = new FakeEngineUser(9);
        $engine = new VhostPatternEngine([1 => $this->gamesurgePattern()], $repo);
        $this->assertSame(9, $engine->resolve($this->ctx(1, identHost: 'ident@Knivey.Void.Gamesurge')));
    }

    public function test_vhost_capture_lowering_is_multibyte(): void
    {
        $repo = new FakeEngineUserRepo();
        $repo->users['1:' . mb_strtolower('ÀÉÎ')] = new FakeEngineUser(11);
        $engine = new VhostPatternEngine([1 => $this->gamesurgePattern()], $repo);
        $this->assertSame(11, $engine->resolve($this->ctx(1, identHost: 'ident@ÀÉÎ.void.gamesurge')));
    }

    public function test_vhost_creates_user_when_allowed(): void
    {
        $repo = new FakeEngineUserRepo();
        $engine = new VhostPatternEngine([1 => $this->gamesurgePattern()], $repo);
        $id = $engine->resolve($this->ctx(1, identHost: 'ident@NewGuy.user.gamesurge', allowCreate: true));
        $this->assertNotNull($id);
        $this->assertSame([['netId' => 1, 'account' => 'NewGuy']], $repo->createCalls);
        // created user is findable afterwards under the lowered captured name
        $this->assertSame($id, $engine->resolve($this->ctx(1, identHost: 'ident@newguy.user.gamesurge')));
    }

    public function test_vhost_does_not_create_when_not_allowed(): void
    {
        $repo = new FakeEngineUserRepo();
        $engine = new VhostPatternEngine([1 => $this->gamesurgePattern()], $repo);
        $this->assertNull($engine->resolve($this->ctx(1, identHost: 'ident@NewGuy.user.gamesurge', allowCreate: false)));
        $this->assertSame([['netId' => 1, 'nameLowered' => 'newguy']], $repo->findCalls);
        $this->assertSame([], $repo->createCalls);
    }

    public function test_vhost_host_without_match_returns_null_without_repo_call(): void
    {
        $repo = new FakeEngineUserRepo();
        $engine = new VhostPatternEngine([1 => $this->gamesurgePattern()], $repo);
        $this->assertNull($engine->resolve($this->ctx(1, identHost: 'ident@plain.isp.example')));
        $this->assertSame([], $repo->findCalls);
        $this->assertSame([], $repo->createCalls);
    }

    public function test_vhost_missing_pattern_for_network_returns_null_without_repo_call(): void
    {
        $repo = new FakeEngineUserRepo();
        $engine = new VhostPatternEngine([1 => $this->gamesurgePattern()], $repo);
        $this->assertNull($engine->resolve($this->ctx(2, identHost: 'ident@zen.user.gamesurge')));
        $this->assertSame([], $repo->findCalls);
        $this->assertSame([], $repo->createCalls);
    }

    public function test_vhost_identhost_without_at_returns_null_without_repo_call(): void
    {
        $repo = new FakeEngineUserRepo();
        $engine = new VhostPatternEngine([1 => $this->gamesurgePattern()], $repo);
        $this->assertNull($engine->resolve($this->ctx(1, identHost: 'userhost-no-at-sign')));
        $this->assertSame([], $repo->findCalls);
        $this->assertSame([], $repo->createCalls);
    }

    public function test_vhost_null_identhost_returns_null_without_repo_call(): void
    {
        $repo = new FakeEngineUserRepo();
        $engine = new VhostPatternEngine([1 => $this->gamesurgePattern()], $repo);
        $this->assertNull($engine->resolve($this->ctx(1, identHost: null)));
        $this->assertSame([], $repo->findCalls);
        $this->assertSame([], $repo->createCalls);
    }

    // ---- WhoxEngine ----

    public function test_whox_without_capability_returns_null_without_querying(): void
    {
        $repo = new FakeEngineUserRepo();
        $client = new FakeWhoxClient(hasWhox: false);
        $engine = new WhoxEngine($client, $repo);
        $result = \Amp\async(fn (): ?int => $engine->resolve($this->ctx(1, client: $client)))->await();
        $this->assertNull($result);
        $this->assertSame(0, $client->whoxCalls);
        $this->assertSame([], $repo->findCalls);
        $this->assertSame([], $repo->createCalls);
    }

    public function test_whox_uses_first_entry_account_to_find_user(): void
    {
        $repo = new FakeEngineUserRepo();
        $repo->users['1:zen'] = new FakeEngineUser(7);
        $client = new FakeWhoxClient(entries: [['a' => 'Zen'], ['a' => 'other']]);
        $engine = new WhoxEngine($client, $repo);
        $result = \Amp\async(fn (): ?int => $engine->resolve($this->ctx(1, client: $client)))->await();
        $this->assertSame(7, $result);
        $this->assertSame(1, $client->whoxCalls);
        $this->assertSame('Knivey', $client->lastTarget);
        $this->assertSame('a', $client->lastFields);
        $this->assertSame([['netId' => 1, 'nameLowered' => 'zen']], $repo->findCalls);
        $this->assertSame([], $repo->createCalls);
    }

    public function test_whox_creates_user_when_allowed(): void
    {
        $repo = new FakeEngineUserRepo();
        $client = new FakeWhoxClient(entries: [['a' => 'NewGuy']]);
        $engine = new WhoxEngine($client, $repo);
        $ctx = $this->ctx(1, client: $client, allowCreate: true);
        $id = \Amp\async(fn (): ?int => $engine->resolve($ctx))->await();
        $this->assertNotNull($id);
        $this->assertSame([['netId' => 1, 'account' => 'NewGuy']], $repo->createCalls);
    }

    public function test_whox_does_not_create_when_not_allowed(): void
    {
        $repo = new FakeEngineUserRepo();
        $client = new FakeWhoxClient(entries: [['a' => 'NewGuy']]);
        $engine = new WhoxEngine($client, $repo);
        $ctx = $this->ctx(1, client: $client, allowCreate: false);
        $result = \Amp\async(fn (): ?int => $engine->resolve($ctx))->await();
        $this->assertNull($result);
        $this->assertSame([], $repo->createCalls);
    }

    public function test_whox_first_entry_null_account_returns_null(): void
    {
        $repo = new FakeEngineUserRepo();
        // the second entry must NOT be consulted; WHOX asked for one nick
        $client = new FakeWhoxClient(entries: [['a' => null], ['a' => 'Zen']]);
        $engine = new WhoxEngine($client, $repo);
        $result = \Amp\async(fn (): ?int => $engine->resolve($this->ctx(1, client: $client)))->await();
        $this->assertNull($result);
        $this->assertSame([], $repo->findCalls);
        $this->assertSame([], $repo->createCalls);
    }

    public function test_whox_logged_out_sentinel_accounts_return_null(): void
    {
        $repo = new FakeEngineUserRepo();
        $client = new FakeWhoxClient(entries: [['a' => '0']]);
        $engine = new WhoxEngine($client, $repo);
        $result = \Amp\async(fn (): ?int => $engine->resolve($this->ctx(1, client: $client)))->await();
        $this->assertNull($result);
        $client = new FakeWhoxClient(entries: [['a' => '*']]);
        $engine = new WhoxEngine($client, $repo);
        $result = \Amp\async(fn (): ?int => $engine->resolve($this->ctx(1, client: $client)))->await();
        $this->assertNull($result);
        $this->assertSame([], $repo->findCalls);
        $this->assertSame([], $repo->createCalls);
    }

    public function test_whox_empty_entry_list_returns_null(): void
    {
        $repo = new FakeEngineUserRepo();
        $client = new FakeWhoxClient(entries: []);
        $engine = new WhoxEngine($client, $repo);
        $result = \Amp\async(fn (): ?int => $engine->resolve($this->ctx(1, client: $client)))->await();
        $this->assertNull($result);
        $this->assertSame([], $repo->findCalls);
        $this->assertSame([], $repo->createCalls);
    }

    public function test_whox_context_without_whox_surface_falls_back_to_injected_client(): void
    {
        $repo = new FakeEngineUserRepo();
        $repo->users['1:zen'] = new FakeEngineUser(7);
        $client = new FakeWhoxClient(entries: [['a' => 'Zen']]);
        $engine = new WhoxEngine($client, $repo);
        // bare stdClass in the context snapshot, e.g. contexts built
        // before the connection's client was available
        $result = \Amp\async(fn (): ?int => $engine->resolve($this->ctx(1)))->await();
        $this->assertSame(7, $result);
        $this->assertSame(1, $client->whoxCalls);
    }

    // ---- HostmaskEngine ----

    public function test_hostmask_exact_full_string_match_resolves_user(): void
    {
        $repo = new FakeEngineMaskRepo();
        $repo->masks[1] = [['mask' => 'Knivey!user@host.example', 'user_id' => 7, 'paranoid' => false]];
        $engine = new HostmaskEngine($repo);
        $this->assertSame(7, $engine->resolve($this->ctx(1)));
    }

    public function test_hostmask_glob_mask_matches(): void
    {
        $repo = new FakeEngineMaskRepo();
        $repo->masks[1] = [['mask' => '*!*@*.example.com', 'user_id' => 7, 'paranoid' => false]];
        $engine = new HostmaskEngine($repo);
        $this->assertSame(7, $engine->resolve($this->ctx(1, nick: 'Anyone', identHost: 'ident@foo.example.com')));
    }

    public function test_hostmask_match_is_case_insensitive(): void
    {
        $repo = new FakeEngineMaskRepo();
        $repo->masks[1] = [['mask' => 'knivey!*@*.example.com', 'user_id' => 7, 'paranoid' => false]];
        $engine = new HostmaskEngine($repo);
        $this->assertSame(7, $engine->resolve($this->ctx(1, nick: 'KNIVEY', identHost: 'user@HOST.Example.Com')));
    }

    public function test_hostmask_no_matching_mask_returns_null(): void
    {
        $repo = new FakeEngineMaskRepo();
        $repo->masks[1] = [['mask' => 'someone!*@elsewhere.example', 'user_id' => 7, 'paranoid' => false]];
        $engine = new HostmaskEngine($repo);
        $this->assertNull($engine->resolve($this->ctx(1)));
    }

    public function test_hostmask_nick_specific_mask_does_not_match_other_nick(): void
    {
        $repo = new FakeEngineMaskRepo();
        $repo->masks[1] = [['mask' => 'nicka!*@*.example.com', 'user_id' => 1, 'paranoid' => false]];
        $engine = new HostmaskEngine($repo);
        // nickb must not inherit nicka's identity from a nick-anchored mask
        $this->assertNull($engine->resolve($this->ctx(1, nick: 'nickb', identHost: 'user@evil.example.com')));
    }

    public function test_hostmask_first_matching_mask_wins(): void
    {
        $repo = new FakeEngineMaskRepo();
        $repo->masks[1] = [
            ['mask' => 'other!*@*', 'user_id' => 99, 'paranoid' => false],
            ['mask' => 'Knivey!user@host.example', 'user_id' => 7, 'paranoid' => false],
            ['mask' => '*!*@host.example', 'user_id' => 8, 'paranoid' => false],
        ];
        $engine = new HostmaskEngine($repo);
        $this->assertSame(7, $engine->resolve($this->ctx(1)));
    }

    public function test_hostmask_masks_are_scoped_per_network(): void
    {
        $repo = new FakeEngineMaskRepo();
        $repo->masks[1] = [['mask' => '*!*@host.example', 'user_id' => 7, 'paranoid' => false]];
        $engine = new HostmaskEngine($repo);
        $this->assertNull($engine->resolve($this->ctx(2)));
        $this->assertSame([['netId' => 2, 'identHost' => 'user@host.example']], $repo->findCalls);
    }

    public function test_hostmask_null_identhost_returns_null_without_repo_call(): void
    {
        $repo = new FakeEngineMaskRepo();
        $engine = new HostmaskEngine($repo);
        $this->assertNull($engine->resolve($this->ctx(1, identHost: null)));
        $this->assertSame([], $repo->findCalls);
    }

    public function test_hostmask_mask_of_paranoid_owner_is_skipped_even_on_exact_match(): void
    {
        $repo = new FakeEngineMaskRepo();
        $repo->masks[1] = [['mask' => 'Knivey!user@host.example', 'user_id' => 7, 'paranoid' => true]];
        $engine = new HostmaskEngine($repo);
        // paranoid forces manual auth each connect: a mask stored before
        // the toggle must never resolve
        $this->assertNull($engine->resolve($this->ctx(1)));
    }

    public function test_hostmask_paranoid_mask_skipped_but_later_normal_match_still_resolves(): void
    {
        $repo = new FakeEngineMaskRepo();
        $repo->masks[1] = [
            ['mask' => '*!*@host.example', 'user_id' => 7, 'paranoid' => true],
            ['mask' => '*!*@host.example', 'user_id' => 8, 'paranoid' => false],
        ];
        $engine = new HostmaskEngine($repo);
        $this->assertSame(8, $engine->resolve($this->ctx(1)));
    }

    private function ctx(
        int $netId = 1,
        string $nick = 'Knivey',
        ?string $identHost = 'user@host.example',
        bool $allowCreate = false,
        ?object $client = null,
    ): ResolveContext {
        return new ResolveContext(
            networkId: $netId,
            nick: $nick,
            nickLowered: mb_strtolower($nick),
            identHost: $identHost,
            account: null,
            client: $client ?? new \stdClass(),
            allowCreate: $allowCreate,
        );
    }
}

final class FakeEngineUser
{
    public function __construct(public int $id)
    {
    }
}

final class FakeEngineUserRepo implements UserRepo
{
    /** @var array<string, FakeEngineUser> keyed "<netId>:<nameLowered>" */
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
        $user = new FakeEngineUser($this->nextId++);
        $this->users[$netId . ':' . mb_strtolower($account)] = $user;
        return $user;
    }
}

final class FakeWhoxClient
{
    public int $whoxCalls = 0;
    public string $lastTarget = '';
    public string $lastFields = '';

    /** @param list<array<string, mixed>> $entries */
    public function __construct(
        public readonly bool $hasWhox = true,
        public readonly array $entries = [],
    ) {
    }

    public function hasOption(string $option): bool
    {
        return $option === 'WHOX' && $this->hasWhox;
    }

    /** @return Future<list<array<string, mixed>>> */
    public function whox(string $target, string $fields = 'uhnaf'): Future
    {
        $this->whoxCalls++;
        $this->lastTarget = $target;
        $this->lastFields = $fields;
        return Future::complete($this->entries);
    }
}

final class FakeEngineMaskRepo implements UserHostmaskRepo
{
    /** @var array<int, list<array{mask: string, user_id: int, paranoid: bool}>> */
    public array $masks = [];

    /** @var list<array{netId: int, identHost: string}> */
    public array $findCalls = [];

    /** @var list<int> */
    public array $deleteCalls = [];

    public function findForHost(int $netId, string $identHost): array
    {
        $this->findCalls[] = ['netId' => $netId, 'identHost' => $identHost];
        return $this->masks[$netId] ?? [];
    }

    public function deleteForUser(int $userId): void
    {
        $this->deleteCalls[] = $userId;
    }
}
