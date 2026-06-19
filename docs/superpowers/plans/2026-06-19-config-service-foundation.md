# Config Service Foundation Implementation Plan (Sub-project 1A)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the tested `ConfigService` / `ServiceLocator` / `SettingsResolver` library, the AI/paste service-config entities, the `linktitles_settings` expansion, the real Doctrine migration, and the `service:*` / `config:import` CLI commands — the foundation that Sub-project 1B wires into the running app.

**Architecture:** Library-centric, no daemon. A `ConfigService` (Doctrine `EntityManager` + a `ChangeNotifier` seam) owns all config mutations and validation; `ServiceLocator` reads global service configs; `SettingsResolver` resolves per-network/channel script settings (channel override → network). The DB is the single source of truth. (See `docs/superpowers/specs/2026-06-19-config-service-design.md`.)

**Tech Stack:** PHP 8.1+, Doctrine ORM 2 / DBAL / Migrations, Symfony Console 6, PHPUnit 13, SQLite (test DB), PostgreSQL (prod).

**Spec:** `docs/superpowers/specs/2026-06-19-config-service-design.md`

---

## File map (this plan)

| File | Responsibility |
|---|---|
| `composer.json` | Add `lolbot\config\` PSR-4 → `library/config`. |
| `tests/Config/ConfigTestCase.php` | Shared base: in-memory SQLite `EntityManager` with schema from metadata. |
| `entities/AiServiceConfig.php` | Global AI service config entity (single-row table). |
| `entities/PasteServiceConfig.php` | Global paste service config entity (single-row table). |
| `scripts/linktitles/entities/linktitles_setting.php` | Expand with `enabled`, `url_log_chan`, `ai_vision_model`, `ai_vision_prompt`, `ai_vision_reasoning_effort`, `ai_vision_reasoning`. |
| `library/config/ConfigChange.php` | DTO describing a mutation for the notifier. |
| `library/config/ChangeNotifier.php` | Interface + `NoopChangeNotifier`. |
| `library/config/DuplicateNameException.php`, `…/NotFoundException.php`, `…/InvalidSettingException.php` | Typed domain exceptions. |
| `library/config/ServiceLocator.php` | Read-only accessor for global service configs by type. |
| `library/config/ConfigService.php` | Mutation layer over `EntityManager`: networks/bots/channels/servers/ignores CRUD, service-config upsert, linktitles settings set/reset. Calls `ChangeNotifier` after each flush. |
| `library/config/SettingsResolver.php` | Resolve `linktitles_setting` (channel → network) and effective values. |
| `Migrations/Version20260619120000.php` | Real schema: create `ai_service_config` / `paste_service_config`; expand `linktitles_settings`. |
| `cli_cmds/service_get.php`, `cli_cmds/service_set.php`, `cli_cmds/service_list.php` | `service:get/set/list` commands. |
| `cli_cmds/config_import.php` | `config:import` — idempotent YAML→DB backfill. |
| `admin-cli.php` | Register the new commands. |

> **Naming convention:** `linktitles_setting` new properties are **snake_case** (`url_log_chan`, `ai_vision_model`, …) to match the existing `ai_vision_disabled` property and the `linktitles:set` command's settings list. (The spec's file-layout table used camelCase; this is the convention-consistent implementation choice.)

> **How commands read config in tests:** tests build a real in-memory SQLite `EntityManager` from the entity metadata (no migration needed) via `ConfigTestCase`. Run targeted tests with `vendor/bin/phpunit tests/Config`.

---

### Task 1: Register the `lolbot\config` PSR-4 namespace

**Files:**
- Modify: `composer.json` (`autoload.psr-4`)

- [ ] **Step 1: Add the namespace mapping**

In `composer.json`, inside `autoload.psr-4`, add the `lolbot\\config\\` entry alongside the existing `lolbot\\entities\\` and `lolbot\\cli_cmds\\` entries:

```json
        "lolbot\\entities\\": "entities",
        "lolbot\\cli_cmds\\": "cli_cmds",
        "lolbot\\config\\": "library/config"
```

- [ ] **Step 2: Regenerate the autoloader**

Run: `composer dump-autoload`
Expected: `Generated optimized autoload files ...` (no error).

- [ ] **Step 3: Smoke-check the mapping resolves**

Run: `php -r 'require "vendor/autoload.php"; echo class_exists("lolbot\\config\\ConfigService") ? "yes\n" : "no\n";'`
Expected: `no` (the class doesn't exist yet — but autoloader is wired; this confirms no syntax error in composer.json). If you get a `composer.json` parse error, fix the JSON.

- [ ] **Step 4: Commit**

```bash
git add composer.json
git commit -m "build(autoload): register lolbot\\config\\ PSR-4 namespace"
```

---

### Task 2: Test harness — in-memory SQLite `EntityManager`

**Files:**
- Create: `tests/Config/ConfigTestCase.php`
- Test: `tests/Config/ConfigTestCaseTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Config/ConfigTestCaseTest.php`:

```php
<?php
namespace Tests\Config;

use lolbot\entities\Network;

require_once __DIR__ . '/../../vendor/autoload.php';

