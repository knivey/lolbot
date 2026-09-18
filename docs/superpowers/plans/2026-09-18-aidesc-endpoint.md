# AI Image Description Endpoint (`POST /aidesc`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expose a key-authed `POST /aidesc` endpoint on the channel bot's control server that accepts raw image bytes (16MB cap) and returns a plain-text AI description, backed by DB-stored scoped API keys (CLI + web admin) and a shared describer refactored out of linktitles.

**Architecture:** A new `api_keys` Doctrine entity (key/label/scopes JSON) is administered through `ConfigService` (CLI `apikey:*` commands + web panel section). The vision pipeline moves from `linktitles::getAiDescription()` into a standalone `scripts/linktitles/ImageDescriber` class returning a typed `DescribeResult`; the IRC path maps results to profile tags, the new endpoint handler maps them to HTTP statuses. Keys are read per-request via a `HINT_REFRESH` repository lookup so the long-lived bot EntityManager never serves stale rows.

**Tech Stack:** PHP 8.1, Amp HTTP server/client, Doctrine ORM 2.x + migrations (SQLite tests / Postgres prod), PHPUnit 10 (`composer test`), Symfony Console + CommandTester, Twig web panel, Imagick, `knivey/amphp-openai`.

**Spec:** `docs/superpowers/specs/2026-09-18-aidesc-endpoint-design.md`

**Conventions for every task:** never remove existing comments; never `git add -f`; run the exact test commands shown. Web/CLI test boot pattern sets `$GLOBALS['entityManager']` and `$GLOBALS['config']` (see `tests/Config/NetworkSetCommandTest.php:14-15`).

---

### Task 1: ApiKey entity + repository + migration

**Files:**
- Create: `entities/ApiKey.php`
- Create: `entities/ApiKeyRepository.php`
- Create: `Migrations/Version20260918120000.php`
- Test: `tests/Config/ApiKeyEntityTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Config/ApiKeyEntityTest.php`:

```php
<?php
namespace Tests\Config;

use lolbot\entities\ApiKey;

require_once __DIR__ . '/../../vendor/autoload.php';

class ApiKeyEntityTest extends ConfigTestCase
{
    public function test_round_trip_persist_and_load(): void
    {
        $key = new ApiKey();
        $key->key = 's3cr3t';
        $key->label = 'image upload site';
        $key->scopes = ['aidesc'];
        $this->em->persist($key);
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->em->getRepository(ApiKey::class)->find($key->id);
        $this->assertNotNull($loaded);
        $this->assertSame('s3cr3t', $loaded->key);
        $this->assertSame('image upload site', $loaded->label);
        $this->assertSame(['aidesc'], $loaded->scopes);
        $this->assertInstanceOf(\DateTimeImmutable::class, $loaded->created);
    }

    public function test_hasscope(): void
    {
        $key = new ApiKey();
        $key->key = 'k';
        $key->scopes = ['aidesc', 'notifier'];
        $this->assertTrue($key->hasScope('aidesc'));
        $this->assertTrue($key->hasScope('notifier'));
        $this->assertFalse($key->hasScope('nope'));
    }

    public function test_duplicate_key_rejected_by_unique_index(): void
    {
        $a = new ApiKey();
        $a->key = 'same';
        $a->scopes = ['aidesc'];
        $this->em->persist($a);
        $this->em->flush();

        $b = new ApiKey();
        $b->key = 'same';
        $b->scopes = ['aidesc'];
        $this->em->persist($b);
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function test_findbykey_sees_deletes_from_another_entity_manager(): void
    {
        $key = new ApiKey();
        $key->key = 'k1';
        $key->scopes = ['aidesc'];
        $this->em->persist($key);
        $this->em->flush();

        $repo = $this->em->getRepository(ApiKey::class);
        $this->assertNotNull($repo->findByKey('k1'));

        $em2 = new \Doctrine\ORM\EntityManager($this->em->getConnection(), $this->em->getConfiguration());
        $fresh = $em2->find(ApiKey::class, $key->id);
        $this->assertNotNull($fresh);
        $em2->remove($fresh);
        $em2->flush();

        // The first EM still holds the entity in its identity map; the
        // HINT_REFRESH lookup must reflect the DB, not the stale map.
        $this->assertNull($repo->findByKey('k1'));
    }

    public function test_findbykey_sees_updates_from_another_entity_manager(): void
    {
        $key = new ApiKey();
        $key->key = 'k2';
        $key->scopes = ['aidesc'];
        $this->em->persist($key);
        $this->em->flush();

        $em2 = new \Doctrine\ORM\EntityManager($this->em->getConnection(), $this->em->getConfiguration());
        $fresh = $em2->find(ApiKey::class, $key->id);
        $this->assertNotNull($fresh);
        $fresh->scopes = ['notifier'];
        $em2->flush();

        $repo = $this->em->getRepository(ApiKey::class);
        $loaded = $repo->findByKey('k2');
        $this->assertNotNull($loaded);
        $this->assertSame(['notifier'], $loaded->scopes);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Config/ApiKeyEntityTest.php`
Expected: FAIL — `Class "lolbot\entities\ApiKey" not found`

- [ ] **Step 3: Create the entity**

Create `entities/ApiKey.php` (house style per `entities/Ignore.php`):

```php
<?php
namespace lolbot\entities;

use Doctrine\ORM\Mapping as ORM;
use lolbot\entities\ApiKeyRepository;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 */
#[ORM\Entity(repositoryClass: ApiKeyRepository::class)]
#[ORM\Table("api_keys")]
#[ORM\UniqueConstraint(name: "api_keys_key_uniq", columns: ["key"])]
class ApiKey
{
    //Known grantable scopes. 'aidesc' gates POST /aidesc; notifier migrates onto these keys later.
    public const SCOPES = ['aidesc'];

    //Cant be readonly due to doctrine bug on remove
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(updatable: false)]
    public int $id;

    #[ORM\Column(length: 64)]
    public string $key;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $label = null;

    /** @var list<string> */
    #[ORM\Column(type: "json")]
    public array $scopes = [];

    #[ORM\Column(updatable: false)]
    public \DateTimeImmutable $created;

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function __construct()
    {
        $this->created = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return "id: $this->id key: $this->key label: $this->label scopes: " . implode(',', $this->scopes)
            . " created: " . $this->created->format('r');
    }
}
```

- [ ] **Step 4: Create the repository**

Create `entities/ApiKeyRepository.php`:

```php
<?php
namespace lolbot\entities;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;

/**
 * @extends EntityRepository<ApiKey>
 */
class ApiKeyRepository extends EntityRepository
{
    public function findByKey(string $key): ?ApiKey
    {
        // Repository finds hydrate from the identity map when the entity is
        // already managed, so a long-lived bot EntityManager would keep serving
        // its first read. Mutations arrive from other processes (admin-cli, web
        // panel) and deletion is a primary flow for keys, so query with
        // HINT_REFRESH: updated rows re-hydrate and a deleted row simply
        // returns null (EntityManager::refresh() would throw instead).
        $rows = $this->getEntityManager()->createQuery(
            'SELECT a FROM lolbot\entities\ApiKey a WHERE a.key = :key'
        )
            ->setParameter('key', $key)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
        return $rows[0] ?? null;
    }
}
```

- [ ] **Step 5: Create the migration**

Create `Migrations/Version20260918120000.php` (createTable pattern per `Migrations/Version20260619120000.php`):

