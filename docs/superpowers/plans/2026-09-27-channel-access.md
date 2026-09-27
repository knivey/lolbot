# Channel Access (user system step 4) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Channel-scoped access control — the code-defined flag registry with groups, per-channel flag grants, the `#[Acl(flag, channel: true)]` form, the in-channel `.cflags` grant command, and registry validation for the existing flag surfaces.

**Architecture:** Pure flag registry first (`Flags`: definitions + transitive group expansion + wildcard), then a `channel_flags` entity/repo/migration (grants per bot's channel row + user, mirroring `users.flags`), then the unified check (network flags ∪ channel grants → expand → check) wired into `Access`/the acl middleware, then the `.cflags` chat command with can't-exceed-your-own-power grant rules, and registry validation retrofitted onto `user:flags` and PM `setflags`.

**Tech Stack:** PHP 8.1, Doctrine ORM (attributes + hand-written migrations, sqlite + postgresql), cmdr v5 middleware/attributes, PHPUnit 10.

**Spec:** `docs/superpowers/specs/2026-09-26-user-system-design.md` — section "Flag registry & channel-scoped access (decided 2026-09-27)". The plan argues from the spec; executors read both.

## Global Constraints

- PHP 8.1 floor; no syntax above 8.1 (readonly promotion OK, no 8.2+ features).
- Entity style: plain public props + `#[ORM\Entity]`/`#[ORM\Table]`/`#[ORM\Column]` exactly like `entities/User.php`; flags columns are `list<string>` JSON arrays (the `User.flags` / `ApiKey.scopes` precedent) — never comma-strings.
- Migration style: hand-written class in `Migrations/`, comparator pattern per `Migrations/Version20260927120000.php`; must be safe on sqlite AND postgresql; `down()` reverses everything. We have both engines locally: sqlite dev DB (copy to scratch) and pg scratch db `lolbot_test`/`lolpass` on localhost:5432 (verify pattern: `/tmp/opencode/pg_verify.php` from 2026-09-27).
- All name comparisons use `mb_strtolower` (shared lowering rule).
- Deny strings are our own templated text — no `\2\2` marking needed (AGENTS.md rule).
- Preserve every existing comment verbatim (AGENTS.md); never `git add -f`; composer.lock is gitignored.
- Dev DB untouched — sqlite scratch copies under `/tmp/opencode`, pg scratch `lolbot_test` only.
- Verification per task: `vendor/bin/phpunit` full suite green; `php -d memory_limit=1G vendor/bin/phpstan analyse <touched paths> --no-progress` zero NEW findings vs `git stash` baseline; TDD red before green.
- Commits: `git -c user.name="knivey" -c user.email="knivey@botops.net" commit -m "..."` — only the task's files, no push (orchestrator pushes).
- Commands find their per-network bundle via `$bot->userSystem` (`library\user\UserSystem`); there is deliberately NO global fallback (fail closed, never cross-network).

## Review Focus

The five failure modes the spec implies but ordinary happy-path tests miss. Each is pinned by a test in its owning task:

1. **PM usage of a channel-scoped command must deny, not crash or silently allow** — pinned in Task 4 (middleware test: PmEvent in extraArgs → deny string "channel only").
2. **A channel the bot isn't configured for must fail closed** — pinned in Task 4 (channelByName miss → deny) and Task 5 (`.cflags` in unconfigured channel replies "not configured" and persists nothing).
3. **Cyclic flag definitions must not hang expansion** — pinned in Task 1 (`Flags::define('a', ['b']); Flags::define('b', ['a'])` → `expand()` terminates, `passes()` false for both).
4. **Self-escalation / exceeding your own power must refuse the WHOLE op batch and persist nothing** — pinned in Task 5 (channel `quotes` holder tries `+admin` → refused, row unchanged).
5. **Cross-network leakage must stay impossible** — the middleware resolves user, channel and grants from the SAME bundle (never mixes two networks' state) — pinned in Task 4 (middleware test where a second network's bundle is present: resolution must use the event's sender bundle only).

---

### Task 1: Flags registry (pure)

**Files:**
- Create: `library/user/Flags.php`
- Test: `tests/User/FlagsTest.php`

**Interfaces:**
- Consumes: nothing (pure static).
- Produces (exact, later tasks rely on these):
  - `Flags::defined(string $flag): bool`
  - `Flags::definitions(): array<string, list<string>>` — name => granted names (`'*'` = all)
  - `Flags::define(string $name, array $grants): void` — programmatic definition (scripts register their own flags at load); redefining an existing name overwrites (idempotent registration per spawn)
  - `Flags::reset(): void` — restore DEFAULTS only (tests)
  - `Flags::expand(array $flags): array<int, string>` — transitive group expansion, cycle-guarded, input filtered to unique strings, `'*'` preserved literally
  - `Flags::passes(array $flags, string $flag): bool` — true iff `'*'` or `$flag` is in `expand($flags)`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/User/FlagsTest.php
use library\user\Flags;
use PHPUnit\Framework\TestCase;

class FlagsTest extends TestCase
{
    protected function tearDown(): void
    {
        Flags::reset();
    }

    public function test_admin_is_defined_and_grants_wildcard(): void
    {
        $this->assertTrue(Flags::defined('admin'));
        $this->assertSame(['*'], Flags::definitions()['admin']);
    }

    public function test_undefined_flag_is_not_defined_and_never_passes(): void
    {
        $this->assertFalse(Flags::defined('nope'));
        $this->assertFalse(Flags::passes(['nope'], 'admin'));
    }

    public function test_plain_flag_passes_for_holder(): void
    {
        $this->assertTrue(Flags::passes(['quotes'], 'quotes'));
        $this->assertFalse(Flags::passes(['quotes'], 'kick'));
    }

    public function test_wildcard_grants_everything(): void
    {
        $this->assertTrue(Flags::passes(['admin'], 'anything.at.all'));
        $this->assertTrue(Flags::passes(['admin'], 'admin'));
    }

    public function test_groups_expand_transitively(): void
    {
        Flags::define('manager', ['quotes', 'set']);
        Flags::define('boss', ['manager', 'kick']);
        // boss -> manager -> quotes/set, boss -> kick
        $this->assertTrue(Flags::passes(['boss'], 'quotes'));
        $this->assertTrue(Flags::passes(['boss'], 'set'));
        $this->assertTrue(Flags::passes(['boss'], 'kick'));
        $this->assertFalse(Flags::passes(['boss'], 'admin'));
        $this->assertSame(
            ['boss', 'manager', 'kick', 'quotes', 'set'],
            Flags::expand(['boss']),
        );
    }

    public function test_cycles_terminate(): void
    {
        Flags::define('a', ['b']);
        Flags::define('b', ['a']);
        $this->assertSame(['a', 'b'], Flags::expand(['a']));
        $this->assertFalse(Flags::passes(['a'], 'c'));
    }

    public function test_expand_filters_non_strings_and_dedupes(): void
    {
        Flags::define('g', ['x']);
        $this->assertSame(['x', 'g'], Flags::expand(['x', 3, 'x', 'g', null, true]));
    }

    public function test_define_overwrites_and_reset_restores_defaults(): void
    {
        Flags::define('admin', []);
        $this->assertSame([], Flags::definitions()['admin']);
        Flags::reset();
        $this->assertSame(['*'], Flags::definitions()['admin']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/User/FlagsTest.php`
Expected: FAIL — `Class "library\user\Flags" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
// library/user/Flags.php
namespace library\user;

/*
 * Code-defined flag registry: flags are ACL names, optionally granting
 * other flags (groups). 'admin' grants '*' (everything). Definitions
 * live in code — admins GRANT flags, they don't invent them; scripts
 * may register their own via Flags::define() at load time.
 *
 * Check semantics (spec 2026-09-27): a check for flag X passes when
 * the holder's flag set (network flags UNION channel grants — the
 * fall-through) expanded through groups contains X or '*'.
 */

final class Flags
{
    /** @var array<string, list<string>> */
    public const DEFAULTS = [
        'admin' => ['*'],
    ];

    /** @var array<string, list<string>>|null */
    private static ?array $definitions = null;

    public static function defined(string $flag): bool
    {
        return array_key_exists($flag, self::definitions());
    }

    /** @return array<string, list<string>> */
    public static function definitions(): array
    {
        if (self::$definitions === null) {
            self::$definitions = self::DEFAULTS;
        }
        return self::$definitions;
    }

    /**
     * @param list<string> $grants
     */
    public static function define(string $name, array $grants): void
    {
        self::definitions(); // ensure initialized before write
        self::$definitions[$name] = array_values(array_filter($grants, 'is_string'));
    }

    public static function reset(): void
    {
        self::$definitions = self::DEFAULTS;
    }

    /**
     * Transitive group expansion, cycle-guarded. Non-strings filtered,
     * order preserved (first-seen), '*' kept literally.
     *
     * @param array<int, mixed> $flags
     * @return list<string>
     */
    public static function expand(array $flags): array
    {
        $set = [];
        foreach ($flags as $f) {
            if (is_string($f) && $f !== '' && !isset($set[$f])) {
                $set[$f] = true;
            }
        }
        $defs = self::definitions();
        // worklist: a flag already-expanded never re-expands (cycle guard)
        $expanded = [];
        $queue = array_keys($set);
        while ($queue !== []) {
            $f = array_shift($queue);
            if (isset($expanded[$f])) {
                continue;
            }
            $expanded[$f] = true;
            foreach ($defs[$f] ?? [] as $granted) {
                if (!isset($set[$granted])) {
                    $set[$granted] = true;
                    $queue[] = $granted;
                }
            }
        }
        return array_keys($set);
    }

    /**
     * @param array<int, mixed> $flags
     */
    public static function passes(array $flags, string $flag): bool
    {
        $expanded = self::expand($flags);
        return in_array('*', $expanded, true) || in_array($flag, $expanded, true);
    }
}
```

Note on `expand()` order: grants discovered during expansion append in discovery order; the exact order in `test_groups_expand_transitively` must match the implementation (seed order first, then discovery). If the assertion order differs, adjust the assertion to a sort-insensitive comparison (`assertSameCanonicalizing`) — semantics is membership, not order.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/User/FlagsTest.php`
Expected: PASS (8 tests).

- [ ] **Step 5: Full suite + phpstan + commit**

Run: `vendor/bin/phpunit` (all green) and `php -d memory_limit=1G vendor/bin/phpstan analyse library/user/Flags.php tests/User/FlagsTest.php --no-progress` (clean).

```bash
git add library/user/Flags.php tests/User/FlagsTest.php
git -c user.name="knivey" -c user.email="knivey@botops.net" commit -m "feat(user): code-defined flag registry with groups"
```

---

### Task 2: ChannelFlag entity, repo, migration

**Files:**
- Create: `entities/ChannelFlag.php`
- Create: `library/user/ChannelFlagRepo.php`
- Create: `library/user/DoctrineChannelFlagRepo.php`
- Create: `Migrations/Version20260928120000.php`
- Modify: `library/user/UserRepos.php` (third ctor param)
- Modify: `library/user/UserSystemFactory.php:46-49` (construct the repo)
- Test: `tests/User/ChannelFlagEntityTest.php` + scratch scripts under `/tmp/opencode`

**Interfaces:**
- Consumes: `UserRepo`-style repo pattern (`library/user/UserHostmaskRepo.php` as template), entity style of `entities/User.php` / `entities/UserHostmask.php`, migration pattern of `Migrations/Version20260927120000.php`.
- Produces:
  - Entity `lolbot\entities\ChannelFlag`, table `channel_flags`: `int $id` PK, `int $channel_id`, `int $user_id`, `list<string> $flags = []` (JSON), `string $addedBy` (column `added_by`), `\DateTimeImmutable $created` (ctor sets `new \DateTimeImmutable()`), unique constraint `channel_flags_channel_user_uniq (channel_id, user_id)`.
  - `interface ChannelFlagRepo { /** @return object{id: int, flags: list<mixed>}|null */ public function findForChannelUser(int $channelId, int $userId): ?object; }`
  - `DoctrineChannelFlagRepo(EntityManager $em)` implements it (returns the `ChannelFlag` entity — it satisfies the shape).
  - `UserRepos::__construct(public UserRepo $users, public UserHostmaskRepo $masks, public ChannelFlagRepo $channelFlags)` — find every `new UserRepos(` site (factory + tests) and update.

- [ ] **Step 1: Write the failing entity/repo test**

```php
<?php
// tests/User/ChannelFlagEntityTest.php
use library\user\ChannelFlagRepo;
use library\user\UserRepos;
use lolbot\entities\ChannelFlag;
use PHPUnit\Framework\TestCase;

class ChannelFlagEntityTest extends TestCase
{
    public function test_entity_shape(): void
    {
        $cf = new ChannelFlag();
        $cf->channel_id = 7;
        $cf->user_id = 9;
        $cf->flags = ['admin'];
        $cf->addedBy = 'bootstrap';
        $this->assertSame(['admin'], $cf->flags);
        $this->assertInstanceOf(\DateTimeImmutable::class, $cf->created);
    }

    public function test_userrepos_carries_channel_repo(): void
    {
        $users = $this->createStub(\library\user\UserRepo::class);
        $masks = $this->createStub(\library\user\UserHostmaskRepo::class);
        $chans = $this->createStub(ChannelFlagRepo::class);
        $repos = new UserRepos($users, $masks, $chans);
        $this->assertSame($chans, $repos->channelFlags);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/User/ChannelFlagEntityTest.php`
Expected: FAIL — `Class "lolbot\entities\ChannelFlag" not found`; then (after entity) `Unknown named parameter $channelFlags`.

- [ ] **Step 3: Implement entity, interface, Doctrine impl**

`entities/ChannelFlag.php` — copy `entities/User.php` structure exactly (docblock, readonly-comment style, `@psalm-suppress PropertyNotSetInConstructor`):

```php
<?php
namespace lolbot\entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 */
#[ORM\Entity]
#[ORM\Table("channel_flags")]
#[ORM\UniqueConstraint(name: "channel_flags_channel_user_uniq", columns: ["channel_id", "user_id"])]
class ChannelFlag
{
    //Cant be readonly due to doctrine bug on remove
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(updatable: false)]
    public int $id;

    #[ORM\Column]
    public int $channel_id;

    #[ORM\Column]
    public int $user_id;

    /** @var list<string> */
    #[ORM\Column(type: "json")]
    public array $flags = [];

    #[ORM\Column(name: "added_by")]
    public string $addedBy = "";

    #[ORM\Column(updatable: false)]
    public \DateTimeImmutable $created;

    public function __construct()
    {
        $this->created = new \DateTimeImmutable();
    }
}
```

`library/user/ChannelFlagRepo.php` (mirror `UserHostmaskRepo.php` docblock style):

```php
<?php
namespace library\user;

interface ChannelFlagRepo
{
    /**
     * Grant row for one user in one channel (exact match on the unique
     * index); null when the user holds nothing in the channel.
     *
     * @return object{id: int, flags: list<mixed>}|null
     */
    public function findForChannelUser(int $channelId, int $userId): ?object;
}
```

`library/user/DoctrineChannelFlagRepo.php` (mirror `DoctrineUserRepo.php`):

```php
<?php
namespace library\user;

use Doctrine\ORM\EntityManager;
use lolbot\entities\ChannelFlag;

final class DoctrineChannelFlagRepo implements ChannelFlagRepo
{
    public function __construct(private EntityManager $em)
    {
    }

    public function findForChannelUser(int $channelId, int $userId): ?object
    {
        return $this->em->getRepository(ChannelFlag::class)->findOneBy([
            'channel_id' => $channelId,
            'user_id' => $userId,
        ]);
    }
}
```

Update `UserRepos::__construct` to the three-param promoted form above (preserve every existing comment in the file; the docblock stays, only the ctor signature gains the param). Update `UserSystemFactory::create()`:

```php
$users = new DoctrineUserRepo($this->em);
$masks = new DoctrineUserHostmaskRepo($this->em);
$channelFlags = new DoctrineChannelFlagRepo($this->em);
$repos = new UserRepos($users, $masks, $channelFlags);
$repos->network = $network;
```

Grep `new UserRepos(` across the repo (tests construct it directly) and update every site.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/User/ChannelFlagEntityTest.php` → PASS. Then `vendor/bin/phpunit` full suite — any `new UserRepos(` site missed surfaces here; fix all.

- [ ] **Step 5: Migration (TDD via scratch: sqlite + pg)**

Create `Migrations/Version20260928120000.php`, class `Version20260928120000`, description `"Add channel_flags table"`. Follow `Version20260927120000.php` exactly (same `up(Schema $schema)`/`down` shape, comparator style):

```php
public function up(Schema $schema): void
{
    $table = $schema->createTable('channel_flags');
    $table->addColumn('id', 'integer', ['autoincrement' => true]);
    $table->addColumn('channel_id', 'integer', ['notnull' => true]);
    $table->addColumn('user_id', 'integer', ['notnull' => true]);
    $table->addColumn('flags', 'json', ['notnull' => true]);
    $table->addColumn('added_by', 'string', ['notnull' => true]);
    $table->addColumn('created', 'datetime_immutable', ['notnull' => true]);
    $table->setPrimaryKey(['id']);
    $table->addUniqueConstraint(['channel_id', 'user_id'], 'channel_flags_channel_user_uniq');
    $table->addForeignKeyConstraint('Channels', ['channel_id'], ['id'], ['onDelete' => 'CASCADE']);
    $table->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);
    $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractPlatform, 'unknown platform');
}
```

(Skip the `abortIf` line — copy the REAL assertion/arrangement lines from `Version20260927120000.php`; the pattern there is authoritative, including how `down()` drops the table. If the repo's migration base requires `$this->addSql()` calls instead of pure Schema manipulation, follow `Version20260927120000.php` verbatim.)

`down()`: `$schema->dropTable('channel_flags');`

Scratch verification (both engines, never the dev DB):
1. sqlite: copy dev sqlite to `/tmp/opencode/chant4.sqlite`, run ALL pending migrations there via the doctrine-migrations DependencyFactory pattern (see `/tmp/opencode/pg_verify.php` steps 1 for the exact `getMigrationPlanCalculator()->getPlanUntilVersion(...)` recipe), assert table `channel_flags` exists with columns `id, channel_id, user_id, flags, added_by, created`, unique index `channel_flags_channel_user_uniq`, and both FKs (CASCADE) present via `introspectSchema()`/`listTableForeignKeys()`.
2. pg: run the same against `lolbot_test` (drop + recreate the db first: `sudo -u postgres psql -c "DROP DATABASE lolbot_test;" -c "CREATE DATABASE lolbot_test OWNER lolbot_test;"` then re-run all migrations) and assert the same shape.
3. Round-trip on sqlite scratch: with a real `EntityManager` on the copy, create Network+Bot+Channel+User rows, persist a `ChannelFlag`, `flush`, `clear`, then `(new DoctrineChannelFlagRepo($em))->findForChannelUser($chanId, $userId)` returns it with `flags` hydrated as array; a miss returns null.

- [ ] **Step 6: Full suite + phpstan + commit**

Run: `vendor/bin/phpunit` (green); `php -d memory_limit=1G vendor/bin/phpstan analyse entities/ChannelFlag.php library/user/ChannelFlagRepo.php library/user/DoctrineChannelFlagRepo.php library/user/UserRepos.php library/user/UserSystemFactory.php tests/User/ChannelFlagEntityTest.php --no-progress` (zero NEW; factory/repo paths were clean after step 3 of the core).

```bash
git add entities/ChannelFlag.php library/user/ChannelFlagRepo.php library/user/DoctrineChannelFlagRepo.php library/user/UserRepos.php library/user/UserSystemFactory.php Migrations/Version20260928120000.php tests/User/ChannelFlagEntityTest.php
git -c user.name="knivey" -c user.email="knivey@botops.net" commit -m "feat(user): channel flag grants entity, repo and migration"
```

---

### Task 3: Unified flag check through the registry

**Files:**
- Modify: `library/user/Access.php` (add `flagArray()`, route `userHasFlag()` through `Flags`)
- Test: extend `tests/User/AccessFlagsTest.php`

**Interfaces:**
- Consumes: `Flags::passes()` (Task 1).
- Produces:
  - `Access::flagArray(object $user): array<int, string>` — defensive extraction (missing/non-array `flags` → `[]`, non-string entries filtered) — the one place raw flags are read.
  - `Access::userHasFlag(object $user, string $flag): bool` — now `Flags::passes(self::flagArray($user), $flag)`; signature unchanged, becomes group-aware (a network `admin` still passes 'admin'; a future network group passes its grants).
  - `wireAccess()`'s `Access::define('admin', ...)` keeps calling `Access::userHasFlag` — no factory change needed in this task.

- [ ] **Step 1: Write the failing tests (add to `tests/User/AccessFlagsTest.php`)**

```php
public function test_flag_array_is_defensive(): void
{
    $this->assertSame([], \library\user\Access::flagArray(new \stdClass()));
    $o = new \stdClass();
    $o->flags = 'admin';
    $this->assertSame([], \library\user\Access::flagArray($o));
    $o->flags = ['admin', 3, null];
    $this->assertSame(['admin'], \library\user\Access::flagArray($o));
}

public function test_user_has_flag_is_group_aware(): void
{
    \library\user\Flags::define('manager', ['quotes']);
    $o = new \stdClass();
    $o->flags = ['manager'];
    $this->assertTrue(\library\user\Access::userHasFlag($o, 'quotes'));
    $this->assertFalse(\library\user\Access::userHasFlag($o, 'kick'));
}

public function test_admin_passes_anything_via_wildcard(): void
{
    $o = new \stdClass();
    $o->flags = ['admin'];
    $this->assertTrue(\library\user\Access::userHasFlag($o, 'whatever'));
}
```

Add `Flags::reset()` in that test class's `tearDown` (create one if absent).

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/User/AccessFlagsTest.php`
Expected: FAIL — `flagArray` undefined; group-awareness assertion fails.

- [ ] **Step 3: Implement**

In `library/user/Access.php` — preserve every existing comment; add above `userHasFlag`:

```php
/**
 * Defensive raw-flag extraction: the single place $user->flags is read.
 * Missing property or non-array value yields [], non-string entries are
 * filtered out.
 *
 * @return array<int, string>
 */
public static function flagArray(object $user): array
{
    if (!property_exists($user, 'flags')) {
        return [];
    }
    $flags = $user->flags;
    if (!is_array($flags)) {
        return [];
    }
    return array_values(array_filter($flags, 'is_string'));
}
```

Rewrite `userHasFlag`'s body (keep its docblock, extend the description — carry the old sentences over) to:

```php
public static function userHasFlag(object $user, string $flag): bool
{
    return Flags::passes(self::flagArray($user), $flag);
}
```

- [ ] **Step 4: Run to verify pass + full suite**

Run: `vendor/bin/phpunit tests/User/AccessFlagsTest.php` → PASS; `vendor/bin/phpunit` full suite green (existing `AclMiddlewareTest` behavior must be unchanged — `admin` still passes `'admin'`).

- [ ] **Step 5: phpstan + commit**

Run: `php -d memory_limit=1G vendor/bin/phpstan analyse library/user/Access.php tests/User/ --no-progress` (clean).

```bash
git add library/user/Access.php tests/User/AccessFlagsTest.php
git -c user.name="knivey" -c user.email="knivey@botops.net" commit -m "feat(user): flag checks go through the registry with group expansion"
```

---

### Task 4: Channel-scoped Acl attribute + middleware

**Files:**
- Modify: `library/user/Acl.php` (attribute `channel` param + middleware channel mode)
- Modify: `library/user/UserSystem.php` (add `channelByName()`)
- Modify: `library/user/Access.php` (add `beforeAllows()`, reuse in `allowed()`)
- Test: extend `tests/User/AclMiddlewareTest.php`; scratch script `/tmp/opencode/chant4_acl.php`

**Interfaces:**
- Consumes: `Access::resolveUser()`, `Access::flagArray()` (Task 3), `Flags::passes()` (Task 1), `UserRepos->channelFlags` (Task 2), `UserSystem` bundle.
- Produces:
  - `#[Acl("flag")]` — unchanged network scope.
  - `#[Acl("flag", channel: true)]` — channel scope: resolves the channel from the `ChatEvent` in extraArgs, checks `Flags::passes(networkFlags ∪ channelGrants, flag)`.
  - `Acl::args(): array{flag: string, channel: bool}` (both keys always present).
  - `UserSystem::channelByName(string $chan): ?\lolbot\entities\Channel` — the bot's channel row for `$chan` on THIS network (lowered name compare), null when unconfigured.
  - `Access::beforeAllows(object ...$args): bool` — public wrapper invoking the registered `before()` hook; true iff it explicitly allowed. The channel path needs this so the **superadmin before-hook also bypasses channel-scoped checks** (the owner can never be locked out — spec's gate rule).

- [ ] **Step 1: Write failing middleware tests (add to `tests/User/AclMiddlewareTest.php`)**

Read the file first and follow its existing fake-event/fake-request construction pattern. New cases:

```php
public function test_channel_mode_denies_pm_with_channel_only(): void
{
    // build a Request whose extraArgs carry a PmEvent-shaped fake (no chan)
    // run Acl::middleware($req, fn () => 'NEXT', flag: 'admin', channel: true)
    $this->assertSame('channel only', $ret);
}

public function test_channel_mode_denies_when_channel_unknown(): void
{
    // ChatEvent-shaped fake with chan '#nope' + sender client whose
    // userSystem->channelByName returns null → 'not configured for this channel'
}

public function test_channel_mode_passes_on_channel_grant(): void
{
    // grant row flags ['quotes'], user network flags [], flag 'quotes'
    // → middleware returns 'NEXT'
}

public function test_channel_mode_network_flag_falls_through(): void
{
    // grant row null, user network flags ['quotes'], flag 'quotes' → 'NEXT'
}

public function test_channel_mode_uses_only_the_event_senders_bundle(): void
{
    // TWO fake bundles present (two sender clients); extraArgs event's
    // sender is bundle A whose channelByName/grants say deny; bundle B
    // would allow. Expect DENY — resolution never consults bundle B.
}

public function test_channel_mode_superadmin_before_hook_still_bypasses(): void
{
    // Access::before(fn () => true) registered; user with NO flags,
    // channel grant null, flag 'admin' → NEXT (owner never locked out).
    // Clear the hook in tearDown (Access::before(null)).
}
```

(The exact fakes depend on the existing test file's helpers — `UserEvent`/`Client` fakes with `->sender`, `->userSystem`. If `channelByName` needs a real `UserSystem`, construct the real final class with a stub `EntityManager` (`createStub(EntityManager::class)`) and override nothing — `channelByName` must be the only path the middleware touches; if the middleware calls it on the real class with a stubbed EM, make `channelByName` return null by having the stub EM's `createQuery` return a stub query yielding null. If that proves brittle, add a narrow `ChannelLookup` callable property on `UserSystem` (`public ?\Closure $channelLookup = null;` — `channelByName()` uses it when set) and set it in tests; production path stays the EM query.)

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/User/AclMiddlewareTest.php`
Expected: FAIL — `channel` arg unknown to the current middleware / `channelByName` undefined.

- [ ] **Step 3: Implement**

`library/user/Acl.php`:

```php
public function __construct(public string $flag, public bool $channel = false)
{
}
```

Update the class docblock (carry over its existing sentences; append the channel form). `args()`:

```php
/** @return array{flag: string, channel: bool} */
public function args(): array
{
    return ['flag' => $this->flag, 'channel' => $this->channel];
}
```

Middleware — keep the existing network path verbatim; add the channel branch before it (structure shown; preserve the existing deny strings):

```php
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
```

`library/user/UserSystem.php` — add (preserve all existing comments):

```php
/**
 * The bot's Channel row for a channel name on THIS network (lowered
 * compare), null when the bot isn't configured for it. Channel rows are
 * per-bot (Channels.bot FK) — that is the deliberate scoping for
 * channel_flags grants (owner decision 2026-09-27).
 */
public function channelByName(string $chan): ?\lolbot\entities\Channel
{
    $q = $this->em->createQuery(
        'SELECT c FROM lolbot\entities\Channel c JOIN c.bot b'
        . ' WHERE b.network = :net AND LOWER(c.name) = :name',
    );
    $q->setParameter('net', $this->network);
    $q->setParameter('name', mb_strtolower($chan));
    return $q->getOneOrNullResult();
}
```

`library/user/Access.php` — add next to `before()` (preserve its comment; cross-reference):

```php
/**
 * Public read of the before-hook's verdict: true iff a registered hook
 * explicitly allowed these args. The acl middleware's channel path uses
 * this so the superadmin bypass covers channel-scoped checks too.
 */
public static function beforeAllows(object ...$args): bool
{
    return self::$before !== null && (self::$before)(...$args) === true;
}
```

(And simplify `allowed()`'s first check to reuse `beforeAllows(...$args) === true` — same behavior, one code path; keep the original comment above it.)

- [ ] **Step 4: Run to verify pass + full suite**

Run: `vendor/bin/phpunit tests/User/AclMiddlewareTest.php` → PASS; full `vendor/bin/phpunit` green (network-scope behavior byte-identical).

- [ ] **Step 5: Scratch-DB end-to-end (real entities, both deny paths + grant pass)**

Script `/tmp/opencode/chant4_acl.php` on a sqlite scratch copy (copy dev DB, migrate, build EM like `/tmp/opencode/pg_verify.php`): create Network/Bot/Channel(#test)/User(flags [])+ChannelFlag(['quotes']) rows; build a real `UserSystem`; drive `Acl::middleware` with a real cmdr `Request` (extraArgs = a real `ChatEvent` with `chan '#TEST'`, sender fake carrying the bundle):
- flag `quotes`, channel mode → NEXT passes (case-insensitive `#TEST` vs `#test`).
- flag `admin`, channel mode → access denied.
- PM event → "channel only".
- delete the ChannelFlag row → flag `quotes` → access denied (no grant, no network flag).
Document the transcript in the task report.

- [ ] **Step 6: phpstan + commit**

Run: `php -d memory_limit=1G vendor/bin/phpstan analyse library/user/Acl.php library/user/UserSystem.php tests/User/ --no-progress` (clean).

```bash
git add library/user/Acl.php library/user/UserSystem.php library/user/Access.php tests/User/AclMiddlewareTest.php
git -c user.name="knivey" -c user.email="knivey@botops.net" commit -m "feat(user): channel-scoped acl attribute with union resolution"
```

---

### Task 5: `.cflags` in-channel grant command

**Files:**
- Modify: `scripts/user/user.php` (new `#[Cmd("cflags")]` function + reuse `applyFlagOps`)
- Test: scratch e2e `/tmp/opencode/chant4_cflags.php` (real router + real DB); no new unit-test file needed — the pure pieces (`applyFlagOps`, `Flags`, `Access::flagArray`) are already covered.

**Interfaces:**
- Consumes: `resolveUserSystem($bot)` (existing helper in user.php), `UserSystem::channelByName()`, `ChannelFlagRepo`, `Flags`, `Access::flagArray`, `User::applyFlag`, `applyFlagOps()` (existing op normalizer).
- Produces: `.cflags <user> [+flag|-flag|flag ...]` chat command (view when no ops given).

- [ ] **Step 1: Scratch-first (the failing e2e)**

Write `/tmp/opencode/chant4_cflags.php` modeled on the Task-5-core scratch harness (copy dev sqlite → migrate → EM → real `Cmdr` + load user.php funcs → fake Client with `userSystem` bundle + recording `msg()`; fake ChatEvent carrying `chan`, `nick`, `identhost`, `account`, sender). Assert BEFORE implementing (they fail):

1. network-admin granter `.cflags alice +admin` → reply `flags for alice in #test: admin`; ChannelFlag row exists (`addedBy` = granter's user name).
2. plain user (no flags) granter `.cflags alice +quotes` → "access denied"-style refusal; no row.
3. channel-`quotes` granter (ChannelFlag row `['quotes']`) `.cflags bob +quotes` → allowed; then `.cflags bob +admin` → refused (`admin` not passed in-channel by holder of only `quotes`), row unchanged.
4. `.cflags nosuchuser +admin` (granter = network admin) → "user unknown"; nothing persisted.
5. `.cflags alice +notAflag` (granter = network admin) → refused listing valid flags; nothing persisted.
6. `.cflags alice -admin` (granter = network admin, alice holds channel `admin`) → row flags become `[]` and the row is REMOVED (empty grant rows are deleted).
7. `.cflags alice` (view) → lists alice's channel grant flags (or "none").
8. In a channel with no Channel row → "I'm not configured for this channel".

- [ ] **Step 2: Implement the command in `scripts/user/user.php`**

Follow the file's existing command style (docblock + attributes + `function (ChatEvent $args, Client $bot, Args $cmdArgs)`). Place it after the PM commands:

```php
/**
 * Channel-scoped flag grants (spec 2026-09-27): .cflags <user> [ops].
 * Viewing shows the target's grants in this channel; ops follow the
 * user:flags syntax (+flag / -flag / bare flag adds; ^ and ! also
 * remove). A granter may only grant or revoke flags they themselves
 * pass in THIS channel (network flags union channel grants, groups
 * expanded) — bot admins pass everything via the admin wildcard.
 * Empty grant rows are deleted. All-or-nothing: any invalid or
 * un-permitted op refuses the whole batch and persists nothing.
 */
#[Cmd("cflags")]
#[Syntax("<user> [ops...]")]
function cflags(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
```

Body flow (use the existing helpers: `resolveUserSystem`, `argString`, `applyFlagOps`; resolve the GRANTER through the bundle's IdentityService exactly like the PM commands resolve the current user):
1. `$sys = resolveUserSystem($bot)` → null: msg "user system not ready".
2. `$chanEntity = $sys->channelByName($args->chan)` → null: msg "I'm not configured for this channel", return.
3. Resolve granter (IdentityService via `$args`, allowCreate true) → user entity or msg "auth required".
4. `$granterUnion = array_merge(Access::flagArray($granterUser), <granter's own grant row flags for this channel>)`.
5. `$target = $sys->repos->users->findForNetwork($sys->network->id, mb_strtolower((string) argString($cmdArgs, 'user')))` → null: msg "user unknown (they must talk or auth first)", return.
6. No ops → view: fetch target's grant row, msg `flags for <name> in <chan>: <csv>` or `none`; return.
7. Normalize ops (`applyFlagOps`); collect errors: undefined flags (`!Flags::defined($f)` → "unknown flag(s): … (valid: …)") and un-permitted ops (`!Flags::passes($granterUnion, $f)` → "you can't change <f> here"). Any error → msg all errors, return (nothing persisted).
8. Find-or-create `ChannelFlag` (set `channel_id`, `user_id`, `addedBy` = granter user's `name` on create), fold every op via `User::applyFlag`, flush. Result `[]` → `$sys->em->remove($row)` + flush.
9. msg `flags for <name> in <chan>: <csv>` (or `none`).

Reply strings go to the CHANNEL (`$bot->msg($args->chan, ...)` — the bot's own templated lead, no `\2\2`).

- [ ] **Step 3: Run the scratch e2e to verify all 8 cases pass**

Run: `php /tmp/opencode/chant4_cflags.php` → all checks OK. Attach transcript to the task report.

- [ ] **Step 4: Full suite + phpstan + commit**

Run: `vendor/bin/phpunit` green; `php -d memory_limit=1G vendor/bin/phpstan analyse scripts/user/user.php --no-progress` (clean).

```bash
git add scripts/user/user.php
git -c user.name="knivey" -c user.email="knivey@botops.net" commit -m "feat(user): cflags in-channel grant command"
```

---

### Task 6: Registry validation on `user:flags` and `setflags`

**Files:**
- Modify: `cli_cmds/user_flags.php`
- Modify: `scripts/user/user.php` (`setflags`/`addflags`/`delflags` path)
- Test: extend `tests/User/UserFlagsCliTest.php` + `tests/User/UserFlagsStringInputTest.php`

**Interfaces:**
- Consumes: `Flags::defined()` / `Flags::definitions()` (Task 1).
- Produces: both flag-editing surfaces refuse unknown flag names with the valid-names list BEFORE applying anything.

- [ ] **Step 1: Write failing tests**

In `tests/User/UserFlagsCliTest.php` (applyFlag-level tests exist; add command-level):

```php
public function test_unknown_flag_is_refused_with_valid_names(): void
{
    // CommandTester/StringInput on the real command: 'user:flags net user +bogus'
    // expect exit 1 and output containing "unknown flag: bogus" and "valid:"
    // and the DB user's flags unchanged ([]).
}

public function test_known_flag_still_applies(): void
{
    // '+admin' → exit 0, flags ['admin']
}
```

(Use the file's existing scratch-EM bootstrap.)

- [ ] **Step 2: Run to verify failure** — `vendor/bin/phpunit tests/User/UserFlagsCliTest.php` → new cases FAIL (bogus currently applies).

- [ ] **Step 3: Implement**

In `cli_cmds/user_flags.php`, after op normalization and BEFORE the first `applyFlag` call: collect `$unknown = array_filter($ops, fn ($f) => !Flags::defined($f))`; if non-empty → `$output->writeln("unknown flag(s): " . implode(', ', $unknown) . " (valid: " . implode(', ', array_keys(Flags::definitions())) . ')')` + return `Command::FAILURE`. Keep every existing comment.

In `scripts/user/user.php` `setflags` (the shared path `addflags`/`delflags` also route through): same guard after `applyFlagOps` — `$bot->pm($args->nick, "unknown flag(s): … (valid: …)")` + return, before any mutation.

- [ ] **Step 4: Verify pass + full suite**

`vendor/bin/phpunit tests/User/` green; full suite green. Also re-run the Task-5-core scratch `/tmp/opencode/chant4_cflags.php`-style check for setflags refusal via PM harness if still present.

- [ ] **Step 5: phpstan + commit**

Run: `php -d memory_limit=1G vendor/bin/phpstan analyse cli_cmds/user_flags.php scripts/user/user.php tests/User/ --no-progress` (clean).

```bash
git add cli_cmds/user_flags.php scripts/user/user.php tests/User/UserFlagsCliTest.php tests/User/UserFlagsStringInputTest.php
git -c user.name="knivey" -c user.email="knivey@botops.net" commit -m "feat(user): flag surfaces validate against the registry"
```

---

## Post-plan (orchestrator, after Task 6)

- Spec: mark step 4 (channel access) DONE in `docs/superpowers/specs/2026-09-26-user-system-design.md` Foundations build order.
- Run `php admin-cli.php migrations:migrate` on the dev DB (owner pre-approved dev migrations).
- Whole-branch review, push, ledger wrap per subagent-driven-development.