class ConfigTestCaseTest extends ConfigTestCase
{
    public function test_can_persist_and_reload_an_entity(): void
    {
        $net = new Network();
        $net->name = 'TestNet';
        $this->em->persist($net);
        $this->em->flush();
        $this->em->clear();

        $repo = $this->em->getRepository(Network::class);
        $found = $repo->findOneBy(['name' => 'TestNet']);
        $this->assertNotNull($found);
        $this->assertSame('TestNet', $found->name);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Config/ConfigTestCaseTest.php`
Expected: FAIL — `Tests\Config\ConfigTestCase` not found (file doesn't exist yet).

- [ ] **Step 3: Write the base class**

`tests/Config/ConfigTestCase.php`:

```php
<?php
namespace Tests\Config;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

abstract class ConfigTestCase extends TestCase
{
    protected EntityManager $em;

    protected function setUp(): void
    {
        // Same entity paths as bootstrap.php so the full metadata set is available.
        $paths = [
            __DIR__ . '/../../entities',
            __DIR__ . '/../../scripts/linktitles/entities',
            __DIR__ . '/../../scripts/weather/entities',
            __DIR__ . '/../../scripts/lastfm/entities',
            __DIR__ . '/../../scripts/remindme/entities',
        ];
        $config = ORMSetup::createAttributeMetadataConfiguration($paths, true);
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->em = new EntityManager($conn, $config);

        $tool = new SchemaTool($this->em);
        $tool->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        $this->em->close();
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Config/ConfigTestCaseTest.php`
Expected: PASS (1 test). If it fails because a metadata scan errors on some entity, read the error — it usually points at a missing relation target; do **not** edit unrelated entities, instead confirm all five paths above exactly match `bootstrap.php:23-29`.

- [ ] **Step 5: Commit**

```bash
git add tests/Config/ConfigTestCase.php tests/Config/ConfigTestCaseTest.php
git commit -m "test(config): add in-memory SQLite EntityManager test harness"
```

---

### Task 3: `AiServiceConfig` and `PasteServiceConfig` entities

**Files:**
- Create: `entities/AiServiceConfig.php`
- Create: `entities/PasteServiceConfig.php`
- Test: `tests/Config/ServiceConfigEntityTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Config/ServiceConfigEntityTest.php`:

```php
<?php
namespace Tests\Config;

use lolbot\entities\AiServiceConfig;
use lolbot\entities\PasteServiceConfig;

require_once __DIR__ . '/../../vendor/autoload.php';

class ServiceConfigEntityTest extends ConfigTestCase
{
    public function test_ai_service_config_persists_with_defaults(): void
    {
        $ai = new AiServiceConfig();
        $ai->apiKey = 'sk-test';
        $this->em->persist($ai);
        $this->em->flush();
        $this->em->clear();

        $all = $this->em->getRepository(AiServiceConfig::class)->findAll();
        $this->assertCount(1, $all);
        $loaded = $all[0];
        $this->assertSame('sk-test', $loaded->apiKey);
        $this->assertSame(1024, $loaded->maxDim);
        $this->assertSame(85, $loaded->jpgQuality);
        $this->assertSame(10, $loaded->timeout);
        $this->assertNull($loaded->reasoningEffort);
        $this->assertNull($loaded->reasoning);
    }

    public function test_ai_service_config_stores_reasoning_array(): void
    {
        $ai = new AiServiceConfig();
        $ai->reasoning = ['effort' => 'low', 'max_tokens' => 4096];
        $this->em->persist($ai);
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->em->getRepository(AiServiceConfig::class)->findAll()[0];
        $this->assertSame(['effort' => 'low', 'max_tokens' => 4096], $loaded->reasoning);
    }

    public function test_paste_service_config_persists(): void
    {
        $p = new PasteServiceConfig();
        $p->host = 'http://localhost:8080';
        $p->key = 'sekret';
        $this->em->persist($p);
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->em->getRepository(PasteServiceConfig::class)->findAll()[0];
        $this->assertSame('http://localhost:8080', $loaded->host);
        $this->assertSame('sekret', $loaded->key);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Config/ServiceConfigEntityTest.php`
Expected: FAIL — entity classes not found.

- [ ] **Step 3: Create `AiServiceConfig`**

`entities/AiServiceConfig.php`:

```php
<?php
namespace lolbot\entities;

use Doctrine\ORM\Mapping as ORM;

// Single-row global table. ServiceLocator/ConfigService treat the first row as the singleton.
#[ORM\Entity]
#[ORM\Table("ai_service_config")]
class AiServiceConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(updatable: false)]
    public int $id;

    #[ORM\Column(length: 512, nullable: true)]
    public ?string $apiKey = null;

    #[ORM\Column(nullable: true)]
    public ?string $baseUrl = null;

    #[ORM\Column]
    public int $maxDim = 1024;

    #[ORM\Column]
    public int $jpgQuality = 85;

    #[ORM\Column]
    public int $timeout = 10;

    #[ORM\Column(length: 32, nullable: true)]
    public ?string $reasoningEffort = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: "json", nullable: true)]
    public ?array $reasoning = null;

    public function __toString(): string
    {
        return "ai service config: apiKey=" . ($this->apiKey !== null ? '(set)' : '(unset)')
            . " baseUrl=" . ($this->baseUrl ?? '(default)')
            . " maxDim={$this->maxDim} jpgQuality={$this->jpgQuality} timeout={$this->timeout}";
    }
}
```

- [ ] **Step 4: Create `PasteServiceConfig`**

`entities/PasteServiceConfig.php`:

```php
<?php
namespace lolbot\entities;

use Doctrine\ORM\Mapping as ORM;

// Single-row global table.
#[ORM\Entity]
#[ORM\Table("paste_service_config")]
class PasteServiceConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(updatable: false)]
    public int $id;

    #[ORM\Column(nullable: true)]
    public ?string $host = null;

    #[ORM\Column(nullable: true)]
    public ?string $key = null;