```php
<?php

declare(strict_types=1);

namespace lolbot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260918120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add api_keys table (scoped API keys for bot REST endpoints)';
    }

    public function up(Schema $schema): void
    {
        $t = $schema->createTable("api_keys");
        $t->addColumn("id", Types::INTEGER)->setNotnull(true)->setAutoincrement(true);
        $t->setPrimaryKey(["id"]);
        $t->addColumn("key", Types::STRING)->setLength(64)->setNotnull(true);
        $t->addUniqueIndex(["key"]);
        $t->addColumn("label", Types::STRING)->setLength(64)->setNotnull(false);
        $t->addColumn("scopes", Types::JSON)->setNotnull(true);
        $t->addColumn("created", Types::DATETIME_IMMUTABLE)->setNotnull(true);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable("api_keys");
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Config/ApiKeyEntityTest.php`
Expected: PASS (5 tests)

- [ ] **Step 7: Commit**

```bash
git add entities/ApiKey.php entities/ApiKeyRepository.php Migrations/Version20260918120000.php tests/Config/ApiKeyEntityTest.php
git commit -m "feat(entities): add scoped ApiKey entity with fresh-read repository lookup"
```

---

### Task 2: ConfigService API-key CRUD + BotManager live-apply no-op

**Files:**
- Modify: `library/config/ConfigService.php` (after the Ignores section ending at line 245, add an API keys section; also add `use lolbot\entities\ApiKey;` after the `use lolbot\entities\AiServiceConfig;` line 6)
- Modify: `library/BotManager.php:570-574` (add a `case 'api_key':` next to the other per-request no-op cases)
- Test: `tests/Config/ConfigServiceApiKeyTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Config/ConfigServiceApiKeyTest.php` (CapturingNotifier pattern from `tests/Config/ConfigServiceNotifierTest.php`):

```php
<?php
namespace Tests\Config;

use lolbot\config\ConfigService;
use lolbot\config\DuplicateNameException;
use lolbot\config\InvalidSettingException;
use lolbot\config\NoopChangeNotifier;
use lolbot\entities\ApiKey;

require_once __DIR__ . '/../../vendor/autoload.php';

class CapturingKeyNotifier extends NoopChangeNotifier
{
    /** @var list<\lolbot\config\ConfigChange> */
    public array $changes = [];

    public function notify(\lolbot\config\ConfigChange $change): void
    {
        $this->changes[] = $change;
    }
}

class ConfigServiceApiKeyTest extends ConfigTestCase
{
    private ConfigService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ConfigService($this->em);
    }

    public function test_add_api_key(): void
    {
        $key = $this->svc->addApiKey('sitekey', 'image upload site', ['aidesc']);
        $this->assertSame('sitekey', $key->key);
        $this->assertSame('image upload site', $key->label);
        $this->assertSame(['aidesc'], $key->scopes);
        $this->assertTrue($key->hasScope('aidesc'));
    }

    public function test_add_duplicate_key_throws(): void
    {
        $this->svc->addApiKey('sitekey', null, ['aidesc']);
        $this->expectException(DuplicateNameException::class);
        $this->svc->addApiKey('sitekey', null, ['aidesc']);
    }

    public function test_add_unknown_scope_throws(): void
    {
        $this->expectException(InvalidSettingException::class);
        $this->svc->addApiKey('sitekey', null, ['nope']);
    }

    public function test_add_empty_key_throws(): void
    {
        $this->expectException(InvalidSettingException::class);
        $this->svc->addApiKey('  ', null, ['aidesc']);
    }

    public function test_list_get_delete(): void
    {
        $key = $this->svc->addApiKey('sitekey', null, ['aidesc']);
        $this->assertCount(1, $this->svc->listApiKeys());
        $this->assertSame($key->id, $this->svc->getApiKey($key->id)->id);

        $this->svc->deleteApiKey($key);
        $this->assertSame([], $this->svc->listApiKeys());
        $this->assertNull($this->em->getRepository(ApiKey::class)->find($key->id));
    }

    public function test_create_and_delete_notify(): void
    {
        $notifier = new CapturingKeyNotifier();
        $svc = new ConfigService($this->em, $notifier);
        $key = $svc->addApiKey('sitekey', null, ['aidesc']);
        $svc->deleteApiKey($key);

        $this->assertCount(2, $notifier->changes);
        $this->assertSame('api_key', $notifier->changes[0]->entityType);
        $this->assertSame($key->id, $notifier->changes[0]->id);
        $this->assertSame('create', $notifier->changes[0]->action);
        $this->assertSame('api_key', $notifier->changes[1]->entityType);
        $this->assertSame('delete', $notifier->changes[1]->action);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Config/ConfigServiceApiKeyTest.php`
Expected: FAIL — `Error: Call to undefined method ...::addApiKey()`

- [ ] **Step 3: Implement ConfigService methods**

In `library/config/ConfigService.php`, add to the imports (line 6-12 block):

```php
use lolbot\entities\ApiKey;
```

Add after the `deleteIgnore()` method (after line 245):

```php
    // ---------------- API keys ----------------

    /**
     * @param list<string> $scopes
     */
    public function addApiKey(string $key, ?string $label, array $scopes): ApiKey
    {
        $key = trim($key);
        if ($key === '') {
            throw new InvalidSettingException("Key required");
        }
        foreach ($scopes as $scope) {
            if (!in_array($scope, ApiKey::SCOPES, true)) {
                throw new InvalidSettingException("Unknown scope '$scope' (known: " . implode(', ', ApiKey::SCOPES) . ")");
            }
        }
        if ($this->em->getRepository(ApiKey::class)->findOneBy(['key' => $key]) !== null) {
            throw new DuplicateNameException("Key already exists");
        }
        $apiKey = new ApiKey();
        $apiKey->key = $key;
        if ($label !== null) {
            $apiKey->label = $label;
        }
        $apiKey->scopes = array_values($scopes);
        $this->em->persist($apiKey);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('api_key', $apiKey->id, 'create'));
        return $apiKey;
    }

    public function getApiKey(int $id): ?ApiKey
    {
        return $this->em->getRepository(ApiKey::class)->find($id);
    }

    /** @return list<ApiKey> */
    public function listApiKeys(): array
    {
        return $this->em->getRepository(ApiKey::class)->findAll();
    }

    public function deleteApiKey(ApiKey $apiKey): void
    {
        $id = $apiKey->id;
        $this->em->remove($apiKey);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('api_key', $id, 'delete'));
    }
```

- [ ] **Step 4: Add the BotManager apply no-op case**

In `library/BotManager.php` in the `apply()` switch, extend the existing no-op block at lines 570-574 to:

```php
                case 'ignore':
                    return; // ignore cache is 5s TTL; auto-applies.
                case 'api_key':
                    return; // checked per-request against the DB; adds/deletes apply live.
                case 'linktitles_ignore':
                case 'linktitles_hostignore':
                    return; // checked per-URL against the DB; adds/deletes apply live.
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Config/ConfigServiceApiKeyTest.php tests/Config/BotManagerApplyTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add library/config/ConfigService.php library/BotManager.php tests/Config/ConfigServiceApiKeyTest.php
git commit -m "feat(config): api key CRUD in ConfigService + live-apply no-op"
```

---

### Task 3: CLI commands `apikey:add/list/del` + showdb + registration

