# Settings Registry (user system step 5) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The central settings registry — `#[Setting]` definitions + storage adapters, `channel_settings`/`user_settings` tables with tiered resolution, the context-split `.set`/`.unset` IRC surface, the linktitles adapter, and weather's account-level location override as the first native consumer.

**Architecture:** Definitions are code-declared (`#[Setting]` attributes on owning command functions, discovered by reflection; programmatic `SettingsRegistry::define()` for adapter-backed settings). Resolution is owned by one service (`SettingsStore`): channel settings `channel → network → code default`, account settings `user → default`; definitions with an attached `SettingStorage` route get/set to the adapter instead of the generic tables. The `.set` surface splits by context: in-channel = channel setting gated by the definition's flag (channel-scoped union check), via PM = the speaker's own account setting.

**Tech Stack:** PHP 8.1, Doctrine ORM (attributes + hand-written migrations, sqlite + pg), cmdr v5, PHPUnit 10, testenv harness for live verification.

**Spec:** `docs/superpowers/specs/2026-09-26-user-system-design.md` — sections "Nick-keyed vs account settings (resolved 2026-09-27)" and "Central settings registry (decided 2026-09-27)". The plan argues from the spec; executors read both.

## Global Constraints

- PHP 8.1 floor; attributes take constant expressions only — **storage adapters are NEVER attribute args**; they attach via `SettingsRegistry::attachStorage()`.
- Entity/migration style: `entities/User.php` patterns; migration `Migrations/Version20260928120000.php` is authoritative; safe on sqlite AND pg (scratch verify BOTH: sqlite copy under /tmp/opencode + `lolbot_test` pg db via the drop/recreate recipe).
- Setting keys are dev-defined identifiers — stored exactly as defined (no lowering). Types: `'bool' | 'int' | 'string' | 'enum'` with `enum_of: list<string>`. Values are ALWAYS scalars (bool/int/string); JSON column stores them typed — `false` must survive as `false`, not `0`.
- The network tier of `channel_settings` is a row with `channel_id` NULL; SQL NULL-uniqueness means INSERTs must be find-before-save (linktitles precedent — the spec documents this).
- Channel-scope `.set` gating = the flag declared on the definition, checked with the channel-scoped union (network flags ∪ channel grants, groups expanded, superadmin before-hook honored) — same semantics as `#[Acl(flag, channel: true)]`.
- Existing nick-keyed commands (`.setlocation`, `.setlastfm`) are NOT modified or removed; the account tier is purely additive (weather task only adds a read-side layer).
- `.set` outputs: bot-templated leads (`setting <key> set to <value>`); values never lead a line — no `\2\2` marking needed. Deny strings plain.
- Preserve every existing comment (AGENTS.md); never `git add -f`; dev DB untouched (testenv scratch DBs + pg scratch only).
- Verification per task: full `vendor/bin/phpunit` green; `php -d memory_limit=1G vendor/bin/phpstan analyse <touched> --no-progress` zero NEW; TDD red→green.
- Commits: `git -c user.name="knivey" -c user.email="knivey@botops.net" commit -m "..."` — only the task's files, no push.
- Composer PSR-4: new code in `library/settings/` (namespace `library\settings`) — autoloads, no composer changes.

## Review Focus

The five failure modes the spec implies but happy-path tests miss. Each pinned in its owning task:

1. **`.set` with an unknown key must refuse with a helpful message, never crash or silently store** — pinned in Task 4 (`unknown setting '<key>' — try .set to list`) incl. the PM variant.
2. **Type coercion garbage refuses the WHOLE operation and persists nothing** (`on`/`off` ok for bool, `maybe` refused; `12a` refused for int; enum value outside `enum_of` refused) — pinned in Task 4's pure `SettingValue::coerce()` tests.
3. **Network-tier NULL uniqueness: setting the same network-tier key twice must find-before-save, never double-insert** — pinned in Task 3 on a real sqlite DB (two sequential `setNetworkSetting` calls → exactly one row).
4. **Adapter-backed settings never write the generic tables** — pinned in Task 5 (set a linktitles setting via the store; assert `channel_settings` row count unchanged and the `linktitles_settings` row changed).
5. **Scalar type fidelity through the JSON column** (`false` ≠ `0` ≠ `''`; `7` stays int) — pinned in Task 3 round-trips.