    public function __toString(): string
    {
        return "paste service config: host=" . ($this->host ?? '(unset)') . " key=" . ($this->key !== null ? '(set)' : '(unset)');
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Config/ServiceConfigEntityTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add entities/AiServiceConfig.php entities/PasteServiceConfig.php tests/Config/ServiceConfigEntityTest.php
git commit -m "feat(config): add AiServiceConfig and PasteServiceConfig entities"
```

---

### Task 4: Expand `linktitles_setting` entity

**Files:**
- Modify: `scripts/linktitles/entities/linktitles_setting.php`
- Test: `tests/Config/LinktitlesSettingExpansionTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Config/LinktitlesSettingExpansionTest.php`:

```php
<?php
namespace Tests\Config;

use lolbot\entities\Network;
use scripts\linktitles\entities\linktitles_setting;

require_once __DIR__ . '/../../vendor/autoload.php';

class LinktitlesSettingExpansionTest extends ConfigTestCase
{
    public function test_new_fields_round_trip_with_defaults(): void
    {
        $net = new Network();
        $net->name = 'N';
        $this->em->persist($net);

        $s = new linktitles_setting();
        $s->network = $net;
        $s->enabled = true;
        $s->url_log_chan = '#urls';
        $s->ai_vision_model = 'gpt-4o-mini';
        $s->ai_vision_prompt = 'describe';
        $s->ai_vision_reasoning_effort = 'low';
        $s->ai_vision_reasoning = ['effort' => 'low'];
        $this->em->persist($s);
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->em->getRepository(linktitles_setting::class)->findAll()[0];
        $this->assertTrue($loaded->enabled);
        $this->assertSame('#urls', $loaded->url_log_chan);
        $this->assertSame('gpt-4o-mini', $loaded->ai_vision_model);
        $this->assertSame('describe', $loaded->ai_vision_prompt);
        $this->assertSame('low', $loaded->ai_vision_reasoning_effort);
        $this->assertSame(['effort' => 'low'], $loaded->ai_vision_reasoning);
    }

    public function test_defaults_when_unset(): void
    {
        $net = new Network();
        $net->name = 'N';
        $this->em->persist($net);

        $s = new linktitles_setting();
        $s->network = $net;
        $this->em->persist($s);
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->em->getRepository(linktitles_setting::class)->findAll()[0];
        $this->assertFalse($loaded->enabled);
        $this->assertNull($loaded->url_log_chan);
        $this->assertNull($loaded->ai_vision_model);
        $this->assertNull($loaded->ai_vision_prompt);
        $this->assertNull($loaded->ai_vision_reasoning_effort);
        $this->assertNull($loaded->ai_vision_reasoning);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Config/LinktitlesSettingExpansionTest.php`
Expected: FAIL — unknown properties (`enabled`, etc.).

- [ ] **Step 3: Add the columns to the entity**

In `scripts/linktitles/entities/linktitles_setting.php`, keep the existing `id`, `network`, `channel`, `ai_vision_disabled` and add six new properties immediately after `ai_vision_disabled`:

```php
    #[ORM\Column]
    public bool $ai_vision_disabled = false;

    #[ORM\Column]
    public bool $enabled = false;

    #[ORM\Column(nullable: true)]
    public ?string $url_log_chan = null;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $ai_vision_model = null;

    #[ORM\Column(type: "text", nullable: true)]
    public ?string $ai_vision_prompt = null;

    #[ORM\Column(length: 32, nullable: true)]
    public ?string $ai_vision_reasoning_effort = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: "json", nullable: true)]
    public ?array $ai_vision_reasoning = null;
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Config/LinktitlesSettingExpansionTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add scripts/linktitles/entities/linktitles_setting.php tests/Config/LinktitlesSettingExpansionTest.php
git commit -m "feat(linktitles): expand linktitles_setting with enabled/url_log_chan/model/prompt/reasoning"
```

---

### Task 5: `ConfigChange`, `ChangeNotifier`, and domain exceptions

**Files:**
- Create: `library/config/ConfigChange.php`
- Create: `library/config/ChangeNotifier.php`
- Create: `library/config/DuplicateNameException.php`
- Create: `library/config/NotFoundException.php`
- Create: `library/config/InvalidSettingException.php`
- Test: `tests/Config/ChangeNotifierTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Config/ChangeNotifierTest.php`:

```php
<?php
namespace Tests\Config;

use lolbot\config\ChangeNotifier;
use lolbot\config\ConfigChange;
use lolbot\config\NoopChangeNotifier;

require_once __DIR__ . '/../../vendor/autoload.php';

class ChangeNotifierTest extends \PHPUnit\Framework\TestCase
{
    public function test_config_change_holds_fields(): void
    {
        $c = new ConfigChange('network', 7, 'create');
        $this->assertSame('network', $c->entityType);
        $this->assertSame(7, $c->id);
        $this->assertSame('create', $c->action);
    }

    public function test_noop_notifier_implements_interface_and_does_not_throw(): void
    {
        $n = new NoopChangeNotifier();
        $this->assertInstanceOf(ChangeNotifier::class, $n);
        $n->notify(new ConfigChange('bot', 1, 'update')); // must not throw
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Config/ChangeNotifierTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Create the DTO**

`library/config/ConfigChange.php`:

```php
<?php
namespace lolbot\config;

/**
 * Describes a single config mutation, passed to ChangeNotifier after a flush.
 */
final class ConfigChange
{
    public function __construct(
        public readonly string $entityType,
        public readonly ?int $id,
        public readonly string $action, // create | update | delete
    ) {}
}
```

- [ ] **Step 4: Create the interface + noop implementation**

`library/config/ChangeNotifier.php`:

```php
<?php
namespace lolbot\config;

/**
 * Seam called by ConfigService after every successful mutation.
 * The default NoopChangeNotifier does nothing; Sub-project 2 provides an
 * HTTP-push implementation that POSTs to each bot's notifier endpoint.
 */
interface ChangeNotifier
{
    public function notify(ConfigChange $change): void;
}

class NoopChangeNotifier implements ChangeNotifier
{
    public function notify(ConfigChange $change): void
    {
        // intentionally empty
    }
}
```

- [ ] **Step 5: Create the three domain exceptions**

`library/config/DuplicateNameException.php`:

```php
<?php
namespace lolbot\config;

class DuplicateNameException extends \InvalidArgumentException {}
```

`library/config/NotFoundException.php`:

```php
<?php
namespace lolbot\config;

class NotFoundException extends \InvalidArgumentException {}
```

`library/config/InvalidSettingException.php`:

```php
<?php
namespace lolbot\config;

class InvalidSettingException extends \InvalidArgumentException {}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Config/ChangeNotifierTest.php`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add library/config/ConfigChange.php library/config/ChangeNotifier.php \
        library/config/DuplicateNameException.php library/config/NotFoundException.php \
        library/config/InvalidSettingException.php tests/Config/ChangeNotifierTest.php
git commit -m "feat(config): add ConfigChange, ChangeNotifier seam, and domain exceptions"
```

---

### Task 6: `ServiceLocator` (read global service configs)

**Files:**
- Create: `library/config/ServiceLocator.php`
- Test: `tests/Config/ServiceLocatorTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Config/ServiceLocatorTest.php`:

```php
<?php
namespace Tests\Config;

use lolbot\config\ServiceLocator;
use lolbot\entities\AiServiceConfig;
use lolbot\entities\PasteServiceConfig;

require_once __DIR__ . '/../../vendor/autoload.php';

class ServiceLocatorTest extends ConfigTestCase
{
    public function test_returns_null_when_no_config(): void
    {
        $loc = new ServiceLocator($this->em);
        $this->assertNull($loc->getServiceConfig('ai'));
        $this->assertNull($loc->getServiceConfig('paste'));
    }

    public function test_returns_the_single_ai_row(): void
    {
        $ai = new AiServiceConfig();
        $ai->apiKey = 'sk-x';
        $this->em->persist($ai);
        $this->em->flush();

        $loc = new ServiceLocator($this->em);
        $got = $loc->getServiceConfig('ai');
        $this->assertInstanceOf(AiServiceConfig::class, $got);
        $this->assertSame('sk-x', $got->apiKey);
    }

    public function test_unknown_type_returns_null(): void
    {
        $loc = new ServiceLocator($this->em);
        $this->assertNull($loc->getServiceConfig('nope'));
    }

    public function test_service_types_lists_registered(): void
    {
        $loc = new ServiceLocator($this->em);
        $types = $loc->serviceTypes();
        $this->assertContains('ai', $types);
        $this->assertContains('paste', $types);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Config/ServiceLocatorTest.php`
Expected: FAIL — `ServiceLocator` not found.

- [ ] **Step 3: Implement `ServiceLocator`**

`library/config/ServiceLocator.php`:

```php
<?php
namespace lolbot\config;

use Doctrine\ORM\EntityManager;
use lolbot\entities\AiServiceConfig;
use lolbot\entities\PasteServiceConfig;

/**
 * Read-only accessor for global service configs. Each registered type maps to a
 * single-row table; getServiceConfig() returns that row (the singleton) or null.
 */
class ServiceLocator
{
    /** @var array<string, class-string> */
    private const REGISTRY = [
        'ai'    => AiServiceConfig::class,
        'paste' => PasteServiceConfig::class,
    ];

    public function __construct(private EntityManager $em) {}

    /**
     * @return object|null  The singleton service-config entity, or null if none / unknown type.
     */
    public function getServiceConfig(string $type): ?object
    {
        $class = self::REGISTRY[$type] ?? null;
        if ($class === null) {
            return null;
        }
        $rows = $this->em->getRepository($class)->findAll();
        return $rows[0] ?? null;
    }

    /** @return list<string> */
    public function serviceTypes(): array
    {
        return array_keys(self::REGISTRY);
    }

    /**
     * Internal helper for ConfigService upserts.
     * @return class-string|null
     */
    public function entityClassFor(string $type): ?string
    {
        return self::REGISTRY[$type] ?? null;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Config/ServiceLocatorTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add library/config/ServiceLocator.php tests/Config/ServiceLocatorTest.php
git commit -m "feat(config): add ServiceLocator for reading global service configs"
```

---

### Task 7: `ConfigService` — network / bot / channel / server CRUD

**Files:**
- Create: `library/config/ConfigService.php`
- Test: `tests/Config/ConfigServiceCoreTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Config/ConfigServiceCoreTest.php`:

```php
<?php
namespace Tests\Config;

use lolbot\config\ConfigService;
use lolbot\config\DuplicateNameException;
use lolbot\config\NotFoundException;
use lolbot\entities\Channel;
use lolbot\entities\Network;

require_once __DIR__ . '/../../vendor/autoload.php';

class ConfigServiceCoreTest extends ConfigTestCase
{
    private ConfigService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ConfigService($this->em);
    }

    public function test_create_network_and_duplicate(): void
    {
        $n = $this->svc->createNetwork('Libera');
        $this->assertSame('Libera', $n->name);
        $this->assertNotNull($n->id);

        $this->expectException(DuplicateNameException::class);
        $this->svc->createNetwork('Libera');
    }

    public function test_get_and_delete_network(): void
    {
        $n = $this->svc->createNetwork('EFnet');
        $id = $n->id;
        $this->assertSame('EFnet', $this->svc->getNetwork($id)->name);

        $this->svc->deleteNetwork($n);
        $this->assertNull($this->svc->getNetwork($id));
    }

    public function test_create_bot_and_add_channel(): void
    {
        $net = $this->svc->createNetwork('N');
        $bot = $this->svc->createBot($net, 'lolbot');
        $this->assertSame('N', $bot->network->name);

        $chan = $this->svc->addChannel($bot, '#test');
        $this->assertSame('#test', $chan->name);
        $this->assertSame($bot->id, $chan->bot->id);

        $chanId = $chan->id;
        $this->svc->deleteChannel($chan);
        $this->assertNull($this->em->getRepository(\lolbot\entities\Channel::class)->find($chanId));
    }

    public function test_create_bot_unknown_network_throws(): void
    {
        $net = new Network(); // detached, no id
        $net->name = 'ghost';
        $this->expectException(NotFoundException::class);
        $this->svc->createBot($net, 'x');
    }

    public function test_add_server(): void
    {
        $net = $this->svc->createNetwork('N');
        $srv = $this->svc->addServer($net, 'irc.example.net', 7000, true, false, 'pass');
        $this->assertSame('irc.example.net', $srv->address);
        $this->assertSame(7000, $srv->port);
        $this->assertTrue($srv->ssl);
        $this->assertFalse($srv->throttle);
        $this->assertSame('pass', $srv->password);

        $srvId = $srv->id;
        $this->svc->deleteServer($srv);
        $this->assertNull($this->svc->getServer($srvId));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Config/ConfigServiceCoreTest.php`
Expected: FAIL — `ConfigService` not found.

- [ ] **Step 3: Implement `ConfigService` (core CRUD part)**

`library/config/ConfigService.php`:

```php
<?php
namespace lolbot\config;

use Doctrine\ORM\EntityManager;
use lolbot\entities\Bot;
use lolbot\entities\Channel;
use lolbot\entities\Ignore;
use lolbot\entities\Network;
use lolbot\entities\Server;

/**
 * Owns all configuration mutations and validation. The DB is the source of truth.
 * After every successful flush it calls ChangeNotifier::notify(); the default
 * NoopChangeNotifier does nothing (Sub-project 2 wires HTTP push).
 */
class ConfigService
{
    public function __construct(
        private EntityManager $em,
        private ChangeNotifier $notifier = new NoopChangeNotifier(),
    ) {}

    // ---------------- Networks ----------------

    public function createNetwork(string $name): Network
    {
        if ($this->em->getRepository(Network::class)->findOneBy(['name' => $name]) !== null) {
            throw new DuplicateNameException("Network already exists with that name");
        }
        $n = new Network();
        $n->name = $name;
        $this->em->persist($n);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('network', $n->id, 'create'));
        return $n;
    }

    public function getNetwork(int $id): ?Network
    {
        return $this->em->getRepository(Network::class)->find($id);
    }

    public function deleteNetwork(Network $network): void
    {
        $id = $network->id;
        $this->em->remove($network);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('network', $id, 'delete'));
    }

    /** @return list<Network> */
    public function listNetworks(): array
    {
        return $this->em->getRepository(Network::class)->findAll();
    }

    // ---------------- Bots ----------------

    public function createBot(Network $network, string $name): Bot
    {
        if ($network->id === null) {
            throw new NotFoundException("Network does not exist (no id)");
        }
        $managed = $this->em->find(Network::class, $network->id);
        if ($managed === null) {
            throw new NotFoundException("Network not found by id {$network->id}");
        }
        $bot = new Bot();
        $bot->name = $name;
        $bot->network = $managed;
        $this->em->persist($bot);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('bot', $bot->id, 'create'));
        return $bot;
    }

    public function getBot(int $id): ?Bot
    {
        return $this->em->getRepository(Bot::class)->find($id);
    }

    /** @return list<Bot> */
    public function listBots(): array
    {
        return $this->em->getRepository(Bot::class)->findAll();
    }

    public function deleteBot(Bot $bot): void
    {
        $id = $bot->id;
        $this->em->remove($bot);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('bot', $id, 'delete'));
    }

    public function addChannel(Bot $bot, string $name): Channel
    {
        $channel = new Channel();
        $channel->name = $name;
        $bot->addChannel($channel);
        $this->em->persist($channel);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('channel', $channel->id, 'create'));
        return $channel;
    }

    public function deleteChannel(Channel $channel): void
    {
        $id = $channel->id;
        $this->em->remove($channel);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('channel', $id, 'delete'));
    }

    // ---------------- Servers ----------------

    public function addServer(
        Network $network,
        string $address,
        ?int $port = null,
        bool $ssl = false,
        bool $throttle = true,
        ?string $password = null,
    ): Server {
        $server = new Server();
        $server->address = $address;
        $server->setNetwork($network);
        if ($port !== null) {
            if ($port <= 0 || $port > 65536) {
                throw new InvalidSettingException("Invalid port");
            }
            $server->port = $port;
        }
        $server->ssl = $ssl;
        $server->throttle = $throttle;
        if ($password !== null) {
            $server->password = $password;
        }
        $this->em->persist($server);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('server', $server->id, 'create'));
        return $server;
    }

    public function getServer(int $id): ?Server
    {
        return $this->em->getRepository(Server::class)->find($id);
    }

    public function deleteServer(Server $server): void
    {
        $id = $server->id;
        $this->em->remove($server);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('server', $id, 'delete'));
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Config/ConfigServiceCoreTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add library/config/ConfigService.php tests/Config/ConfigServiceCoreTest.php
git commit -m "feat(config): ConfigService network/bot/channel/server CRUD with notifier seam"
```

---

### Task 8: `ConfigService` — ignore CRUD

**Files:**
- Modify: `library/config/ConfigService.php` (append ignore methods)
- Test: `tests/Config/ConfigServiceIgnoreTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Config/ConfigServiceIgnoreTest.php`:

```php
<?php
namespace Tests\Config;

use lolbot\config\ConfigService;
use lolbot\config\NotFoundException;

require_once __DIR__ . '/../../vendor/autoload.php';

class ConfigServiceIgnoreTest extends ConfigTestCase
{
    private ConfigService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ConfigService($this->em);
    }

    public function test_add_ignore_with_networks(): void
    {
        $net = $this->svc->createNetwork('N');
        $ignore = $this->svc->addIgnore('*@*.bad', 'spam', [$net]);
        $this->assertSame('*@*.bad', $ignore->hostmask);
        $this->assertSame('spam', $ignore->reason);
        $this->assertTrue($ignore->assignedToNetwork($net));
    }

    public function test_add_ignore_unknown_network_throws(): void
    {
        $net = $this->svc->createNetwork('N');
        $this->em->detach($net);
        $this->expectException(NotFoundException::class);
        $this->svc->addIgnore('*@*.bad', null, [$net]);
    }

    public function test_add_ignore_networks_to_existing(): void
    {
        $n1 = $this->svc->createNetwork('A');
        $n2 = $this->svc->createNetwork('B');
        $ignore = $this->svc->addIgnore('*@*.bad', null, [$n1]);
        $this->svc->addIgnoreNetworks($ignore, [$n2]);
        $this->assertTrue($ignore->assignedToNetwork($n1));
        $this->assertTrue($ignore->assignedToNetwork($n2));
    }

    public function test_delete_ignore(): void
    {
        $net = $this->svc->createNetwork('N');
        $ignore = $this->svc->addIgnore('*@*.bad', null, [$net]);
        $id = $ignore->id;
        $this->svc->deleteIgnore($ignore);
        $this->assertNull($this->em->getRepository(\lolbot\entities\Ignore::class)->find($id));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Config/ConfigServiceIgnoreTest.php`
Expected: FAIL — `addIgnore` not found.

- [ ] **Step 3: Append ignore methods to `ConfigService`**

Add this `use` at the top of `library/config/ConfigService.php` with the others (if not present):

```php
use lolbot\entities\Ignore;
```

Append inside the `ConfigService` class, before the final closing brace:

```php
    // ---------------- Ignores ----------------

    /**
     * @param list<Network> $networks
     */
    public function addIgnore(string $hostmask, ?string $reason, array $networks): Ignore
    {
        $ignore = new Ignore();
        $ignore->hostmask = $hostmask;
        if ($reason !== null) {
            $ignore->reason = $reason;
        }
        foreach ($networks as $network) {
            if ($network->id === null || $this->em->find(Network::class, $network->id) === null) {
                throw new NotFoundException("Network not found (id=" . ($network->id ?? 'null') . ")");
            }
            $ignore->addToNetwork($network);
        }
        $this->em->persist($ignore);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('ignore', $ignore->id, 'create'));
        return $ignore;
    }

    /**
     * @param list<Network> $networks
     */
    public function addIgnoreNetworks(Ignore $ignore, array $networks): void
    {
        foreach ($networks as $network) {
            if ($network->id === null || $this->em->find(Network::class, $network->id) === null) {
                throw new NotFoundException("Network not found (id=" . ($network->id ?? 'null') . ")");
            }
            $ignore->addToNetwork($network);
        }
        $this->em->persist($ignore);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('ignore', $ignore->id, 'update'));
    }

    public function getIgnore(int $id): ?Ignore
    {
        return $this->em->getRepository(Ignore::class)->find($id);
    }

    /** @return list<Ignore> */
    public function listIgnores(): array
    {
        return $this->em->getRepository(Ignore::class)->findAll();
    }

    public function deleteIgnore(Ignore $ignore): void
    {
        $id = $ignore->id;
        $this->em->remove($ignore);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('ignore', $id, 'delete'));
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Config/ConfigServiceIgnoreTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add library/config/ConfigService.php tests/Config/ConfigServiceIgnoreTest.php
git commit -m "feat(config): ConfigService ignore CRUD"
```

---

### Task 9: `ConfigService` — service-config upsert + linktitles settings

**Files:**
- Modify: `library/config/ConfigService.php` (append service + script-settings methods)
- Test: `tests/Config/ConfigServiceSettingsTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Config/ConfigServiceSettingsTest.php`:

```php
<?php
namespace Tests\Config;

use lolbot\config\ConfigService;
use lolbot\config\InvalidSettingException;
use lolbot\entities\AiServiceConfig;
use lolbot\entities\Network;
use lolbot\entities\PasteServiceConfig;

require_once __DIR__ . '/../../vendor/autoload.php';

class ConfigServiceSettingsTest extends ConfigTestCase
{
    private ConfigService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ConfigService($this->em);
    }

    public function test_set_service_config_value_creates_singleton_row(): void
    {
        $this->svc->setServiceConfigValue('ai', 'apiKey', 'sk-1');
        $this->svc->setServiceConfigValue('ai', 'maxDim', 2048);

        $ai = $this->em->getRepository(AiServiceConfig::class)->findAll();
        $this->assertCount(1, $ai); // single row
        $this->assertSame('sk-1', $ai[0]->apiKey);
        $this->assertSame(2048, $ai[0]->maxDim);
    }

    public function test_set_service_config_value_updates_existing_row(): void
    {
        $this->svc->setServiceConfigValue('paste', 'host', 'http://a');
        $this->svc->setServiceConfigValue('paste', 'key', 'k');
        $this->svc->setServiceConfigValue('paste', 'host', 'http://b'); // update

        $p = $this->em->getRepository(PasteServiceConfig::class)->findAll();
        $this->assertCount(1, $p);
        $this->assertSame('http://b', $p[0]->host);
        $this->assertSame('k', $p[0]->key);
    }

    public function test_set_service_config_unknown_type_throws(): void
    {
        $this->expectException(InvalidSettingException::class);
        $this->svc->setServiceConfigValue('nope', 'x', 'y');
    }

    public function test_set_service_config_unknown_key_throws(): void
    {
        $this->expectException(InvalidSettingException::class);
        $this->svc->setServiceConfigValue('ai', 'notAField', 'y');
    }

    public function test_set_linktitles_setting_creates_and_updates(): void
    {
        $net = $this->svc->createNetwork('N');
        $this->svc->setLinktitlesSetting($net, null, 'enabled', true);
        $this->svc->setLinktitlesSetting($net, null, 'url_log_chan', '#urls');

        $rows = $this->em->getRepository(\scripts\linktitles\entities\linktitles_setting::class)->findAll();
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]->enabled);
        $this->assertSame('#urls', $rows[0]->url_log_chan);
    }

    public function test_reset_linktitles_setting_clears_field(): void
    {
        $net = $this->svc->createNetwork('N');
        $this->svc->setLinktitlesSetting($net, null, 'ai_vision_model', 'gpt-4o-mini');
        $this->svc->resetLinktitlesSetting($net, null, 'ai_vision_model');

        $row = $this->em->getRepository(\scripts\linktitles\entities\linktitles_setting::class)->findAll()[0];
        $this->assertNull($row->ai_vision_model);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Config/ConfigServiceSettingsTest.php`
Expected: FAIL — `setServiceConfigValue` not found.

- [ ] **Step 3: Append service + script-settings methods to `ConfigService`**

Add these `use` lines at the top of `library/config/ConfigService.php` (with the others):

```php
use lolbot\entities\AiServiceConfig;
use lolbot\entities\Channel;
use scripts\linktitles\entities\linktitles_setting;
```

Append inside the class, before the final closing brace. The linktitles-setting whitelist mirrors the entity's new fields plus the existing `ai_vision_disabled`:

```php
    // ---------------- Service config (global singletons) ----------------

    /** Known writable keys per service type (entity property names). */
    private const SERVICE_KEYS = [
        'ai' => ['apiKey', 'baseUrl', 'maxDim', 'jpgQuality', 'timeout', 'reasoningEffort', 'reasoning'],
        'paste' => ['host', 'key'],
    ];

    public function setServiceConfigValue(string $type, string $key, mixed $value): void
    {
        $class = (new ServiceLocator($this->em))->entityClassFor($type);
        if ($class === null) {
            throw new InvalidSettingException("Unknown service type: $type");
        }
        if (!in_array($key, self::SERVICE_KEYS[$type], true)) {
            throw new InvalidSettingException("Unknown setting '$key' for service '$type'");
        }
        $rows = $this->em->getRepository($class)->findAll();
        $row = $rows[0] ?? null;
        if ($row === null) {
            $row = new $class();
            $this->em->persist($row);
        }
        $row->$key = $value;
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('service:' . $type, $row->id, 'update'));
    }

    // ---------------- Script settings (linktitles) ----------------

    /** Writable linktitles_setting keys (property names). */
    private const LINKTITLES_KEYS = [
        'enabled', 'url_log_chan', 'ai_vision_disabled',
        'ai_vision_model', 'ai_vision_prompt',
        'ai_vision_reasoning_effort', 'ai_vision_reasoning',
    ];

    private function findOrCreateLinktitlesSetting(Network $network, ?Channel $channel): linktitles_setting
    {
        $repo = $this->em->getRepository(linktitles_setting::class);
        $setting = $repo->findOneBy([
            'network' => $channel !== null ? null : $network,
            'channel' => $channel,
        ]);
        if ($setting === null) {
            $setting = new linktitles_setting();
            $setting->network = $channel !== null ? null : $network;
            $setting->channel = $channel;
            $this->em->persist($setting);
        }
        return $setting;
    }

    public function setLinktitlesSetting(Network $network, ?Channel $channel, string $key, mixed $value): linktitles_setting
    {
        if (!in_array($key, self::LINKTITLES_KEYS, true)) {
            throw new InvalidSettingException("Unknown linktitles setting: $key");
        }
        $setting = $this->findOrCreateLinktitlesSetting($network, $channel);
        $setting->$key = $value;
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('linktitles_setting', $setting->id, 'update'));
        return $setting;
    }

    public function resetLinktitlesSetting(Network $network, ?Channel $channel, string $key): void
    {
        if (!in_array($key, self::LINKTITLES_KEYS, true)) {
            throw new InvalidSettingException("Unknown linktitles setting: $key");
        }
        $repo = $this->em->getRepository(linktitles_setting::class);
        $setting = $repo->findOneBy([
            'network' => $channel !== null ? null : $network,
            'channel' => $channel,
        ]);
        if ($setting !== null) {
            $setting->$key = null;
            // booleans reset to false rather than null
            if (in_array($key, ['enabled', 'ai_vision_disabled'], true)) {
                $setting->$key = false;
            }
            $this->em->flush();
            $this->notifier->notify(new ConfigChange('linktitles_setting', $setting->id, 'update'));
        }
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Config/ConfigServiceSettingsTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add library/config/ConfigService.php tests/Config/ConfigServiceSettingsTest.php
git commit -m "feat(config): ConfigService service-config upsert + linktitles settings"
```

---

### Task 10: `SettingsResolver` (linktitles channel→network + service reads)

**Files:**
- Create: `library/config/SettingsResolver.php`
- Test: `tests/Config/SettingsResolverTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Config/SettingsResolverTest.php`:

```php
<?php
namespace Tests\Config;

use lolbot\config\ConfigService;
use lolbot\config\SettingsResolver;
use lolbot\entities\Channel;
use lolbot\entities\Network;
use scripts\linktitles\entities\linktitles_setting;

require_once __DIR__ . '/../../vendor/autoload.php';

class SettingsResolverTest extends ConfigTestCase
{
    private ConfigService $svc;
    private SettingsResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ConfigService($this->em);
        $this->resolver = new SettingsResolver($this->em);
    }

    public function test_returns_null_setting_when_none(): void
    {
        $net = $this->svc->createNetwork('N');
        $this->assertNull($this->resolver->getLinktitlesSetting($net, null));
        $this->assertFalse($this->resolver->linktitlesEnabled($net, null));
    }

    public function test_network_setting_resolves(): void
    {
        $net = $this->svc->createNetwork('N');
        $this->svc->setLinktitlesSetting($net, null, 'enabled', true);
        $this->assertTrue($this->resolver->linktitlesEnabled($net, null));
    }

    public function test_channel_overrides_network(): void
    {
        $net = $this->svc->createNetwork('N');
        $bot = $this->svc->createBot($net, 'b');
        $chan = $this->svc->addChannel($bot, '#c');

        // network: enabled=true; channel row: enabled=false overrides
        $this->svc->setLinktitlesSetting($net, null, 'enabled', true);
        $this->svc->setLinktitlesSetting($net, $chan, 'enabled', false);

        $this->assertFalse($this->resolver->linktitlesEnabled($net, $chan));
        $this->assertTrue($this->resolver->linktitlesEnabled($net, null)); // no channel → network
    }

    public function test_url_log_chan_resolves(): void
    {
        $net = $this->svc->createNetwork('N');
        $this->svc->setLinktitlesSetting($net, null, 'url_log_chan', '#urls');
        $this->assertSame('#urls', $this->resolver->urlLogChan($net, null));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Config/SettingsResolverTest.php`
Expected: FAIL — `SettingsResolver` not found.

- [ ] **Step 3: Implement `SettingsResolver`**

`library/config/SettingsResolver.php`:

```php
<?php
namespace lolbot\config;

use Doctrine\ORM\EntityManager;
use lolbot\entities\Channel;
use lolbot\entities\Network;
use scripts\linktitles\entities\linktitles_setting;

/**
 * Resolves effective per-scope settings for a running bot.
 *
 * linktitles_setting resolution follows the existing linktitles.php convention:
 * a channel-scoped row (if present) wins; otherwise the network-scoped row;
 * otherwise null (caller applies code defaults).
 */
class SettingsResolver
{
    public function __construct(private EntityManager $em) {}

    public function getLinktitlesSetting(Network $network, ?Channel $channel): ?linktitles_setting
    {
        $repo = $this->em->getRepository(linktitles_setting::class);
        if ($channel !== null) {
            $s = $repo->findOneBy(['channel' => $channel]);
            if ($s !== null) {
                return $s;
            }
        }
        return $repo->findOneBy(['network' => $network, 'channel' => null]);
    }

    public function linktitlesEnabled(Network $network, ?Channel $channel): bool
    {
        return $this->getLinktitlesSetting($network, $channel)?->enabled ?? false;
    }

    public function urlLogChan(Network $network, ?Channel $channel): ?string
    {
        return $this->getLinktitlesSetting($network, $channel)?->url_log_chan ?? null;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Config/SettingsResolverTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Run the whole Config suite to confirm nothing regressed**

Run: `vendor/bin/phpunit tests/Config`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add library/config/SettingsResolver.php tests/Config/SettingsResolverTest.php
git commit -m "feat(config): SettingsResolver for linktitles channel->network resolution"
```

---

### Task 11: Doctrine migration — real schema

**Files:**
- Create: `Migrations/Version20260619120000.php`

This creates the two new service tables and expands `linktitles_settings`. It mirrors the two existing migration styles: `createTable` (see `Version20260606120000.php`) and column-add via comparator (see `Version20260530120000.php`).

- [ ] **Step 1: Write the migration**

`Migrations/Version20260619120000.php`:

```php
<?php

declare(strict_types=1);

namespace lolbot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260619120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ai_service_config and paste_service_config tables; expand linktitles_settings';
    }

    public function up(Schema $schema): void
    {
        $ai = $schema->createTable("ai_service_config");
        $ai->addColumn("id", Types::INTEGER)->setNotnull(true)->setAutoincrement(true);
        $ai->setPrimaryKey(["id"]);
        $ai->addColumn("api_key", Types::STRING)->setLength(512)->setNotnull(false);
        $ai->addColumn("base_url", Types::STRING)->setNotnull(false);
        $ai->addColumn("max_dim", Types::INTEGER)->setNotnull(true)->setDefault(1024);
        $ai->addColumn("jpg_quality", Types::INTEGER)->setNotnull(true)->setDefault(85);
        $ai->addColumn("timeout", Types::INTEGER)->setNotnull(true)->setDefault(10);
        $ai->addColumn("reasoning_effort", Types::STRING)->setLength(32)->setNotnull(false);
        $ai->addColumn("reasoning", Types::JSON)->setNotnull(false);

        $paste = $schema->createTable("paste_service_config");
        $paste->addColumn("id", Types::INTEGER)->setNotnull(true)->setAutoincrement(true);
        $paste->setPrimaryKey(["id"]);
        $paste->addColumn("host", Types::STRING)->setNotnull(false);
        $paste->addColumn("key", Types::STRING)->setNotnull(false);

        // Expand linktitles_settings via comparator (portable ALTER), mirroring
        // Migrations/Version20260530120000.php.
        $sm = $this->connection->createSchemaManager();
        $comp = $sm->createComparator();
        $newSchema = clone $schema;

        $t = $newSchema->getTable("linktitles_settings");
        $t->addColumn("enabled", Types::BOOLEAN)->setNotnull(true)->setDefault(false);
        $t->addColumn("url_log_chan", Types::STRING)->setNotnull(false);
        $t->addColumn("ai_vision_model", Types::STRING)->setLength(64)->setNotnull(false);
        $t->addColumn("ai_vision_prompt", Types::TEXT)->setNotnull(false);
        $t->addColumn("ai_vision_reasoning_effort", Types::STRING)->setLength(32)->setNotnull(false);
        $t->addColumn("ai_vision_reasoning", Types::JSON)->setNotnull(false);

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable("ai_service_config");
        $schema->dropTable("paste_service_config");

        $sm = $this->connection->createSchemaManager();
        $comp = $sm->createComparator();
        $newSchema = clone $schema;

        $t = $newSchema->getTable("linktitles_settings");
        foreach (["enabled", "url_log_chan", "ai_vision_model", "ai_vision_prompt", "ai_vision_reasoning_effort", "ai_vision_reasoning"] as $col) {
            $t->dropColumn($col);
        }

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }
}
```

- [ ] **Step 2: Verify the migration generates correct SQL (no execute)**

Run: `php admin-cli.php migrations:migrate --dry-run`
Expected: lists the `CREATE TABLE ai_service_config ...`, `CREATE TABLE paste_service_config ...`, and the `ALTER TABLE linktitles_settings ADD ...` statements; no errors. (The migration commands are registered in `admin-cli.php`; `migrations.yml` holds the config.)

- [ ] **Step 3: Confirm dev DB applies cleanly**

Run: `php admin-cli.php migrations:migrate --allow-no-migration`
Expected: the migration applies (or reports "No migrations"), no SQL errors. Then verify columns landed:

```
php admin-cli.php migrations:status
```
Expected: shows the new version applied.

- [ ] **Step 4: Confirm the test suite still passes**

Run: `vendor/bin/phpunit tests/Config`
Expected: all green (tests use metadata schema, not the migration — this just guards against accidental edits).

- [ ] **Step 5: Commit**

```bash
git add Migrations/Version20260619120000.php
git commit -m "feat(db): migration for ai/paste service config + linktitles_settings expansion"
```

---

### Task 12: `service:get`, `service:set`, `service:list` commands

**Files:**
- Create: `cli_cmds/service_get.php`
- Create: `cli_cmds/service_set.php`
- Create: `cli_cmds/service_list.php`
- Modify: `admin-cli.php` (register the three)

These mirror the existing `cli_cmds/*` Symfony Console style. Reads use `ServiceLocator`; writes use `ConfigService`.

- [ ] **Step 1: Create `service:get`**

`cli_cmds/service_get.php`:

```php
<?php
namespace lolbot\cli_cmds;
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;

use lolbot\config\ServiceLocator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand("service:get")]
class service_get extends Command
{
    protected function configure(): void
    {
        $this->addArgument("type", InputArgument::REQUIRED, "Service type (e.g. ai, paste)");
        $this->addArgument("key", InputArgument::OPTIONAL, "Specific key; omit to show all");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        /** @var \Doctrine\ORM\EntityManager $em */
        global $entityManager;
        $type = $input->getArgument("type");
        $locator = new ServiceLocator($entityManager);
        $cfg = $locator->getServiceConfig($type);
        if ($cfg === null) {
            $output->writeln("<comment>No '$type' service config is set.</comment>");
            return Command::SUCCESS;
        }
        $key = $input->getArgument("key");
        if ($key !== null) {
            if (!property_exists($cfg, $key)) {
                throw new \InvalidArgumentException("No key '$key' on service '$type'");
            }
            $output->writeln("$key=" . self::display($cfg->$key));
            return Command::SUCCESS;
        }
        $output->writeln((string)$cfg);
        return Command::SUCCESS;
    }

    private static function display(mixed $v): string
    {
        if (is_array($v)) {
            return json_encode($v, JSON_UNESCAPED_SLASHES);
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        return (string)$v;
    }
}
```

- [ ] **Step 2: Create `service:set`**

`cli_cmds/service_set.php`:

```php
<?php
namespace lolbot\cli_cmds;
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;

use lolbot\config\ConfigService;
use lolbot\config\ServiceLocator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand("service:set")]
class service_set extends Command
{
    protected function configure(): void
    {
        $this->addArgument("type", InputArgument::REQUIRED, "Service type (e.g. ai, paste)");
        $this->addArgument("key", InputArgument::REQUIRED, "Setting key");
        $this->addArgument("value", InputArgument::REQUIRED, "New value");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        /** @var \Doctrine\ORM\EntityManager $em */
        global $entityManager;
        $type = $input->getArgument("type");
        $key = $input->getArgument("key");
        $raw = $input->getArgument("value");

        $locator = new ServiceLocator($em);
        if ($locator->entityClassFor($type) === null) {
            throw new \InvalidArgumentException("Unknown service type: $type");
        }
        $class = $locator->entityClassFor($type);
        if (!property_exists($class, $key)) {
            throw new \InvalidArgumentException("No key '$key' on service '$type'");
        }

        // Coerce ints/bools/json by reflection of the entity property type.
        $value = self::coerce(new \ReflectionProperty($class, $key), $raw);

        $svc = new ConfigService($em);
        $svc->setServiceConfigValue($type, $key, $value);

        $output->writeln("<info>Set $type.$key = " . (is_array($value) ? json_encode($value) : (string)$value) . "</info>");
        return Command::SUCCESS;
    }