**Files:**
- Create: `cli_cmds/apikey_add.php`
- Create: `cli_cmds/apikey_list.php`
- Create: `cli_cmds/apikey_del.php`
- Modify: `cli_cmds/showdb.php` (append an api keys block at the end of `showdb()`, line 63)
- Modify: `admin-cli.php` (register after the ignore commands, line 35)
- Test: `tests/Config/ApiKeyCommandTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Config/ApiKeyCommandTest.php` (CommandTester pattern per `tests/Config/NetworkSetCommandTest.php:12-18`):

```php
<?php
namespace Tests\Config;

use lolbot\config\ConfigService;
use lolbot\entities\ApiKey;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__ . '/../../vendor/autoload.php';

class ApiKeyCommandTest extends ConfigTestCase
{
    private function bootGlobals(): void
    {
        $GLOBALS['entityManager'] = $this->em;
        $GLOBALS['config'] ??= [];
    }

    public function test_add_lists_and_deletes(): void
    {
        $this->bootGlobals();

        $add = new CommandTester(new \lolbot\cli_cmds\apikey_add());
        $add->execute(['key' => 'sitekey', '--label' => 'image upload site', '--scope' => ['aidesc']]);

        $svc = new ConfigService($this->em);
        $keys = $svc->listApiKeys();
        $this->assertCount(1, $keys);
        $this->assertSame('sitekey', $keys[0]->key);
        $this->assertSame('image upload site', $keys[0]->label);
        $this->assertSame(['aidesc'], $keys[0]->scopes);

        $list = new CommandTester(new \lolbot\cli_cmds\apikey_list());
        $list->execute([]);
        $this->assertStringContainsString('sitekey', $list->getDisplay());
        $this->assertStringContainsString('aidesc', $list->getDisplay());

        $del = new CommandTester(new \lolbot\cli_cmds\apikey_del());
        $del->execute(['id' => (string)$keys[0]->id]);
        $this->assertSame([], $svc->listApiKeys());
    }

    public function test_add_requires_a_scope(): void
    {
        $this->bootGlobals();
        $add = new CommandTester(new \lolbot\cli_cmds\apikey_add());
        $this->expectException(\InvalidArgumentException::class);
        $add->execute(['key' => 'sitekey']);
    }

    public function test_add_rejects_unknown_scope(): void
    {
        $this->bootGlobals();
        $add = new CommandTester(new \lolbot\cli_cmds\apikey_add());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nope');
        $add->execute(['key' => 'sitekey', '--scope' => ['nope']]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Config/ApiKeyCommandTest.php`
Expected: FAIL — `Class "lolbot\cli_cmds\apikey_add" not found`

- [ ] **Step 3: Create the commands**

Create `cli_cmds/apikey_add.php`:

```php
<?php
namespace lolbot\cli_cmds;
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use lolbot\entities\ApiKey;

#[AsCommand("apikey:add")]
class apikey_add extends Command
{

    protected function configure(): void
    {
        $this->addArgument("key", InputArgument::REQUIRED)
            ->addOption('label', "l", InputOption::VALUE_REQUIRED, 'Label for this key')
            ->addOption('scope', "s", InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Scope to grant (' . implode('|', ApiKey::SCOPES) . ')')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager, \lolbot\config\build_change_notifier());

        $key = $input->getArgument('key');
        if (!is_string($key)) {
            throw new \LogicException("'key' argument must be a string");
        }
        $label = $input->getOption('label');
        $scopes = $input->getOption('scope');
        if (!is_array($scopes) || count($scopes) == 0) {
            throw new \InvalidArgumentException("Must specify at least one --scope (" . implode('|', ApiKey::SCOPES) . ")");
        }
        $svc->addApiKey($key, is_string($label) ? $label : null, array_map('strval', $scopes));

        showdb::showdb();
        return Command::SUCCESS;
    }
}
```

Create `cli_cmds/apikey_list.php`:

```php
<?php
namespace lolbot\cli_cmds;
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand("apikey:list")]
class apikey_list extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager);
        foreach ($svc->listApiKeys() as $apiKey) {
            $output->writeln((string)$apiKey);
        }
        return Command::SUCCESS;
    }
}
```

Create `cli_cmds/apikey_del.php`:

```php
<?php
namespace lolbot\cli_cmds;
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand("apikey:del")]
class apikey_del extends Command
{
    protected function configure(): void
    {
        $this->addArgument("id", InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager, \lolbot\config\build_change_notifier());

        $id = $input->getArgument('id');
        $apiKey = $svc->getApiKey(is_string($id) ? (int)$id : 0);
        if ($apiKey === null) {
            throw new \InvalidArgumentException("Couldn't find that API key ID ($id)");
        }
        $svc->deleteApiKey($apiKey);

        showdb::showdb();
        return Command::SUCCESS;
    }
}
```

- [ ] **Step 4: Register commands + extend showdb**

In `admin-cli.php`, after line 35 (`$application->add(new cli_cmds\ignore_test());`), add:

```php
$application->add(new cli_cmds\apikey_add());
$application->add(new cli_cmds\apikey_del());
$application->add(new cli_cmds\apikey_list());
```

In `cli_cmds/showdb.php`, add `use lolbot\entities\ApiKey;` to the imports (after `use lolbot\entities\Bot;` line 14) and append inside `showdb()` after the ignores block (before the closing brace, line 63):

```php
        echo "\napi keys:\n";
        $keys = $entityManager->getRepository(ApiKey::class)->findAll();
        foreach ($keys as $apiKey) {
            echo "  " . $apiKey . "\n";
        }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Config/ApiKeyCommandTest.php`
Expected: PASS (3 tests)

- [ ] **Step 6: Commit**

```bash
git add cli_cmds/apikey_add.php cli_cmds/apikey_list.php cli_cmds/apikey_del.php cli_cmds/showdb.php admin-cli.php tests/Config/ApiKeyCommandTest.php
git commit -m "feat(cli): apikey:add/list/del commands + showdb output"
```

---

### Task 4: SettingsResolver::resolveGlobalLinktitles()

`resolveLinktitles()` requires a `Network`; the `/aidesc` endpoint has no network scope, so add a global-only resolve by extracting the pick cascade into a shared private method.

**Files:**
- Modify: `library/config/SettingsResolver.php:71-119`
- Test: `tests/Config/SettingsResolverGlobalTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Config/SettingsResolverGlobalTest.php`:

```php
<?php
namespace Tests\Config;

use lolbot\config\ConfigService;
use lolbot\config\SettingsResolver;
use scripts\linktitles\entities\linktitles_setting;

require_once __DIR__ . '/../../vendor/autoload.php';

class SettingsResolverGlobalTest extends ConfigTestCase
{
    public function test_global_row_is_honored(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');

        // Global scope row (no network).
        $global = new linktitles_setting();
        $global->ai_vision_model = 'gpt-global';
        $global->ai_vision_prompt = 'global prompt';
        $this->em->persist($global);

        // Network scope row must be ignored by the global resolve.
        $networkRow = new linktitles_setting();
        $networkRow->network = $net;
        $networkRow->ai_vision_model = 'gpt-network';
        $this->em->persist($networkRow);
        $this->em->flush();

        $resolved = (new SettingsResolver($this->em))->resolveGlobalLinktitles();
        $this->assertSame('gpt-global', $resolved->aiVisionModel);
        $this->assertSame('global prompt', $resolved->aiVisionPrompt);
    }

    public function test_defaults_when_no_rows(): void
    {
        $resolved = (new SettingsResolver($this->em))->resolveGlobalLinktitles();
        $this->assertSame(\lolbot\config\LinktitlesDefaults::MODEL, $resolved->aiVisionModel);
        $this->assertSame(\lolbot\config\LinktitlesDefaults::PROMPT, $resolved->aiVisionPrompt);
    }
}
```