---

### Task 1: Setting attribute + SettingsRegistry (pure)

**Files:**
- Create: `library/settings/Setting.php`
- Create: `library/settings/SettingStorage.php`
- Create: `library/settings/SettingsRegistry.php`
- Test: `tests/Settings/RegistryTest.php`

**Interfaces:**
- Consumes: nothing (pure).
- Produces (exact — every later task):
  - `#[Setting]` attribute (repeatable, targets functions/methods): `public function __construct(public string $name, public string $type = 'string', public mixed $default = null, public string $scope = 'channel', public string $flag = 'admin', public string $description = '', public bool $irc = true, public array $enum_of = [])`
  - `library\settings\SettingStorage` interface: `public function get(string $name, ?int $networkId = null, ?int $channelId = null, ?int $userId = null): mixed`; `public function set(string $name, mixed $value, ?int $networkId = null, ?int $channelId = null, ?int $userId = null): void`; `public function clear(string $name, ?int $networkId = null, ?int $channelId = null, ?int $userId = null): void;` — **binding decision: `null` is the NOT_FOUND sentinel** (safe because setting values are never null — bool/int/string only); this is documented in the interface docblock.
  - `SettingsRegistry::define(Setting $setting, ?SettingStorage $storage = null): void` — throws `InvalidArgumentException` on duplicate name
  - `SettingsRegistry::attachStorage(string $name, SettingStorage $storage): void` — unknown name throws
  - `SettingsRegistry::get(string $name): ?Setting`
  - `SettingsRegistry::storage(string $name): ?SettingStorage`
  - `SettingsRegistry::all(?string $scope = null, ?bool $irc = null): array<string, Setting>` (name → Setting, insertion order)
  - `SettingsRegistry::loadAttributeSettings(): void` — idempotent reflection scan of declared user functions for `#[Setting]` attributes, defining each (attribute-defined settings never carry storage)
  - `SettingsRegistry::reset(): void` (tests)

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Settings/RegistryTest.php
use library\settings\Setting;
use library\settings\SettingsRegistry;
use PHPUnit\Framework\TestCase;

#[Setting('test.one', type: 'bool', default: true, scope: 'account', description: 'a bool')]
#[Setting('test.two', type: 'enum', enum_of: ['a', 'b'], default: 'a')]
function registryTestFixture(): void
{
}

class RegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        SettingsRegistry::reset();
    }

    public function test_programmatic_define_and_get(): void
    {
        SettingsRegistry::define(new Setting('lastfm', type: 'string', default: '', scope: 'account', description: 'your last.fm username'));
        $s = SettingsRegistry::get('lastfm');
        $this->assertNotNull($s);
        $this->assertSame('string', $s->type);
        $this->assertSame('', $s->default);
        $this->assertSame('account', $s->scope);
        $this->assertNull(SettingsRegistry::storage('lastfm'));
    }

    public function test_duplicate_name_throws(): void
    {
        SettingsRegistry::define(new Setting('x'));
        $this->expectException(InvalidArgumentException::class);
        SettingsRegistry::define(new Setting('x'));
    }

    public function test_attribute_scan_loads_fixture(): void
    {
        SettingsRegistry::loadAttributeSettings();
        $one = SettingsRegistry::get('test.one');
        $this->assertNotNull($one);
        $this->assertTrue($one->default);
        $this->assertSame('account', $one->scope);
        $two = SettingsRegistry::get('test.two');
        $this->assertSame(['a', 'b'], $two->enum_of);
        $this->assertSame('channel', $two->scope); // default scope
        $this->assertSame('admin', $two->flag);    // default flag
    }

    public function test_attribute_scan_is_idempotent(): void
    {
        SettingsRegistry::loadAttributeSettings();
        SettingsRegistry::loadAttributeSettings();
        $this->assertNotNull(SettingsRegistry::get('test.one'));
    }

    public function test_scope_and_irc_filters(): void
    {
        SettingsRegistry::define(new Setting('acct', scope: 'account'));
        SettingsRegistry::define(new Setting('chan', scope: 'channel', irc: false));
        SettingsRegistry::define(new Setting('webchan', scope: 'channel', irc: false));
        SettingsRegistry::define(new Setting('normal', scope: 'channel'));
        $this->assertCount(2, SettingsRegistry::all(scope: 'account'));
        $this->assertArrayNotHasKey('chan', SettingsRegistry::all(irc: true));
        $this->assertArrayHasKey('chan', SettingsRegistry::all(irc: false));
    }

    public function test_attach_storage(): void
    {
        SettingsRegistry::define(new Setting('adapted'));
        $storage = $this->createStub(library\settings\SettingStorage::class);
        SettingsRegistry::attachStorage('adapted', $storage);
        $this->assertSame($storage, SettingsRegistry::storage('adapted'));
        $this->expectException(InvalidArgumentException::class);
        SettingsRegistry::attachStorage('missing', $storage);
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `vendor/bin/phpunit tests/Settings/RegistryTest.php` → FAIL (`Class "library\settings\Setting" not found`).

- [ ] **Step 3: Implement**

`Setting` — plain attribute class (`#[\Attribute(\Attribute::IS_REPEATABLE | \Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]`), promoted readonly-style public props per the Interfaces block, plus `public function __toString(): string` returning `"name (type, scope)"`. NOTE: promoted props on an attribute are fine mutable — do NOT use `readonly` (attributes are reflected read-only anyway; keep 8.1-safe and simple).

`SettingStorage` interface with the null-sentinel docblock:

```php
<?php
namespace library\settings;

/*
 * Storage backend for adapter-backed settings (scripts with their own
 * tables — e.g. linktitles). get() returns null to mean "no value at
 * this scope" — null is safe as the NOT_FOUND sentinel because setting
 * VALUES are never null (bool/int/string only).
 */
interface SettingStorage
{
    public function get(string $name, ?int $networkId = null, ?int $channelId = null, ?int $userId = null): mixed;
    public function set(string $name, mixed $value, ?int $networkId = null, ?int $channelId = null, ?int $userId = null): void;
    public function clear(string $name, ?int $networkId = null, ?int $channelId = null, ?int $userId = null): void;
}
```

`SettingsRegistry` — static store `array<string, Setting>` + `array<string, SettingStorage>`; `loadAttributeSettings()` iterates `get_defined_functions()['user']`, reflects each with `->getAttributes(Setting::class)`, defines each attribute instance (skip names already defined — idempotency); track a `$scanned bool` so repeated calls don't re-walk.

- [ ] **Step 4: Run to verify pass + full suite + phpstan** — RegistryTest 6 green; full suite green; `php -d memory_limit=1G vendor/bin/phpstan analyse library/settings/ tests/Settings/ --no-progress` clean.

- [ ] **Step 5: Commit**

```bash
git add library/settings/Setting.php library/settings/SettingStorage.php library/settings/SettingsRegistry.php tests/Settings/RegistryTest.php
git -c user.name="knivey" -c user.email="knivey@botops.net" commit -m "feat(settings): setting attribute and definitions registry"
```

---

### Task 2: Entities + migration

**Files:**
- Create: `entities/ChannelSetting.php`, `entities/UserSetting.php`
- Create: `Migrations/Version20261008120000.php`
- Test: `tests/Settings/EntityTest.php` + scratch scripts under `/tmp/opencode`

**Interfaces:**
- Consumes: entity/migration patterns (Task 2 of the channel-access plan is the reference).
- Produces:
  - `lolbot\entities\ChannelSetting`, table `channel_settings`: `int $id` PK; `int $network_id`; `?int $channel_id = null` (NULL = network tier); `string $settingKey` (column `setting_key`); `mixed $value` JSON column (`public mixed $value` with `#[ORM\Column(type: "json")]` and `@var bool|int|string|null` phpdoc); `?\DateTimeImmutable $updated = null`; unique `channel_settings_scope_uniq (network_id, channel_id, setting_key)`.
  - `lolbot\entities\UserSetting`, table `user_settings`: `int $id`; `int $user_id`; `string $settingKey` (column `setting_key`); `mixed $value` JSON; `?\DateTimeImmutable $updated = null`; unique `user_settings_scope_uniq (user_id, setting_key)`.
  - FKs: `channel_settings.network_id → Networks.id CASCADE`, `channel_settings.channel_id → Channels.id CASCADE`, `user_settings.user_id → users.id CASCADE`.

- [ ] **Step 1: Write the failing entity test**

```php
<?php
// tests/Settings/EntityTest.php
use lolbot\entities\ChannelSetting;
use lolbot\entities\UserSetting;
use PHPUnit\Framework\TestCase;

class EntityTest extends TestCase
{
    public function test_channel_setting_defaults(): void
    {
        $cs = new ChannelSetting();
        $cs->network_id = 1;
        $cs->settingKey = 'enabled';
        $cs->value = false;
        $this->assertNull($cs->channel_id);
        $this->assertNull($cs->updated);
    }

    public function test_user_setting_defaults(): void
    {
        $us = new UserSetting();
        $us->user_id = 5;
        $us->settingKey = 'weather.location';
        $us->value = 'Seattle';
        $this->assertNull($us->updated);
    }
}
```

- [ ] **Step 2: RED** → classes missing.

- [ ] **Step 3: Implement** entities per Interfaces (follow `entities/ChannelFlag.php` style — psalm-suppress docblock, `//Cant be readonly...` comment above ids) and the migration:

```php
// Migrations/Version20261008120000.php — up(): pattern per Version20260928120000
$table = $schema->createTable('channel_settings');
// id autoincrement PK, network_id INT NOT NULL, channel_id INT NULL,
// setting_key STRING NOT NULL, value JSON NOT NULL, updated DATETIME_IMMUTABLE NULL
$table->addUniqueConstraint(['network_id', 'channel_id', 'setting_key'], 'channel_settings_scope_uniq');
$table->addForeignKeyConstraint('Networks', ['network_id'], ['id'], ['onDelete' => 'CASCADE']);
$table->addForeignKeyConstraint('Channels', ['channel_id'], ['id'], ['onDelete' => 'CASCADE']);
// user_settings likewise (FK users)
// down(): dropTable both
```

Scratch verification (both engines): sqlite copy → run all migrations via the DependencyFactory recipe → assert tables/columns/uniques/FKs; pg drop/recreate `lolbot_test` → migrate → same assertions; round-trip on sqlite: persist ChannelSetting with `false` → flush/clear → reload → `value === false` (strict), and a network-tier row (`channel_id` null).

- [ ] **Step 4: GREEN + full suite + phpstan + commit**

```bash
git add entities/ChannelSetting.php entities/UserSetting.php Migrations/Version20261008120000.php tests/Settings/EntityTest.php
git -c user.name="knivey" -c user.email="knivey@botops.net" commit -m "feat(settings): channel and user setting entities and migration"
```

---

### Task 3: SettingsStore — resolution, persistence, adapter routing

**Files:**
- Create: `library/settings/SettingsStore.php`
- Create: `library/settings/UnknownSettingException.php`
- Test: `tests/Settings/StoreTest.php` (scratch-EM driven, the `tests/TestEnv/SeederTest` bootstrapping pattern)

**Interfaces:**
- Consumes: `SettingsRegistry`, `SettingStorage`, entities from Task 2, `EntityManager`.
- Produces:
  - `SettingsStore::__construct(EntityManager $em)`
  - `getChannelSetting(int $networkId, ?int $channelId, string $name): array{value: bool|int|string, source: 'channel'|'network'|'default'}` — resolution: exact `(network, channel, key)` row → `(network, NULL, key)` row → definition default; adapter-backed definitions route to `$storage->get($name, $networkId, $channelId)` where non-null = channel source… **adapter tier mapping**: adapters implement their own internal tiering (linktitles already does) and return one value; `source` for adapter hits = `'adapter'`. So source enum: `'channel'|'network'|'default'|'adapter'`.
  - `setChannelSetting(int $networkId, int $channelId, string $name, mixed $value): void` (find-before-save exact row; sets `updated`)
  - `setNetworkSetting(int $networkId, string $name, mixed $value): void` (find-before-save `(network, NULL)` row)
  - `clearChannelSetting(int $networkId, int $channelId, string $name): void` / `clearNetworkSetting(int $networkId, string $name): void` (remove row if present)
  - `getUserSetting(int $userId, string $name): array{value: bool|int|string, source: 'user'|'default'|'adapter'}`
  - `setUserSetting(int $userId, string $name, mixed $value): void` / `clearUserSetting(int $userId, string $name): void`
  - Every method throws `UnknownSettingException` (`"unknown setting '<name>'"`) for undefined names; adapter-backed names route set/clear to the storage too.

- [ ] **Step 1: Write the failing test** (scratch EM on a sqlite copy per the SeederTest pattern; define two settings in setUp: `plain` (channel, default `false`) and `uacct` (account, default `''`); attach a fake storage to `adapted` returning `'from-adapter'` for get and recording set calls):

```php
<?php
// tests/Settings/StoreTest.php — core cases (implement with the shared scratch-EM bootstrap; keep one bootstrap helper per file):
// 1. channel default: getChannelSetting(net, chan, 'plain') === {value: false, source: 'default'}
// 2. channel tier: setChannelSetting(..., 'plain', true) → get returns {true, 'channel'}
// 3. network tier: setNetworkSetting(net, 'plain', ...) with channel row ABSENT → {value, 'network'}; with channel row PRESENT channel wins
// 4. NULL uniqueness: setNetworkSetting twice → assert exactly ONE (network, NULL, 'plain') row (SELECT COUNT)
// 5. clearChannelSetting falls back to network tier; clearNetworkSetting → default
// 6. scalar fidelity: value false → reload → identical(false); 7 → int; '' → ''
// 7. user tiers: default → setUserSetting → clear → default
// 8. adapter routing: getChannelSetting on 'adapted' → {'from-adapter', 'adapter'}; setChannelSetting('adapted', ...) → fake storage received set(name, value, net, chan); assert NO channel_settings row was created
// 9. UnknownSettingException for 'nope' on every accessor/mutator
```

(Write these as real test methods — 9 cases; the bootstrap is the SeederTest one: copy dev sqlite → migrate → new EntityManager. If copying the dev DB is undesired per-run, create the sqlite from scratch via `EnvStore::dbPath('settings_test')` + the Seeder migration recipe — EITHER is acceptable; document the choice.)

- [ ] **Step 2: RED** → class missing.

- [ ] **Step 3: Implement** per Interfaces. Key discipline: registry lookup FIRST (throws for unknown), storage routing SECOND (adapter present → storage, no entity work), entity find-before-save THIRD. `updated` set on every write.

- [ ] **Step 4: GREEN + full suite + phpstan + commit**

```bash
git add library/settings/SettingsStore.php library/settings/UnknownSettingException.php tests/Settings/StoreTest.php
git -c user.name="knivey" -c user.email="knivey@botops.net" commit -m "feat(settings): tiered store with adapter routing"
```

---

### Task 4: `.set` / `.unset` commands + ChannelAccess helper

**Files:**
- Create: `scripts/settings/settings.php`
- Create: `library/user/ChannelAccess.php`
- Create: `library/settings/SettingValue.php`
- Modify: `lolbot.php` (require the new script next to the other script requires)
- Test: `tests/Settings/SettingValueTest.php` + scratch e2e `/tmp/opencode/setcmd_e2e.php`

**Interfaces:**
- Consumes: `SettingsRegistry`, `SettingsStore`, `Setting`, `UserSystem` bundle (`$bot->userSystem`), identity resolution pattern from `scripts/user/user.php` (`resolveUserSystem`, granter resolution via `ResolveContext`), `ServiceLocator` paste pattern (`scripts/alias/alias.php:144-155`).
- Produces:
  - `library\user\ChannelAccess::passesInChannel(\library\user\UserSystem $us, object $user, string $chan, string $flag): bool` — resolves the Channel via `$us->channelByName($chan)` (false when unknown), merges `Access::flagArray($user)` with the user's `channel_flags` grants for that channel, honors `Access::beforeAllows($user)`, returns `Flags::passes($union, $flag)`.
  - `library\settings\SettingValue::coerce(Setting $s, string $input): bool|int|string` — throws `InvalidArgumentException` with a clear message; bool: `true/false/on/off/1/0/yes/no` (case-insensitive); int: `filter_var(FILTER_VALIDATE_INT)`; enum: strict `in_array($input, $s->enum_of, true)`; string: as-is (trimmed at the command layer, not here).
  - `library\settings\SettingValue::display(mixed $v): string` — bool → `on`/`off`; others `(string)`.
  - `#[Cmd("set")]` + `#[Cmd("unset")]` in `scripts/settings/settings.php` (`#[Syntax('[key] [value...]')]` / `#[Syntax('<key>')]`), both chat+PM capable (`UserEvent`):
    - **chat context**: channel scope. No key → list channel-scope irc settings with `value (source)`; >20 lines or `--web` → paste via ServiceLocator (fallback inline on paste failure, alias.php pattern). Key only → show `key: value (source: channel|network|default)`. Key+value → definition flag check via `ChannelAccess::passesInChannel`; `--net` option sets/clears the network tier instead (same check); coerce → `setChannelSetting`/`setNetworkSetting` → reply `setting <key> set to <display>`.
    - **PM context**: account scope (resolve speaker via the bundle, allowCreate true — the user.php granter pattern; no user → "auth required"). No key → list account-scope settings. Key only → `key: value (source: user|default)`. Key+value → any known user may set their own → `setUserSetting`.
    - Wrong-scope key (channel key via PM, account key in chat) → `'<key>' is a channel setting — set it in the channel` / `'<key>' is an account setting — set it via PM`.
    - Unknown key → `unknown setting '<key>' — try .set to list`.
    - `.unset <key>` mirrors scope/context rules; clearing an absent override → `'<key>' is already at its default`.
- Modify `lolbot.php`: after its script `require` block, one top-level call: `\library\settings\SettingsRegistry::loadAttributeSettings();` (so attribute definitions from ALL scripts — including weather's Task 6 one — are loaded before any command can run). Do NOT call it inside scripts/settings/settings.php.

- [ ] **Step 1: TDD the pure parts** — `tests/Settings/SettingValueTest.php`:

```php
<?php
use library\settings\Setting;
use library\settings\SettingValue;
use PHPUnit\Framework\TestCase;

class SettingValueTest extends TestCase
{
    public function test_bool_forms(): void
    {
        $s = new Setting('b', type: 'bool');
        foreach (['true','on','1','yes','TRUE'] as $in) $this->assertTrue(SettingValue::coerce($s, $in), $in);
        foreach (['false','off','0','no'] as $in) $this->assertFalse(SettingValue::coerce($s, $in), $in);
    }

    public function test_bool_garbage_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SettingValue::coerce(new Setting('b', type: 'bool'), 'maybe');
    }

    public function test_int_and_garbage(): void
    {
        $this->assertSame(7, SettingValue::coerce(new Setting('i', type: 'int'), '7'));
        $this->assertSame(-3, SettingValue::coerce(new Setting('i', type: 'int'), '-3'));
        $this->expectException(InvalidArgumentException::class);
        SettingValue::coerce(new Setting('i', type: 'int'), '12a');
    }

    public function test_enum_membership(): void
    {
        $s = new Setting('e', type: 'enum', enum_of: ['metric', 'imperial']);
        $this->assertSame('metric', SettingValue::coerce($s, 'metric'));
        $this->expectException(InvalidArgumentException::class);
        SettingValue::coerce($s, 'kelvin');
    }

    public function test_string_passthrough_and_display(): void
    {
        $this->assertSame('Seattle, WA', SettingValue::coerce(new Setting('s'), 'Seattle, WA'));
        $this->assertSame('on', SettingValue::display(true));
        $this->assertSame('off', SettingValue::display(false));
        $this->assertSame('7', SettingValue::display(7));
    }
}
```

- [ ] **Step 2: RED → implement SettingValue → GREEN.**

- [ ] **Step 3: Scratch e2e** — `/tmp/opencode/setcmd_e2e.php` on a testenv `ownnet`-shaped scratch (copy the harness from the channel-access cflags e2e): fake ChatEvent in `#knivey` + PM events through the REAL router with `scripts/settings/settings.php` loaded. Define a fixture setting programmatically (channel bool `test.enabled` default false, account string `test.greeting` default `hi`). Cases:
  1. chat `.set test.enabled` → `test.enabled: off (default)`
  2. chat `.set test.enabled true` as network-admin granter → `setting test.enabled set to on`
  3. chat `.set test.enabled` → `on (channel)`
  4. chat `.set test.enabled maybe` → refused (coercion), value unchanged
  5. chat `.set unknown.key x` → `unknown setting 'unknown.key' — try .set to list`
  6. PM `.set test.greeting hello there` → set; `.set test.greeting` → `hello there (user)`; `.unset test.greeting` → back to `hi (default)`
  7. PM `.set test.enabled on` → `'test.enabled' is a channel setting — set it in the channel`
  8. chat `.set test.enabled on` as UNPRIVILEGED granter (no admin, no grant) → deny message, value unchanged
  9. chat `.set --net test.enabled on` → network tier; then channel `.set test.enabled off` wins in reads
  10. `.unset` on default → `already at its default`

- [ ] **Step 4: Full suite + phpstan + lolbot.php require + commit**

```bash
git add scripts/settings/settings.php library/user/ChannelAccess.php library/settings/SettingValue.php lolbot.php tests/Settings/SettingValueTest.php
git -c user.name="knivey" -c user.email="knivey@botops.net" commit -m "feat(settings): context-split set and unset commands"
```

---

### Task 5: linktitles adapter

**Files:**
- Create: `scripts/linktitles/settings_adapter.php`
- Modify: `scripts/linktitles/linktitles.php` (one top-level `require_once __DIR__ . '/settings_adapter.php';` — find the file's existing require/init block and place it there; preserve all comments)
- Test: extend `tests/Settings/StoreTest.php` with an adapter case set (or `tests/Settings/LinktitlesAdapterTest.php` with the same bootstrap)

**Interfaces:**
- Consumes: `SettingsRegistry::define` + `attachStorage`, `SettingStorage`, `ConfigService::setLinktitlesSetting(?Network, ?Channel, string, mixed)` / `resetLinktitlesSetting` / the EM to resolve Network/Channel entities by id, `linktitles_setting` entity (its own tiering: channel row → network row → global row — the adapter's `get` must implement `channel → network → global` and return the definition default only when all tiers absent).
- Produces: `scripts\linktitles\LinktitlesSettingStorage implements \library\settings\SettingStorage` (constructor takes `EntityManager`), registered at file load for the definitions: `linktitles.enabled` (bool, default `true`), `linktitles.ai_vision_disabled` (bool, default `false`), `linktitles.url_log_chan` (string, default `''`), `linktitles.ai_vision_model` (string, default `''`), `linktitles.ai_vision_prompt` (string, default `''`), `linktitles.ai_vision_reasoning_effort` (string, default `''`), all `scope: 'channel'`, `flag: 'admin'`, `description` one-liners; `linktitles.ai_vision_reasoning` (the raw JSON blob) defined with `irc: false`.
  - `get($name, $networkId, $channelId)`: strip the `linktitles.` prefix → key; resolve Network/Channel entities (`null` network = global row lookups — for the generic surface networkId is always provided); read via the entity repo with its own tiering; null when absent at all tiers.
  - `set`/`clear`: route to `ConfigService::setLinktitlesSetting($network, $channel, $key, $value)` / `resetLinktitlesSetting`. ConfigService is constructed with the EM (`new ConfigService($em, ...)` — READ its constructor first and mirror how other call sites construct or obtain it; if it needs the notifier, pass a no-op/null per its signature).

- [ ] **Step 1: Failing adapter tests** (same scratch-EM bootstrap): define-with-storage happens by requiring the adapter file; cases:
  1. `.set`-level round trip through `SettingsStore`: `setChannelSetting(net, chan, 'linktitles.enabled', false)` → `getChannelSetting` returns `{false, 'adapter'}` AND the `linktitles_settings` row for (network, channel) has `enabled=false`.
  2. Review Focus #4: assert NO `channel_settings` row was created.
  3. Tiering: network-tier linktitles set (channelId null in adapter terms = network row) → channel without its own row reads network value (`source: 'adapter'`).
  4. Clear: `clearChannelSetting` removes/NULLs the channel-tier value in the linktitles table (falls back to network).
  5. The raw-JSON definition exists with `irc: false` (invisible to `.set` lists).

- [ ] **Step 2: RED → implement → GREEN; full suite + phpstan** (`php -d memory_limit=1G vendor/bin/phpstan analyse scripts/linktitles/ library/settings/ tests/Settings/ --no-progress`).

- [ ] **Step 3: Commit**

```bash
git add scripts/linktitles/settings_adapter.php scripts/linktitles/linktitles.php tests/Settings/LinktitlesAdapterTest.php
git -c user.name="knivey" -c user.email="knivey@botops.net" commit -m "feat(settings): linktitles storage adapter"
```

---

### Task 6: weather account-level location override (first native consumer)

**Files:**
- Modify: `scripts/weather/weather.php` (read-side layering ONLY — `#[Setting('weather.location', type: 'string', default: '', scope: 'account', flag: 'admin', description: 'account-level location override for .weather')]` attribute on a NEW tiny helper function `weather_account_location()` in the file + the self-query branch tries it first)
- Test: scratch e2e `/tmp/opencode/weather_layer_e2e.php`

**Interfaces:**
- Consumes: `SettingsRegistry::loadAttributeSettings()` (already called by lolbot.php after all script requires — Task 4 placed it there), `SettingsStore::getUserSetting`, the identity resolution pattern (`$bot->userSystem` + `ResolveContext`, allowCreate FALSE here — weather must not auto-register), `location` entity lookup (unchanged).
- Produces: `.weather` with no query resolves in order: (1) speaker identity resolves AND `weather.location` user setting ≠ default → use it (reply unchanged format); (2) else the existing nick-keyed location row. `.setlocation` and `@user` lookups untouched.

- [ ] **Step 1: Scratch e2e first (RED)** — birdnet-shaped harness with weather's real `weather` function is heavy (external API); instead drive ONLY the layered-read helper: add `function weather_location_for(?\library\user\UserSystem $us, \Irc\Event\UserEvent $args, EntityManager $em): ?string` in weather.php implementing the order above (pure-ish, DB+bundle only, no HTTP), and the e2e asserts: identity resolves + setting set → returns the setting; setting cleared → returns the nick-row location; no identity (null bundle) → nick-row location. RED: function missing.
- [ ] **Step 2: Implement** the helper + wire the self-query branch to use it (replace ONLY the `if ($query == '')` lookup internals; keep the "You don't have a location set use .setlocation" message when both tiers miss; preserve every comment around it).
- [ ] **Step 3: GREEN + full suite + phpstan** (`scripts/weather/weather.php`).
- [ ] **Step 4: Live smoke (optional but recommended)**: `php testenv.php ownnet up` + client — PM `.set weather.location Seattle` as devtesterbird, then `.weather` (needs openweather key in the generated config — SKIP the live weather call if the key is absent; the helper-level e2e is the required gate).
- [ ] **Step 5: Commit**

```bash
git add scripts/weather/weather.php lolbot.php
git -c user.name="knivey" -c user.email="knivey@botops.net" commit -m "feat(weather): account-level location override layered over setlocation"
```

---

## Post-plan (orchestrator, after Task 6)

- Spec: mark step 5 (settings registry) DONE; note weather as the layered-override exemplar.
- Run `php admin-cli.php migrations:migrate` on the dev DB (owner pre-approves dev migrations).
- Whole-branch review → push → ledger wrap per subagent-driven-development.
- Live verification menu for the owner: `.set`/`.unset` on ownnet + gamesurge, `.set linktitles.enabled off` on ownnet.