    private static function coerce(\ReflectionProperty $prop, string $raw): mixed
    {
        $type = $prop->getType();
        if ($type instanceof \ReflectionNamedType) {
            return match ($type->getName()) {
                'int' => (int)$raw,
                'bool' => filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                    ?? throw new \InvalidArgumentException("Value must be true or false"),
                'array' => json_decode($raw, true)
                    ?? throw new \InvalidArgumentException("Value must be valid JSON for an array key"),
                default => $raw,
            };
        }
        return $raw;
    }
}
```

- [ ] **Step 3: Create `service:list`**

`cli_cmds/service_list.php`:

```php
<?php
namespace lolbot\cli_cmds;
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;

use lolbot\config\ServiceLocator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand("service:list")]
class service_list extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int {
        /** @var \Doctrine\ORM\EntityManager $em */
        global $entityManager;
        $locator = new ServiceLocator($entityManager);
        foreach ($locator->serviceTypes() as $type) {
            $cfg = $locator->getServiceConfig($type);
            $state = $cfg === null ? '<comment>(unset)</comment>' : '<info>(set)</info>';
            $output->writeln("$type $state");
        }
        return Command::SUCCESS;
    }
}
```

- [ ] **Step 4: Register the commands**

In `admin-cli.php`, add after the existing `new cli_cmds\showdb()` line (around line 53):

```php
$application->add(new cli_cmds\service_get());
$application->add(new cli_cmds\service_set());
$application->add(new cli_cmds\service_list());
```

- [ ] **Step 5: Smoke-test the commands end to end**

Run each and check output:

```
php admin-cli.php service:list
php admin-cli.php service:set paste host http://localhost:8080
php admin-cli.php service:set paste key testkey
php admin-cli.php service:get paste
php admin-cli.php service:get paste host
php admin-cli.php service:set ai apiKey sk-test
php admin-cli.php service:set ai maxDim 1024
php admin-cli.php service:get ai
```
Expected: each succeeds; `service:get paste` shows host/key; `service:get ai` shows the apiKey/maxDim.

- [ ] **Step 6: Commit**

```bash
git add cli_cmds/service_get.php cli_cmds/service_set.php cli_cmds/service_list.php admin-cli.php
git commit -m "feat(cli): add service:get/set/list commands for AI/paste service config"
```

---

### Task 13: `config:import` — idempotent YAML→DB backfill

**Files:**
- Create: `cli_cmds/config_import.php`
- Modify: `admin-cli.php` (register it)

Imports existing `config.yaml` values into the new tables so a deployment keeps its current behavior on first run. Idempotent: rows are only created where a value is non-default; `--force` overwrites existing rows.

Mapping:
- `ai_vision_key`, `ai_vision_base_url`, `ai_vision_max_dim`, `ai_vision_jpg_quality`, `ai_vision_timeout`, `ai_vision_reasoning_effort`, `ai_vision_reasoning` → AI service config.
- `paste_host`, `paste_key` → paste service config.
- For each `bots.<id>` with `linktitles: true` or `url_log_chan`: write a network-scoped `linktitles_setting` row (`channel = null`) for that bot's network.

- [ ] **Step 1: Create `config_import`**

`cli_cmds/config_import.php`:

```php
<?php
namespace lolbot\cli_cmds;
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager, $config;