Property names verified against `scripts/linktitles/entities/linktitles_setting.php`: `?Network $network`, `?string $ai_vision_model`, `?string $ai_vision_prompt` (all nullable, defaults null) — the assignments above are accurate.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Config/SettingsResolverGlobalTest.php`
Expected: FAIL — `Error: Call to undefined method ...::resolveGlobalLinktitles()`

- [ ] **Step 3: Refactor the resolver**

In `library/config/SettingsResolver.php`, replace `resolveLinktitles()` (lines 71-119) with:

```php
    public function resolveLinktitles(Network $network, ?Channel $channel): LinktitlesResolved
    {
        [$channelRow, $networkRow, $globalRow] = $this->linktitlesTiers($network, $channel);
        return $this->resolveFromTiers($channelRow, $networkRow, $globalRow);
    }

    /**
     * Resolve with only the global tier (no network/channel overrides) — for
     * bot-wide consumers like the POST /aidesc REST endpoint.
     */
    public function resolveGlobalLinktitles(): LinktitlesResolved
    {
        return $this->resolveFromTiers(null, null, $this->globalLinktitlesSetting());
    }

    private function resolveFromTiers(
        ?linktitles_setting $channelRow,
        ?linktitles_setting $networkRow,
        ?linktitles_setting $globalRow,
    ): LinktitlesResolved {
        $sources = [];

        [$enabled, $sources['enabled']] = $this->pick(
            $channelRow, $networkRow, $globalRow,
            static fn(linktitles_setting $s) => $s->enabled,
            LinktitlesDefaults::ENABLED,
        );
        [$aiVisionDisabled, $sources['ai_vision_disabled']] = $this->pick(
            $channelRow, $networkRow, $globalRow,
            static fn(linktitles_setting $s) => $s->ai_vision_disabled,
            LinktitlesDefaults::AI_VISION_DISABLED,
        );
        [$aiVisionModel, $sources['ai_vision_model']] = $this->pick(
            $channelRow, $networkRow, $globalRow,
            static fn(linktitles_setting $s) => $s->ai_vision_model,
            LinktitlesDefaults::MODEL,
        );
        [$aiVisionPrompt, $sources['ai_vision_prompt']] = $this->pick(
            $channelRow, $networkRow, $globalRow,
            static fn(linktitles_setting $s) => $s->ai_vision_prompt,
            LinktitlesDefaults::PROMPT,
        );
        [$urlLogChan, $sources['url_log_chan']] = $this->pickNullable(
            $channelRow, $networkRow, $globalRow,
            static fn(linktitles_setting $s) => $s->url_log_chan,
        );
        [$aiVisionReasoningEffort, $sources['ai_vision_reasoning_effort']] = $this->pickNullable(
            $channelRow, $networkRow, $globalRow,
            static fn(linktitles_setting $s) => $s->ai_vision_reasoning_effort,
        );
        [$aiVisionReasoning, $sources['ai_vision_reasoning']] = $this->pickNullable(
            $channelRow, $networkRow, $globalRow,
            static fn(linktitles_setting $s) => $s->ai_vision_reasoning,
        );

        return new LinktitlesResolved(
            enabled: $enabled,
            urlLogChan: $urlLogChan,
            aiVisionModel: $aiVisionModel,
            aiVisionPrompt: $aiVisionPrompt,
            aiVisionReasoningEffort: $aiVisionReasoningEffort,
            aiVisionReasoning: $aiVisionReasoning,
            aiVisionDisabled: $aiVisionDisabled,
            sources: $sources,
        );
    }
```

The bodies of `pick`, `pickNullable`, `linktitlesTiers`, and `globalLinktitlesSetting()` are unchanged (`globalLinktitlesSetting()` is the existing private no-arg method at line 33).

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Config/SettingsResolverGlobalTest.php tests/Config/LinktitlesCascadeTest.php tests/Config/SettingsResolverTest.php`
Expected: PASS (new + existing cascade tests unchanged)

- [ ] **Step 5: Commit**

```bash
git add library/config/SettingsResolver.php tests/Config/SettingsResolverGlobalTest.php
git commit -m "feat(config): global-only linktitles resolve for scopeless consumers"
```

---

### Task 5: ImageDescriber + DescribeResult extraction from linktitles

Extract the vision pipeline from `linktitles::getAiDescription()` (lines 228-349) into a standalone class so the bot-scoped linktitles instances (whose `script_base` constructor needs per-bot objects) and the bot-wide `/aidesc` endpoint share one implementation. The IRC wrapper keeps its URL cache, 200-char truncation, and profile tags so `tests/Linktitles/FormatImageResponseTest.php` passes unchanged.

**Files:**
- Create: `scripts/linktitles/DescribeResult.php`
- Create: `scripts/linktitles/ImageDescriber.php`
- Modify: `scripts/linktitles/linktitles.php` (rewrite `getAiDescription()` lines 228-349 as a wrapper; change `formatDuration` from `private static` to `public static` at line 486; replace the two `self::$ai_desc_cache` references — declaration line 80 and uses at lines 341, 372 — with `ImageDescriber::$descCache`)
- Test: `tests/Linktitles/ImageDescriberTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Linktitles/ImageDescriberTest.php`:

```php
<?php

namespace Tests\Linktitles;

use lolbot\config\LinktitlesDefaults;
use lolbot\config\LinktitlesResolved;
use lolbot\entities\AiServiceConfig;
use PHPUnit\Framework\TestCase;
use scripts\linktitles\DescribeResult;
use scripts\linktitles\ImageDescriber;

require_once __DIR__ . '/../../vendor/autoload.php';

class ImageDescriberTest extends TestCase
{
    private function settings(): LinktitlesResolved
    {
        return new LinktitlesResolved(
            enabled: true,
            urlLogChan: null,
            aiVisionModel: LinktitlesDefaults::MODEL,
            aiVisionPrompt: LinktitlesDefaults::PROMPT,
            aiVisionReasoningEffort: null,
            aiVisionReasoning: null,
            aiVisionDisabled: false,
            sources: [],
        );
    }

    private function describer(): ImageDescriber
    {
        return new ImageDescriber($this->createStub(\Psr\Log\LoggerInterface::class));
    }

    public function test_ping_guard_skips_bomb_without_decoding(): void
    {
        $img = file_get_contents(__DIR__ . '/../fixtures/bomb_header_60000x60000.png');
        assert(is_string($img));
        $ai = new AiServiceConfig();
        $ai->apiKey = 'test-key';
        $result = $this->describer()->describe($img, $ai, $this->settings());
        $this->assertNull($result->description);
        $this->assertSame(DescribeResult::TOO_LARGE, $result->error);
        $this->assertSame('60000x60000x1f', $result->errorDetail);
    }

    public function test_ping_guard_skips_animated_bomb(): void
    {
        $img = file_get_contents(__DIR__ . '/../fixtures/animated_bomb_10f_1600x1600.gif');
        assert(is_string($img));
        $ai = new AiServiceConfig();
        $ai->apiKey = 'test-key';
        $result = $this->describer()->describe($img, $ai, $this->settings());
        $this->assertNull($result->description);
        $this->assertSame(DescribeResult::TOO_LARGE, $result->error);
        $this->assertSame('1600x1600x10f', $result->errorDetail);
    }

    public function test_garbage_bytes_are_undecodable(): void
    {
        $ai = new AiServiceConfig();
        $ai->apiKey = 'test-key';
        $result = $this->describer()->describe('not an image at all', $ai, $this->settings());
        $this->assertNull($result->description);
        $this->assertSame(DescribeResult::UNDECODABLE, $result->error);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Linktitles/ImageDescriberTest.php`
Expected: FAIL — `Class "scripts\linktitles\DescribeResult" not found`

- [ ] **Step 3: Create DescribeResult**

Create `scripts/linktitles/DescribeResult.php`:

```php
<?php

namespace scripts\linktitles;

/**
 * Outcome of an ImageDescriber run: either a raw (untruncated) description
 * or a typed failure, plus whatever profile/timing fragments were gathered
 * before the failure so callers can keep their existing profile strings.
 */
final class DescribeResult
{
    public const TOO_LARGE = 'too_large';
    public const UNDECODABLE = 'undecodable';
    public const EMPTY = 'empty';
    public const TIMEOUT = 'timeout';
    public const UPSTREAM = 'upstream_error';

    public function __construct(
        public readonly ?string $description,
        public readonly ?string $error,
        public readonly string $errorDetail = '',
        public readonly string $profile = '',
        public readonly float $workMs = 0.0,
    ) {}

    public static function success(string $description, string $profile, float $workMs): self
    {
        return new self($description, null, '', $profile, $workMs);
    }
}
```

- [ ] **Step 4: Create ImageDescriber**

Create `scripts/linktitles/ImageDescriber.php` — the pipeline moved verbatim from `getAiDescription()` with error classification added; all original comments carried over:

```php
<?php

namespace scripts\linktitles;

use Amp\Http\Client\HttpClientBuilder;
use Amp\TimeoutCancellation;
use Knivey\OpenAi\HttpClient as OpenAiHttpClient;
use Knivey\OpenAi\OpenAiClient;
use Knivey\OpenAi\Request\ChatRequest;
use Knivey\OpenAi\Request\Message;
use Knivey\OpenAi\Request\Reasoning;
use Knivey\OpenAi\Request\Content\TextPart;
use Knivey\OpenAi\Request\Content\ImagePart;
use lolbot\config\LinktitlesResolved;
use lolbot\entities\AiServiceConfig;

/**
 * Shared AI image-description pipeline used by the in-channel linktitles
 * flow and the POST /aidesc REST endpoint: bomb-guard, resize/JPEG
 * re-encode, OpenAI-compatible vision call, control-char strip. Callers map
 * the typed DescribeResult to their own surfaces (profile tags / HTTP).
 */
class ImageDescriber
{
    /**
     * Shared in-process description cache, keyed by caller-chosen keys
     * (URL for the IRC path, sha256:... for the /aidesc endpoint). Static so
     * all bot instances and the endpoint share entries, like the previous
     * static on the linktitles class.
     * @var array<string, string>
     */
    public static array $descCache = [];

    public function __construct(private \Psr\Log\LoggerInterface $logger) {}

    public function describe(string $body, AiServiceConfig $ai, LinktitlesResolved $settings): DescribeResult
    {
        $profile = '';
        try {
            $maxDim = $ai->maxDim;
            $quality = $ai->jpgQuality;

            $resizeStart = hrtime(true);
            $img = new \Imagick();
            try {
                //pingImageBlob reads headers only, catches decompression bombs in formats
                //getimagesizefromstring can't parse before the full decode happens
                $ping = new \Imagick();
                try {
                    $ping->pingImageBlob($body);
                    $pingW = $ping->getImageWidth();
                    $pingH = $ping->getImageHeight();
                    //multi-frame images decode every frame, so frame count multiplies the cost
                    $pingFrames = max(1, $ping->getNumberImages());
                } finally {
                    $ping->clear();
                }
                if ($pingW * $pingH * $pingFrames > linktitles::maxAiPixels) {
                    return new DescribeResult(null, DescribeResult::TOO_LARGE, "{$pingW}x{$pingH}x{$pingFrames}f", $profile);
                }
                $img->readImageBlob($body);
                $origW = $img->getImageWidth();
                $origH = $img->getImageHeight();
                if ($origW * $origH * max(1, $img->getNumberImages()) > linktitles::maxAiPixels) {
                    return new DescribeResult(null, DescribeResult::TOO_LARGE, "{$origW}x{$origH}", $profile);
                }
                if ($origW > $maxDim || $origH > $maxDim) {
                    $img->thumbnailImage($maxDim, $maxDim, true);
                }
                $img->setImageFormat('jpeg');
                $img->setImageCompressionQuality($quality);
                $newW = $img->getImageWidth();
                $newH = $img->getImageHeight();
                $base64 = base64_encode($img->getImageBlob());
            } catch (\Exception $e) {
                return new DescribeResult(null, DescribeResult::UNDECODABLE, $e->getMessage(), $profile);
            } finally {
                $img->clear();
            }
            $resizeMs = (hrtime(true) - $resizeStart) / 1e6;
            $profile .= " resize=" . linktitles::formatDuration($resizeMs) . " {$origW}x{$origH}->{$newW}x{$newH} " . \knivey\tools\convert(strlen($body)) . "->" . \knivey\tools\convert((int)(strlen($base64) * 3 / 4));

            try {
                $aiStart = hrtime(true);
                $ampClient = HttpClientBuilder::buildDefault();
                $timeout = $ai->timeout;
                $openAiHttp = new OpenAiHttpClient($ai->apiKey ?? '', $ampClient, new TimeoutCancellation($timeout));
                $aiClient = new OpenAiClient(
                    apiKey: $ai->apiKey ?? '',
                    baseUrl: $ai->baseUrl ?? 'https://api.openai.com/v1',
                    httpClient: $openAiHttp,
                );

                $prompt = $settings->aiVisionPrompt;
                $model = $settings->aiVisionModel;
                $reasoningConfig = $settings->aiVisionReasoning;
                $reasoningEffort = $settings->aiVisionReasoningEffort;

                $reasoning = null;
                if ($reasoningConfig !== null) {
                    $effortVal = $reasoningConfig['effort'] ?? null;
                    $maxTokensVal = $reasoningConfig['max_tokens'] ?? null;
                    $reasoning = new Reasoning(
                        effort: is_string($effortVal) ? $effortVal : null,
                        maxTokens: is_int($maxTokensVal) ? $maxTokensVal : null,
                        exclude: isset($reasoningConfig['exclude']) ? (bool)$reasoningConfig['exclude'] : null,
                        enabled: isset($reasoningConfig['enabled']) ? (bool)$reasoningConfig['enabled'] : null,
                    );
                } elseif ($reasoningEffort !== null) {
                    $reasoning = Reasoning::effort($reasoningEffort);
                }

                $response = $aiClient->chatCompletion(new ChatRequest(
                    model: $model,
                    messages: [
                        Message::system($prompt),
                        Message::user([
                            new TextPart('describe this image'),
                            ImagePart::base64($base64, 'image/jpeg'),
                        ]),
                    ],
                    reasoning: $reasoning,
                ));
                $aiMs = (hrtime(true) - $aiStart) / 1e6;
                $profile .= " ai($model)=" . linktitles::formatDuration($aiMs);

                $description = $response->choices[0]->message->content ?? null;
                if ($description === null || trim($description) === '') {
                    return new DescribeResult(null, DescribeResult::EMPTY, '', $profile, $resizeMs + $aiMs);
                }
                $description = trim($description);
                $description = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F]/', '', $description);
                if ($description === null) {
                    return new DescribeResult(null, DescribeResult::EMPTY, '', $profile, $resizeMs + $aiMs);
                }
                return DescribeResult::success($description, $profile, $resizeMs + $aiMs);
            } catch (\Amp\TimeoutException $e) {
                return new DescribeResult(null, DescribeResult::TIMEOUT, $e->getMessage(), $profile);
            } catch (\Exception $e) {
                return new DescribeResult(null, DescribeResult::UPSTREAM, $e->getMessage(), $profile);
            }
        } catch (\Exception $e) {
            // Beyond the classified zones above (should be unreachable).
            return new DescribeResult(null, DescribeResult::UPSTREAM, $e->getMessage(), $profile);
        }
    }
}
```