use lolbot\config\ConfigService;
use lolbot\config\ServiceLocator;
use lolbot\entities\Bot;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand("config:import")]
class config_import extends Command
{
    protected function configure(): void
    {
        $this->setDescription("Import legacy config.yaml values (ai_vision_*, paste_*, per-bot linktitles/url_log_chan) into the database.");
        $this->addOption("force", "f", InputOption::VALUE_NONE, "Overwrite existing DB rows");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        /** @var \Doctrine\ORM\EntityManager $em */
        global $entityManager, $config;
        $force = (bool)$input->getOption("force");
        $svc = new ConfigService($em);
        $locator = new ServiceLocator($em);

        $imported = 0;

        // --- AI service ---
        $aiMap = [
            'apiKey'  => ['ai_vision_key',           null],
            'baseUrl' => ['ai_vision_base_url',      null],
            'maxDim'  => ['ai_vision_max_dim',       1024],
            'jpgQuality' => ['ai_vision_jpg_quality', 85],
            'timeout' => ['ai_vision_timeout',       10],
            'reasoningEffort' => ['ai_vision_reasoning_effort', null],
            'reasoning' => ['ai_vision_reasoning',   null],
        ];
        foreach ($aiMap as $prop => [$yamlKey, $default]) {
            if (!array_key_exists($yamlKey, $config)) {
                continue;
            }
            $val = $config[$yamlKey];
            if (!$force && $default !== null && $val === $default) {
                continue; // skip default-valued
            }
            $existing = $locator->getServiceConfig('ai');
            if (!$force && $existing !== null && $existing->$prop !== null && !self::isAiDefault($prop, $existing->$prop)) {
                $output->writeln("<comment>skip ai.$prop (already set, use --force)</comment>");
                continue;
            }
            $svc->setServiceConfigValue('ai', $prop, $val);
            $output->writeln("imported ai.$prop");
            $imported++;
        }

        // --- Paste service ---
        foreach (['host' => 'paste_host', 'key' => 'paste_key'] as $prop => $yamlKey) {
            if (!array_key_exists($yamlKey, $config)) {
                continue;
            }
            $existing = $locator->getServiceConfig('paste');
            if (!$force && $existing !== null && $existing->$prop !== null) {
                $output->writeln("<comment>skip paste.$prop (already set, use --force)</comment>");
                continue;
            }
            $svc->setServiceConfigValue('paste', $prop, $config[$yamlKey]);
            $output->writeln("imported paste.$prop");
            $imported++;
        }

        // --- Per-bot linktitles / url_log_chan (network-scoped rows) ---
        foreach ($config['bots'] ?? [] as $botId => $botCfg) {
            $bot = $em->find(Bot::class, (int)$botId);
            if ($bot === null) {
                $output->writeln("<comment>skip bots.$botId (not found in DB)</comment>");
                continue;
            }
            $network = $bot->network;
            if (isset($botCfg['linktitles']) && $botCfg['linktitles'] === true) {
                $svc->setLinktitlesSetting($network, null, 'enabled', true);
                $output->writeln("imported linktitles enabled for network {$network->name} (from bot $botId)");
                $imported++;
            }
            if (isset($botCfg['url_log_chan'])) {
                $svc->setLinktitlesSetting($network, null, 'url_log_chan', $botCfg['url_log_chan']);
                $output->writeln("imported url_log_chan for network {$network->name} (from bot $botId)");
                $imported++;
            }
        }

        $output->writeln("<info>Imported $imported value(s).</info>");
        return Command::SUCCESS;
    }