Note: `linktitles::formatDuration` must be made `public static` in Step 5 (the logger constructor property is kept for future endpoint logging; the IRC wrapper keeps its own logging). The `$img` used in the inner `finally` is always an \Imagick instance because the `catch` sits between the constructor and `finally`; if PHP static analysis complains about a possibly-undefined `$img` in `finally`, restructure by constructing `$img = new \Imagick();` before the try — keep behavior identical.

- [ ] **Step 5: Rewrite linktitles::getAiDescription as a wrapper**

In `scripts/linktitles/linktitles.php`:

1. Delete the `private static array $ai_desc_cache = [];` property (lines 77-80 keep their docblock removal minimal — remove the docblock with it) and add no replacement (the cache now lives on `ImageDescriber::$descCache`).
2. Replace the entire `getAiDescription()` body (lines 228-349) with:

```php
    private function getAiDescription(string $body, string $url, string $chan, string &$profile = '', float $dlMs = 0.0): ?string
    {
        global $entityManager;

        $ai = (new ServiceLocator($entityManager))->getServiceConfig('ai');
        if (!$ai instanceof AiServiceConfig || $ai->apiKey === null || $ai->apiKey === '') {
            return null;
        }

        $resolved = (new SettingsResolver($entityManager))->resolveLinktitles(
            $this->network,
            $this->channelEntityForChan($chan),
        );

        $result = (new ImageDescriber($this->logger))->describe($body, $ai, $resolved);
        $profile .= $result->profile;
        if ($result->error !== null) {
            if ($result->error === DescribeResult::TOO_LARGE) {
                $profile .= " ai_skipped=image_too_large {$result->errorDetail}";
                $this->logger->info("AI vision skipped oversize image {$result->errorDetail} for {$url}");
            } elseif ($result->error === DescribeResult::EMPTY) {
                $profile .= " total=" . self::formatDuration($dlMs + $result->workMs);
            } else {
                $profile .= " ai_error={$result->errorDetail}";
                $this->logger->warning("AI vision description failed: " . $result->errorDetail);
            }
            return null;
        }
        $description = $result->description ?? '';
        if (mb_strwidth($description) > 200) {
            $description = mb_strimwidth($description, 0, 197, '...');
        }
        ImageDescriber::$descCache[$url] = $description;
        $profile .= " total=" . self::formatDuration($dlMs + $result->workMs);
        return $description;
    }
```

3. In `formatImageResponse()` (line 372), replace `self::$ai_desc_cache[$cacheKey] ??` with `ImageDescriber::$descCache[$cacheKey] ??`.
4. Change `private static function formatDuration(float $ms): string` (line 486) to `public static function formatDuration(float $ms): string`.

Behavior preserved for the IRC path: oversize → `ai_skipped` tag and info log; empty model output → `total` tag only; any other failure → `ai_error` tag and warning log; success → truncation, cache, `total`.

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Linktitles/`
Expected: PASS — `ImageDescriberTest` (3 tests) AND the existing `FormatImageResponseTest` (8 tests, including both `getAiDescription` ping-guard profile assertions `60000x60000x1f` / `1600x1600x10f`) unchanged.

- [ ] **Step 7: Commit**

```bash
git add scripts/linktitles/DescribeResult.php scripts/linktitles/ImageDescriber.php scripts/linktitles/linktitles.php tests/Linktitles/ImageDescriberTest.php
git commit -m "refactor(linktitles): extract shared ImageDescriber vision pipeline"
```

---

### Task 6: POST /aidesc endpoint

**Files:**
- Create: `scripts/aidesc/aidesc.php`
- Modify: `lolbot.php` (require near line 54, register near line 237)
- Test: `tests/Linktitles/AidescMapTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Linktitles/AidescMapTest.php`:

```php
<?php

namespace Tests\Linktitles;

use Amp\Http\HttpStatus;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../scripts/linktitles/DescribeResult.php';
require_once __DIR__ . '/../../scripts/aidesc/aidesc.php';

class AidescMapTest extends TestCase
{
    public function test_success_maps_200_with_description(): void
    {
        [$status, $body] = \scripts\aidesc\aidesc_map_result(
            \scripts\linktitles\DescribeResult::success('a red rectangle', ' profile', 1.0)
        );
        $this->assertSame(HttpStatus::OK, $status);
        $this->assertSame('a red rectangle', $body);
    }

    public function test_too_large_maps_400(): void
    {
        [$status, $body] = \scripts\aidesc\aidesc_map_result(
            new \scripts\linktitles\DescribeResult(null, \scripts\linktitles\DescribeResult::TOO_LARGE, '60000x60000x1f')
        );
        $this->assertSame(HttpStatus::BAD_REQUEST, $status);
        $this->assertStringContainsString('too large', $body);
    }

    public function test_undecodable_maps_400(): void
    {
        [$status, $body] = \scripts\aidesc\aidesc_map_result(
            new \scripts\linktitles\DescribeResult(null, \scripts\linktitles\DescribeResult::UNDECODABLE, 'boom')
        );
        $this->assertSame(HttpStatus::BAD_REQUEST, $status);
        $this->assertStringContainsString('undecodable', $body);
    }

    public function test_empty_maps_502(): void
    {
        [$status] = \scripts\aidesc\aidesc_map_result(
            new \scripts\linktitles\DescribeResult(null, \scripts\linktitles\DescribeResult::EMPTY)
        );
        $this->assertSame(HttpStatus::BAD_GATEWAY, $status);
    }

    public function test_timeout_maps_504(): void
    {
        [$status] = \scripts\aidesc\aidesc_map_result(
            new \scripts\linktitles\DescribeResult(null, \scripts\linktitles\DescribeResult::TIMEOUT, 'timed out')
        );
        $this->assertSame(HttpStatus::GATEWAY_TIMEOUT, $status);
    }

    public function test_upstream_maps_502(): void
    {
        [$status, $body] = \scripts\aidesc\aidesc_map_result(
            new \scripts\linktitles\DescribeResult(null, \scripts\linktitles\DescribeResult::UPSTREAM, 'api down')
        );
        $this->assertSame(HttpStatus::BAD_GATEWAY, $status);
        $this->assertSame('api down', $body);
    }
}
```

Note: `scripts/aidesc/aidesc.php` must not fail at require time — the closure bodies only run per request, so requiring the file in a test is safe (no Amp server booted).

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Linktitles/AidescMapTest.php`
Expected: FAIL — require of `scripts/aidesc/aidesc.php` fails (file does not exist)

- [ ] **Step 3: Create the endpoint module**

Create `scripts/aidesc/aidesc.php` (mirrors `scripts/notifier/notifier.php`; `key` header auth style is identical to notifier's):

```php
<?php
namespace scripts\aidesc;

use Amp\ByteStream\BufferException;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\HttpStatus;
use lolbot\config\ServiceLocator;
use lolbot\config\SettingsResolver;
use lolbot\entities\ApiKey;
use lolbot\entities\AiServiceConfig;
use scripts\linktitles\DescribeResult;
use scripts\linktitles\ImageDescriber;

// Raw-image-body limit for submitted image data; mirrors the 16MB body limit
// linktitles uses when fetching images.
const maxBodyBytes = 1024 * 1024 * 16;

/**
 * Register POST /aidesc on the shared global REST server: callers submit raw
 * image bytes as the request body and receive a plain-text AI description.
 * Auth via api_keys table (key header) with the 'aidesc' scope; settings are
 * the global-scope linktitles AI config + ai_service_config.
 */
function aidesc_register(Router $router, \Psr\Log\LoggerInterface $logger): void {
    $handler = new ClosureRequestHandler(function (Request $request) use ($logger) {
        global $entityManager;

        // Key auth: per-request HINT_REFRESH read so adds/deletes apply live.
        $key = (string)$request->getHeader('key');
        $apiKey = $entityManager->getRepository(ApiKey::class)->findByKey($key);
        if ($key === '' || $apiKey === null || !$apiKey->hasScope('aidesc')) {
            return new Response(HttpStatus::FORBIDDEN, ['content-type' => 'text/plain'], "Invalid key");
        }

        try {
            $body = $request->getBody()->buffer(limit: maxBodyBytes + 1);
        } catch (BufferException $e) {
            return new Response(HttpStatus::PAYLOAD_TOO_LARGE, ['content-type' => 'text/plain'], "Image too large (max 16MB)");
        }
        if (strlen($body) > maxBodyBytes) {
            return new Response(HttpStatus::PAYLOAD_TOO_LARGE, ['content-type' => 'text/plain'], "Image too large (max 16MB)");
        }
        if ($body === '') {
            return new Response(HttpStatus::BAD_REQUEST, ['content-type' => 'text/plain'], "Empty body");
        }

        $ai = (new ServiceLocator($entityManager))->getServiceConfig('ai');
        if (!$ai instanceof AiServiceConfig || $ai->apiKey === null || $ai->apiKey === '') {
            return new Response(HttpStatus::SERVICE_UNAVAILABLE, ['content-type' => 'text/plain'], "AI service not configured");
        }

        // Identical resubmits hit the shared in-process cache, keyed by content.
        $cacheKey = 'sha256:' . hash('sha256', $body);
        if (isset(ImageDescriber::$descCache[$cacheKey])) {
            return new Response(HttpStatus::OK, ['content-type' => 'text/plain'], ImageDescriber::$descCache[$cacheKey]);
        }

        $settings = (new SettingsResolver($entityManager))->resolveGlobalLinktitles();
        $result = (new ImageDescriber($logger))->describe($body, $ai, $settings);
        [$status, $message] = aidesc_map_result($result);
        if ($status === HttpStatus::OK) {
            ImageDescriber::$descCache[$cacheKey] = $result->description ?? '';
        }
        $logger->info("aidesc [{$apiKey->label ?? $apiKey->key}] " . ($result->error ?? 'ok')
            . ($result->error !== null ? " {$result->errorDetail}" : '') . " {$result->profile}");
        return new Response($status, ['content-type' => 'text/plain'], $message);
    });
    $router->addRoute('POST', '/aidesc', $handler);
}

/**
 * Map a DescribeResult to [HttpStatus, plain-text body].
 * @return array{0: int, 1: string}
 */
function aidesc_map_result(DescribeResult $result): array
{
    if ($result->error === null) {
        return [HttpStatus::OK, $result->description ?? ''];
    }
    return match ($result->error) {
        DescribeResult::TOO_LARGE => [HttpStatus::BAD_REQUEST, "image too large ({$result->errorDetail})"],
        DescribeResult::UNDECODABLE => [HttpStatus::BAD_REQUEST, "undecodable image"],
        DescribeResult::EMPTY => [HttpStatus::BAD_GATEWAY, "empty description"],
        DescribeResult::TIMEOUT => [HttpStatus::GATEWAY_TIMEOUT, "AI timeout"],
        default => [HttpStatus::BAD_GATEWAY, $result->errorDetail],
    };
}
```

Note: the endpoint output is plain text over HTTP, never sent to an IRC channel, so the `\x02\x02` anti-bot marker does not apply here; the IRC-facing output paths (`formatImageResponse`) are untouched.

- [ ] **Step 4: Wire into lolbot.php**

In `lolbot.php`, after `require_once 'scripts/notifier/notifier.php';` (line 54), add:

```php
require_once 'scripts/aidesc/aidesc.php';
```

After the notifier registration block (lines 235-237), add:

```php
        // AI image description endpoint (scripts/aidesc).
        if (function_exists('\\scripts\\aidesc\\aidesc_register')) {
            \scripts\aidesc\aidesc_register($router, $logger);
        }
```

(`$logger` is the control logger already in scope at that point — see line 157.)

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Linktitles/`
Expected: PASS (ImageDescriberTest, AidescMapTest, FormatImageResponseTest)

- [ ] **Step 6: Commit**

```bash
git add scripts/aidesc/aidesc.php lolbot.php tests/Linktitles/AidescMapTest.php
git commit -m "feat(aidesc): POST /aidesc raw-image AI description endpoint"
```

---

### Task 7: Web panel API keys section

**Files:**
- Create: `web/sections/apikeys.php`
- Create: `web/templates/apikeys/list.twig`
- Modify: `web/routes.php` (require at line 9-ish, routes after the ignores block at line 123)
- Modify: `web/templates/base.twig` (nav item after the Ignores `<li>` at line 23)
- Test: `tests/Config/WebApiKeysTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Config/WebApiKeysTest.php` (boot pattern from `tests/Config/WebAuthTest.php:9-17`):

```php
<?php
namespace Tests\Config;

use lolbot\config\ConfigService;

require_once __DIR__ . '/../../vendor/autoload.php';

/** Template + section surface test: the CRUD itself is covered by ConfigServiceApiKeyTest. */
class WebApiKeysTest extends ConfigTestCase
{
    private function bootWeb(): void
    {
        $GLOBALS['config'] = ['control_key' => 'sekret'];
        $GLOBALS['entityManager'] = $this->em; // web_app() builds ConfigService from the global EM.
        @session_start();
        $_SESSION = [];
        require_once __DIR__ . '/../../web/app.php';
        require_once __DIR__ . '/../../web/auth.php';
        require_once __DIR__ . '/../../web/sections/apikeys.php';
    }

    public function test_list_template_renders_keys_and_scopes(): void
    {
        $this->bootWeb();
        $svc = new ConfigService($this->em);
        $svc->addApiKey('sitekey', 'image upload site', ['aidesc']);

        $app = web_app();
        $html = $app['twig']->render('apikeys/list.twig', [
            'active' => 'apikeys', 'section' => 'API keys', 'authed' => true,
            'keys' => $svc->listApiKeys(),
            'scopes' => \lolbot\entities\ApiKey::SCOPES,
            'error' => null,
        ]);
        $this->assertStringContainsString('sitekey', $html);
        $this->assertStringContainsString('image upload site', $html);
        $this->assertStringContainsString('aidesc', $html);
        $this->assertStringContainsString('/apikeys', $html);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Config/WebApiKeysTest.php`
Expected: FAIL — require of `web/sections/apikeys.php` fails (file does not exist)

- [ ] **Step 3: Create the section handlers**

Create `web/sections/apikeys.php` (mirrors `web/sections/ignores.php`):

```php
<?php
function web_apikeys_list(?string $error = null): never
{
    $app = web_app();
    web_render('apikeys/list.twig', [
        'active' => 'apikeys', 'section' => 'API keys',
        'keys' => $app['svc']->listApiKeys(),
        'scopes' => \lolbot\entities\ApiKey::SCOPES,
        'error' => $error,
    ]);
}

function web_apikeys_create(): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_apikeys_list($e->getMessage()); }
    $key = trim(is_string($_POST['key'] ?? null) ? $_POST['key'] : '');
    $labelRaw = trim(is_string($_POST['label'] ?? null) ? $_POST['label'] : '');
    $label = $labelRaw !== '' ? $labelRaw : null;
    $scopes = array_values(array_filter($_POST['scopes'] ?? [], 'is_string'));
    if ($key === '') {
        web_apikeys_list('Key required');
    }
    if (!$scopes) {
        web_apikeys_list('Select at least one scope');
    }
    try { $app['svc']->addApiKey($key, $label, $scopes); } catch (\Throwable $e) { web_apikeys_list($e->getMessage()); }
    web_redirect('/apikeys');
}

function web_apikeys_delete(int $id): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_apikeys_list($e->getMessage()); }
    $apiKey = $app['svc']->getApiKey($id);
    if ($apiKey !== null) { $app['svc']->deleteApiKey($apiKey); }
    web_redirect('/apikeys');
}
```

- [ ] **Step 4: Create the template**

Create `web/templates/apikeys/list.twig`:

```twig
{% extends 'base.twig' %}
{% import '_macros.twig' as m %}
{% block content %}
<h1 class="page-heading">API keys</h1>
{% if error %}<div class="alert alert-danger py-2 mb-3">{{ error }}</div>{% endif %}
<div class="table-card mb-3">
  <table class="table table-sm align-middle">
    <thead>
      <tr><th>id</th><th>key</th><th>label</th><th>scopes</th><th>created</th><th></th></tr>
    </thead>
    <tbody>
      {% for k in keys %}
      <tr><td>{{ k.id }}</td><td class="font-monospace">{{ k.key }}</td><td>{{ k.label }}</td>
        <td>{% for s in k.scopes %}<span class="badge text-bg-secondary">{{ s }}</span>{% endfor %}</td>
        <td>{{ k.created.format('r') }}</td>
        <td><form class="d-inline" method="post" action="/apikeys/{{ k.id }}/delete"><input type="hidden" name="_csrf" value="{{ csrf() }}"><button class="btn btn-outline-secondary btn-sm" type="submit">−</button></form></td></tr>
      {% endfor %}
    </tbody>
  </table>