    private static function isAiDefault(string $prop, mixed $val): bool
    {
        return match ($prop) {
            'maxDim' => $val === 1024,
            'jpgQuality' => $val === 85,
            'timeout' => $val === 10,
            default => false,
        };
    }
}
```

- [ ] **Step 2: Register the command**

In `admin-cli.php`, add near the other new service commands:

```php
$application->add(new cli_cmds\config_import());
```

- [ ] **Step 3: Smoke-test idempotency**

```
php admin-cli.php service:set paste host http://preexisting
php admin-cli.php config:import
```
Expected: prints `skip paste.host (already set, use --force)` (and similarly for any AI keys already set earlier in Task 12). Then:

```
php admin-cli.php config:import --force
```
Expected: overwrites from `config.yaml`.

- [ ] **Step 4: Run the full test suite**

Run: `vendor/bin/phpunit tests/Config`
Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add cli_cmds/config_import.php admin-cli.php
git commit -m "feat(cli): add config:import to backfill config.yaml values into DB"
```

---

## Definition of done (Plan 1A)

- `vendor/bin/phpunit tests/Config` is green.
- `php admin-cli.php migrations:status` shows `Version20260619120000` applied.
- `service:get/set/list` and `config:import` commands work end to end.
- `ConfigService`, `ServiceLocator`, `SettingsResolver`, `ChangeNotifier`, `ConfigChange`, and the three exceptions exist under `library/config/` with type-coerced, validated mutations.
- `AiServiceConfig`, `PasteServiceConfig`, and the expanded `linktitles_setting` are persisted by Doctrine.

Next: **Plan 1B — Wiring** (`admin-cli` existing commands onto `ConfigService`; migrate the `linktitles` AI consumer and `help`/`alias` paste consumers; `lolbot.php` reads via `SettingsResolver`; `config.yaml` cleanup).

---

## Implementation notes (deviations made during execution)

These were necessary, verified departures from the literal task text above, applied while executing the plan under PHPStan level 9 and real-DB smoke tests:

1. **`NoopChangeNotifier` lives in its own file** (`library/config/NoopChangeNotifier.php`), NOT co-located in `ChangeNotifier.php`. The original Task 5 text co-located them, but that violates PSR-4 (Composer cannot autoload a second class from one file). No `classmap` was added; the repo stays pure PSR-4, one class per file.

2. **`AiServiceConfig` camelCase properties are explicitly mapped to snake_case columns** via `#[ORM\Column(name: 'api_key', …)]` (and `base_url`, `max_dim`, `jpg_quality`, `reasoning_effort`). The migration created snake_case columns while the entity properties are camelCase; without explicit `name:` Doctrine's `DefaultNamingStrategy` expected camelCase columns and real-DB queries failed with `no such column: t0.apiKey`. (The metadata-based test harness masked this — end-to-end CLI smoke tests caught it.) `PasteServiceConfig` was unaffected (`host`/`key` are single words).