</div>
<div class="card mb-3"><div class="card-body">
  <h3 class="card-title">Add API key</h3>
  <form method="post" action="/apikeys">
    {{ m.csrf_field() }}
    {{ m.field('key', 'key', '', 'text', 'choose a secret value to hand to the caller') }}
    {{ m.field('label', 'label (opt)', '') }}
    <div class="mb-3"><label class="form-label">scopes</label>
      {% for s in scopes %}<div class="form-check"><input class="form-check-input" type="checkbox" name="scopes[]" value="{{ s }}" id="scope_{{ s }}"> <label class="form-check-label" for="scope_{{ s }}">{{ s }}</label></div>{% endfor %}
    </div>
    {{ m.submit('Add') }}
  </form>
</div></div>
<p class="text-body-secondary">Key grants take effect immediately — the endpoints check the DB per request.</p>
{% endblock %}
```

- [ ] **Step 5: Add routes + nav**

In `web/routes.php`, after `require_once __DIR__ . '/sections/linktitles.php';` (line 9), add:

```php
require_once __DIR__ . '/sections/apikeys.php';
```

After the ignores route block (line 123), add:

```php
    if ($method === 'GET' && $path === '/apikeys') {
        web_apikeys_list();
    }
    if ($method === 'POST' && $path === '/apikeys') {
        web_apikeys_create();
    }
    if ($method === 'POST' && preg_match('#^/apikeys/(\d+)/delete$#', $path, $m)) {
        web_apikeys_delete((int)$m[1]);
    }
```

In `web/templates/base.twig`, after the Ignores nav `<li>` (line 23), add:

```twig
        <li class="nav-item"><a class="nav-link {{ active == 'apikeys' ? 'active' : '' }}" href="/apikeys">🗝 API keys</a></li>
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Config/WebApiKeysTest.php tests/Config/WebAuthTest.php`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add web/sections/apikeys.php web/templates/apikeys/list.twig web/routes.php web/templates/base.twig tests/Config/WebApiKeysTest.php
git commit -m "feat(web): API keys admin section"
```

---

### Task 8: Final verification

**Files:** none (verification only)

- [ ] **Step 1: Full test suite**

Run: `composer test`
Expected: PASS, no failures in existing suites (Alias, Artbot, Canvas, Config, Crypto, Duration, ImageGuard, Imgur, Irc, Linktitles, NetworkContext, Quotes, Remindme, Weather).

- [ ] **Step 2: Scoped static analysis**

Run:

```bash
vendor/bin/phpstan analyse entities/ApiKey.php entities/ApiKeyRepository.php library/config/ConfigService.php library/config/SettingsResolver.php library/BotManager.php cli_cmds/apikey_add.php cli_cmds/apikey_list.php cli_cmds/apikey_del.php cli_cmds/showdb.php scripts/linktitles/DescribeResult.php scripts/linktitles/ImageDescriber.php scripts/linktitles/linktitles.php scripts/aidesc/aidesc.php web/sections/apikeys.php --no-progress
```

Expected: no NEW errors in the touched files (the repo has a pre-existing baseline; compare against `git stash` + rerun if unsure).

- [ ] **Step 3: Formatting**

Run: `vendor/bin/php-cs-fixer fix` then `git diff` to review; re-run `composer test` if it changed files.

- [ ] **Step 4: Migration smoke**

Run: `php admin-cli.php migrations:migrate` then `php admin-cli.php apikey:add testsite --label "test site" --scope aidesc` then `php admin-cli.php apikey:list` then `php admin-cli.php apikey:del <id>`
Expected: migration applies `Version20260918120000`; add/list/del round-trip works. Note: requires a local `config.yaml`; if the DB is shared with the live bot, run at a safe time — the bot refuses to start with pending migrations until this runs.

- [ ] **Step 5: Manual endpoint smoke (needs running bot + configured AI service)**

```bash
curl -s -X POST -H "key: testsite" --data-binary @tests/fixtures/100x50_red.jpg http://127.0.0.1:1339/aidesc -w '\n%{http_code}\n'   # 200 + description
curl -s -X POST -H "key: badkey" --data-binary @tests/fixtures/100x50_red.jpg http://127.0.0.1:1339/aidesc -w '\n%{http_code}\n' # 403
curl -s -X POST -H "key: testsite" --data-binary /dev/urandom http://127.0.0.1:1339/aidesc -w '\n%{http_code}\n'                  # 413 (>16MB)
```

Also verify: web panel `/apikeys` add + delete while the bot runs, then an immediate `/aidesc` call with the new key (no restart) and a 403 with the deleted one.

- [ ] **Step 6: Final commit (if cs-fixer touched files)**

```bash
git add -u && git commit -m "style: php-cs-fixer on aidesc endpoint files"
```