3. **Port validation bound corrected** in `ConfigService::addServer()`: `> 65536` → `> 65535` (the plan's value, copied from the legacy `server_add.php`, allowed the invalid port 65536). Plus an invalid-port test and a `CapturingNotifier` seam test were added.

4. **`Bot.php` annotation fix:** `trigger`, `trigger_re`, `sasl_user`, `sasl_pass` changed from `#[ORM\Column]` to `#[ORM\Column(nullable: true)]` to match the real migrations (`setNotnull(false)`) and their `?string` PHP types. Latent bug surfaced by the test harness.

5. **`ConfigService::createBot` / ignore methods** use `isset($entity->id)` (not `=== null`) and `$this->em->contains($network)` for the ignore network guard, because entity `id` properties are typed `int` (uninitialized before persist) and `detach()`ed entities still have DB rows that `find()` would re-return.

6. **`SettingsResolver` accessors** use PHPStan-clean forms (`$setting !== null && $setting->enabled`; bare `?->url_log_chan`) instead of `$x?->prop ?? $default`, which trips PHPStan's `nullsafe.neverNull` rule. Semantically identical.

7. **Dynamic property writes** (`$row->$key = $value`) in `ConfigService` settings setters were refactored to typed `match` dispatch + a `normalizeStringKeyedArray` helper to satisfy PHPStan level 9 with a `mixed $value` API (and add runtime type validation). Behavior unchanged.

8. **`ConfigService::update(object, string)`** helper was added with Task 1B (Wiring) in mind (the `*:set` commands use it) — not present at the end of Plan 1A; it arrives in Plan 1B Task 1.

