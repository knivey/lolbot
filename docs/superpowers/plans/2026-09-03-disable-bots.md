# Disable Bots (per-bot + per-network kill-switch) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `disabled` bool flags to `Bot` and `Network`; disabled bots don't connect at startup, QUIT live when toggled off, and respawn when re-enabled — enforced at startup, in `BotManager::apply()` live-sync, and exposed via admin CLI and web panel.

**Architecture:** Independent booleans with OR semantics — a bot is stopped iff `bot.disabled || network.disabled` (centralized in `Bot::isDisabled()`). One Doctrine migration adds the columns. `BotManager::apply()` grows a `syncBot()` method for bot/update and a reworked network/update that drops disabled bots and spawns enabled-but-not-running ones. CLI `*:set` commands and web edit forms flip the flags through the existing `ConfigService::update()` → `ConfigChange` push path.

**Tech Stack:** PHP 8.1, Doctrine ORM 2.x + migrations, PHPUnit 10 (SQLite in-memory via `tests/Config/ConfigTestCase.php`), Symfony Console + CommandTester, Twig web panel.

**Spec:** `docs/superpowers/specs/2026-09-03-disable-bots-design.md`

**Key codebase facts (read these before coding):**

- `tests/Config/ConfigTestCase.php` builds the SQLite schema straight from entity metadata (`SchemaTool::createSchema`) — entity tests exercise new columns without running migrations.
- `library/BotManager.php` holds live bots in public arrays: `clients[int botId => Irc\Client]`, `bots[botId => Bot]`, `networks[botId => Network]`, `state[botId => stdClass]`. `drop()` unsets all four. `spawn()` registers into all four at its end (lines 299-302).
- `BotManager::apply()` is wrapped in a try/catch that echoes and swallows — new live-sync logic can throw without crashing the bot.
- In production the mutating process (CLI/web) is separate from the bot process, so the bot's Doctrine identity map is stale; `em->refresh()` is required after `em->find()` inside `apply()` paths (the old network/update code did exactly this).
- CLI bool parsing precedent: `scripts/linktitles/cli_cmds/linktitles_set.php` uses `filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? throw new \InvalidArgumentException(...)`. Follow it.
- Bool column precedent: `entities/Server.php` `#[ORM\Column] public bool $ssl = false;`.
- Migration portability precedent: `Migrations/Version20260621120000.php` uses the schema-comparator technique (`compareSchemas` + `platform->getAlterSchemaSQL`) so ALTERs work on SQLite and Postgres. Table names are `Bots` and `Networks` (capitalized).
- Web checkbox precedent: `web/sections/networks.php` `web_networks_update_server` uses `$srv->ssl = isset($_POST['ssl']);`.
- The web panel and CLI never render raw entity output except via `__toString()` — `bot:list`, `network:list`, and `showdb` all `writeln`/echo entities, so badge state belongs in `__toString()`.
- NEVER trim art outputs, NEVER remove existing comments when editing, NEVER `git add -f` (AGENTS.md).

---

### Task 1: Entities — `disabled` flags, `isDisabled()`, `__toString()` badges

**Files:**
- Modify: `entities/Bot.php`
- Modify: `entities/Network.php`
- Test: `tests/Config/BotDisabledTest.php` (new)

- [ ] **Step 1: Write the failing entity tests**

Create `tests/Config/BotDisabledTest.php`:

```php
<?php
namespace Tests\Config;

use lolbot\config\ConfigService;
use lolbot\entities\Bot;

require_once __DIR__ . '/../../vendor/autoload.php';

class BotDisabledTest extends ConfigTestCase
{
    public function test_disabled_defaults_to_false(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');

        $this->assertFalse($net->disabled);
        $this->assertFalse($bot->disabled);
        $this->assertFalse($bot->isDisabled());
    }

    public function test_is_disabled_when_bot_flag_set(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        $bot->disabled = true;
        $this->em->flush();

        $this->assertTrue($bot->isDisabled());
    }

    public function test_is_disabled_when_network_flag_set(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        $net->disabled = true;
        $this->em->flush();

        $this->assertFalse($bot->disabled);
        $this->assertTrue($bot->isDisabled());
    }

    public function test_is_disabled_without_network_is_own_flag(): void
    {
        $bot = new Bot();
        $this->assertFalse($bot->isDisabled());
        $bot->disabled = true;
        $this->assertTrue($bot->isDisabled());
    }

    public function test_to_string_marks_disabled(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        $this->assertStringNotContainsString('disabled', (string)$bot);
        $this->assertStringNotContainsString('disabled', (string)$net);

        $bot->disabled = true;
        $this->assertStringContainsString('[disabled]', (string)$bot);

        $bot->disabled = false;
        $net->disabled = true;
        $this->assertStringContainsString('[disabled (network)]', (string)$bot);
        $this->assertStringContainsString('[disabled]', (string)$net);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Config/BotDisabledTest.php`
Expected: FAIL — errors like `Call to undefined method lolbot\entities\Bot::isDisabled()` and `Failed asserting that null is false` (unknown `disabled` property reads as null).

- [ ] **Step 3: Implement the entity changes**

In `entities/Bot.php`, add the column after the `$bindIp` property (after line 38):

```php
    #[ORM\Column]
    public bool $disabled = false;
```

Add after the `getChannels()` method:

```php
    /**
     * Effective disabled state: a bot is stopped when it or its network is disabled.
     */
    public function isDisabled(): bool
    {
        return $this->disabled || (isset($this->network) && $this->network->disabled);
    }
```

Replace `__toString()` (keeping the base output identical when enabled) with:

```php
    public function __toString(): string
    {
        $s = "id: $this->id name: $this->name created: ".$this->created->format('r');
        if ($this->disabled) {
            $s .= " [disabled]";
        } elseif (isset($this->network) && $this->network->disabled) {
            $s .= " [disabled (network)]";
        }
        return $s;
    }
```

In `entities/Network.php`, add the column after the `$name` property (after line 20):

```php
    #[ORM\Column]
    public bool $disabled = false;
```

Replace `__toString()` with:

```php
    public function __toString():string {
        $s = "id: {$this->id} name: {$this->name} created: ".$this->created->format('r');
        if ($this->disabled)
            $s .= " [disabled]";
        return $s;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Config/BotDisabledTest.php`
Expected: PASS (5 tests, 5 assertions)

- [ ] **Step 5: Commit**

```bash
git add entities/Bot.php entities/Network.php tests/Config/BotDisabledTest.php
git commit -m "feat(entities): disabled flag on Bot and Network"
```

---

### Task 2: Doctrine migration for the `disabled` columns

**Files:**
- Create: `Migrations/Version20260903120000.php`

- [ ] **Step 1: Write the migration**

Create `Migrations/Version20260903120000.php`:

```php
<?php

declare(strict_types=1);

namespace lolbot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds Bots.disabled / Networks.disabled (NOT NULL, default false) so bots
 * can be taken offline per-bot or per-network without deleting them.
 *
 * Uses the portable schema-comparator technique (mirroring
 * Version20260621120000.php) so the generated ALTER statements work on both
 * SQLite (tests) and Postgres (prod).
 */
final class Version20260903120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add disabled bool columns to Bots and Networks';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        $comp = $sm->createComparator();
        $newSchema = clone $schema;

        $bots = $newSchema->getTable("Bots");
        $bots->addColumn("disabled", Types::BOOLEAN)->setNotnull(true)->setDefault(false);

        $nets = $newSchema->getTable("Networks");
        $nets->addColumn("disabled", Types::BOOLEAN)->setNotnull(true)->setDefault(false);

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        $comp = $sm->createComparator();
        $newSchema = clone $schema;

        $bots = $newSchema->getTable("Bots");
        $bots->dropColumn("disabled");

        $nets = $newSchema->getTable("Networks");
        $nets->dropColumn("disabled");

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }
}
```

- [ ] **Step 2: Verify the migration generates valid SQL (no execution)**

Run: `php admin-cli.php migrations:migrate --dry-run`
Expected: shows `Version20260903120000` with two `ALTER TABLE ... ADD disabled` statements (SQLite/Postgres dialect per your `config.yaml`), and does NOT execute anything. If `config.yaml` is missing on this machine, instead verify with `php admin-cli.php migrations:status` that the new version is listed as not-migrated and skip the dry-run.

- [ ] **Step 3: Verify the full test suite still passes (tests build schema from metadata, migration not needed)**

Run: `composer test`
Expected: all pass (entity tests from Task 1 keep passing; schema in tests comes from `SchemaTool`, which now includes the new columns).

- [ ] **Step 4: Commit**

```bash
git add Migrations/Version20260903120000.php
git commit -m "feat(db): migration for Bots/Networks disabled columns"
```

Note (for the final report to the user): the bot refuses to start with pending migrations (`dieIfPendingMigration()`), so before the next `php lolbot.php` run on a real DB, run `php admin-cli.php migrations:migrate`.

---

### Task 3: BotManager live-sync — `syncBot()`, bot/create guard, network/update drop+respawn

**Files:**
- Modify: `library/BotManager.php` (apply() bot case lines ~394-401, network case lines ~403-409; new `syncBot()` method)
- Test: `tests/Config/BotManagerApplyTest.php` (extend)

- [ ] **Step 1: Write the failing apply tests**

In `tests/Config/BotManagerApplyTest.php`, add this test-double class at the bottom of the file (after the `BotManagerApplyTest` class). It stubs `spawn()` so tests never build real IRC clients, while performing the same fleet bookkeeping as the real `spawn()` tail:

```php
/**
 * BotManager whose spawn() is stubbed: records bot ids and registers mock
 * clients instead of constructing real IRC connections.
 */
class RecordingBotManager extends BotManager
{
    /** @var list<int> */
    public array $spawned = [];
    private \PHPUnit\Framework\TestCase $tc;

    public function __construct(\Doctrine\ORM\EntityManager $em, \PHPUnit\Framework\TestCase $tc)
    {
        parent::__construct($em);
        $this->tc = $tc;
    }

    public function spawn(\lolbot\entities\Network $network, \lolbot\entities\Bot $dbBot): \Irc\Client
    {
        $this->spawned[] = $dbBot->id;
        $client = $this->tc->createMock(\Irc\Client::class);
        $this->clients[$dbBot->id] = $client;
        $this->bots[$dbBot->id] = $dbBot;
        $this->networks[$dbBot->id] = $network;
        $this->state[$dbBot->id] = new \stdClass();
        return $client;
    }
}
```

Add these test methods inside `BotManagerApplyTest`:

```php
    public function test_bot_update_disabled_drops_client(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        [$mgr, $client] = $this->mgrWithBot($net, $bot);
        $client->expects($this->once())->method('exit');
        $bot->disabled = true;
        $this->em->flush();
        $mgr->apply(new ConfigChange('bot', $bot->id, 'update'));
        $this->assertArrayNotHasKey($bot->id, $mgr->clients);
    }

    public function test_bot_update_network_disabled_drops_client(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        [$mgr, $client] = $this->mgrWithBot($net, $bot);
        $client->expects($this->once())->method('exit');
        $net->disabled = true;
        $this->em->flush();
        $mgr->apply(new ConfigChange('bot', $bot->id, 'update'));
        $this->assertArrayNotHasKey($bot->id, $mgr->clients);
    }

    public function test_bot_update_reenable_spawns(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        $bot->disabled = true;
        $this->em->flush();
        $mgr = new RecordingBotManager($this->em, $this);

        $mgr->apply(new ConfigChange('bot', $bot->id, 'update'));
        $this->assertSame([], $mgr->spawned);

        $bot->disabled = false;
        $this->em->flush();
        $mgr->apply(new ConfigChange('bot', $bot->id, 'update'));
        $this->assertSame([$bot->id], $mgr->spawned);
        $this->assertArrayHasKey($bot->id, $mgr->clients);
    }

    public function test_bot_create_disabled_does_not_spawn(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        $bot->disabled = true;
        $this->em->flush();
        $mgr = new RecordingBotManager($this->em, $this);
        $mgr->apply(new ConfigChange('bot', $bot->id, 'create'));
        $this->assertSame([], $mgr->spawned);
    }

    public function test_network_update_disabled_drops_all_bots(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot1 = $svc->createBot($net, 'b1');
        $bot2 = $svc->createBot($net, 'b2');
        $mgr = new BotManager($this->em);
        foreach ([$bot1, $bot2] as $b) {
            $mgr->clients[$b->id] = $this->createMock(\Irc\Client::class);
            $mgr->bots[$b->id] = $b;
            $mgr->networks[$b->id] = $net;
            $mgr->state[$b->id] = new \stdClass();
        }
        $net->disabled = true;
        $this->em->flush();
        $mgr->apply(new ConfigChange('network', $net->id, 'update'));
        $this->assertArrayNotHasKey($bot1->id, $mgr->clients);
        $this->assertArrayNotHasKey($bot2->id, $mgr->clients);
    }

    public function test_network_update_reenable_spawns_only_enabled_bots(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $b1 = $svc->createBot($net, 'b1');
        $b2 = $svc->createBot($net, 'b2');
        $b2->disabled = true;
        $net->disabled = true;
        $this->em->flush();

        $mgr = new RecordingBotManager($this->em, $this);
        $mgr->apply(new ConfigChange('network', $net->id, 'update'));
        $this->assertSame([], $mgr->spawned);

        $net->disabled = false;
        $this->em->flush();
        $mgr->apply(new ConfigChange('network', $net->id, 'update'));
        $this->assertSame([$b1->id], $mgr->spawned);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Config/BotManagerApplyTest.php`
Expected: the 6 new tests FAIL (no drop/spawn happens on update; `RecordingBotManager` is defined but unused logic) — the 5 pre-existing tests still PASS.

- [ ] **Step 3: Implement the BotManager changes**

In `library/BotManager.php`, add this method after `reloadBot()` (after line 367):

```php
    /**
     * Live-sync one bot after a config change: drop it if disabled (its own
     * flag or its network's), spawn it if it should run but has no client
     * (covers re-enable), else reload in place. Refreshes held entities from
     * the DB because mutations arrive from a separate process.
     */
    public function syncBot(int $botId): void
    {
        $held = $this->bots[$botId] ?? null;
        if ($held !== null) {
            $this->em->refresh($held->network);
            $this->em->refresh($held);
            if ($held->isDisabled()) {
                $this->drop($botId);
                return;
            }
            if (!isset($this->clients[$botId])) {
                $this->spawn($held->network, $held);
                return;
            }
            $this->reloadBot($botId);
            return;
        }
        $fresh = $this->em->find(\lolbot\entities\Bot::class, $botId);
        if ($fresh === null) return;
        $this->em->refresh($fresh);
        if (!$fresh->isDisabled()) $this->spawn($fresh->network, $fresh);
    }
```

In `apply()`, replace the `'bot'` case with (the only changes: the create guard and update now routes through `syncBot`):

```php
                case 'bot':
                    if ($c->action === 'create') {
                        $bot = $this->em->find(\lolbot\entities\Bot::class, $c->id);
                        if ($bot !== null && !$bot->isDisabled()) $this->spawn($bot->network, $bot);
                        return;
                    }
                    if ($c->action === 'delete') { $this->drop((int)$c->id); return; }
                    if ($c->action === 'update') { $this->syncBot((int)$c->id); return; }
                    return;
```

Replace the `'network'` case with:

```php
                case 'network':
                    if ($c->action === 'update') {
                        $net = $this->em->find(\lolbot\entities\Network::class, $c->id);
                        if ($net === null) return;
                        $this->em->refresh($net);
                        // Held bots: drop if disabled, spawn if missing a client, refresh otherwise.
                        foreach ($this->bots as $bid => $bot) {
                            if ($bot->network->id !== $c->id) continue;
                            $this->em->refresh($bot);
                            if ($bot->isDisabled()) {
                                $this->drop((int)$bid);
                            } elseif (!isset($this->clients[$bid])) {
                                $this->spawn($net, $bot);
                            }
                        }
                        // Bots created (or previously dropped) while the network was disabled are
                        // not held by the manager; bring the enabled ones up on re-enable.
                        foreach ($net->getBots() as $bot) {
                            if (!isset($this->bots[$bot->id]) && !$bot->isDisabled()) {
                                $this->spawn($net, $bot);
                            }
                        }
                    }
                    return;
```

(The old network/update behavior — refreshing each held bot entity of that network — is folded into the `$this->em->refresh($bot)` call in the first loop.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Config/BotManagerApplyTest.php`
Expected: PASS (11 tests: 5 pre-existing + 6 new). The pre-existing `test_bot_update_reloads_nick_when_changed` must still pass — held, enabled, client present routes to `reloadBot()`.

- [ ] **Step 5: Commit**

```bash
git add library/BotManager.php tests/Config/BotManagerApplyTest.php
git commit -m "feat(botmanager): live-sync drop/spawn on disable toggles"
```

---

### Task 4: Startup skip in `lolbot.php`

**Files:**
- Modify: `lolbot.php:142-148`

- [ ] **Step 1: Update the startup loop**

Replace the "Start every existing bot" block in `main()`:

```php
    // Start every existing bot (unless disabled — per-bot or per-network).
    $nets = $entityManager->getRepository(Network::class)->findAll();
    foreach ($nets as $network) {
        if ($network->disabled)
            continue;
        foreach ($network->getBots() as $bot) {
            if ($bot->isDisabled())
                continue;
            $mgr->spawn($network, $bot);
        }
    }
```

- [ ] **Step 2: Verify syntax and static analysis**

Run: `php -l lolbot.php && vendor/bin/phpstan analyse lolbot.php --no-progress`
Expected: no syntax errors; no NEW phpstan errors on the touched lines (the repo has a large pre-existing baseline; only care about lines we added).

- [ ] **Step 3: Run the full test suite**

Run: `composer test`
Expected: all pass.

- [ ] **Step 4: Commit**

```bash
git add lolbot.php
git commit -m "feat(lolbot): skip disabled bots/networks at startup"
```

---

### Task 5: CLI — `bot:set` / `network:set` disabled toggle

**Files:**
- Modify: `cli_cmds/bot_set.php`
- Modify: `cli_cmds/network_set.php`
- Test: `tests/Config/BotSetCommandTest.php` (new), `tests/Config/NetworkSetCommandTest.php` (new)

- [ ] **Step 1: Write the failing CLI tests**

Create `tests/Config/BotSetCommandTest.php`:

```php
<?php
namespace Tests\Config;

use lolbot\config\ConfigService;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__ . '/../../vendor/autoload.php';

class BotSetCommandTest extends ConfigTestCase
{
    private function runCommand(string $setting, string $value, int $botId): int
    {
        $GLOBALS['entityManager'] = $this->em;
        $GLOBALS['config'] = $GLOBALS['config'] ?? [];
        $tester = new CommandTester(new \lolbot\cli_cmds\bot_set());
        return $tester->execute(['bot' => (string)$botId, 'setting' => $setting, 'value' => $value]);
    }

    private function makeBot(): \lolbot\entities\Bot
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        return $svc->createBot($net, 'b');
    }

    public function test_sets_disabled_true(): void
    {
        $bot = $this->makeBot();
        $this->runCommand('disabled', 'true', $bot->id);
        $this->assertTrue($bot->disabled);
    }

    public function test_sets_disabled_1_and_back_0(): void
    {
        $bot = $this->makeBot();
        $this->runCommand('disabled', '1', $bot->id);
        $this->assertTrue($bot->disabled);
        $this->runCommand('disabled', '0', $bot->id);
        $this->assertFalse($bot->disabled);
    }

    public function test_rejects_garbage(): void
    {
        $bot = $this->makeBot();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('disabled must be true or false');
        $this->runCommand('disabled', 'garbage', $bot->id);
    }
}
```

Create `tests/Config/NetworkSetCommandTest.php`:

```php
<?php
namespace Tests\Config;

use lolbot\config\ConfigService;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__ . '/../../vendor/autoload.php';

class NetworkSetCommandTest extends ConfigTestCase
{
    private function runCommand(string $setting, string $value, int $netId): int
    {
        $GLOBALS['entityManager'] = $this->em;
        $GLOBALS['config'] = $GLOBALS['config'] ?? [];
        $tester = new CommandTester(new \lolbot\cli_cmds\network_set());
        return $tester->execute(['network' => (string)$netId, 'setting' => $setting, 'value' => $value]);
    }

    private function makeNet(): \lolbot\entities\Network
    {
        $svc = new ConfigService($this->em);
        return $svc->createNetwork('N');
    }

    public function test_sets_disabled_true(): void
    {
        $net = $this->makeNet();
        $this->runCommand('disabled', 'true', $net->id);
        $this->assertTrue($net->disabled);
    }

    public function test_sets_disabled_false(): void
    {
        $net = $this->makeNet();
        $net->disabled = true;
        $this->em->flush();
        $this->runCommand('disabled', 'false', $net->id);
        $this->assertFalse($net->disabled);
    }

    public function test_rejects_garbage(): void
    {
        $net = $this->makeNet();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('disabled must be true or false');
        $this->runCommand('disabled', 'garbage', $net->id);
    }
}
```

Note: `network_set::execute` calls `showdb::showdb()` after a successful set — it echoes to stdout, which CommandTester captures harmlessly.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Config/BotSetCommandTest.php tests/Config/NetworkSetCommandTest.php`
Expected: FAIL — "No setting by that name" (`disabled` not in whitelist) / `disabled` unknown property exceptions.

- [ ] **Step 3: Implement `bot_set` changes**

In `cli_cmds/bot_set.php`:

Add to the `$settings` array (after `"bindIp"`):

```php
        "disabled"
```

Replace the value-assignment block (lines 65-67) with:

```php
        $value = $input->getArgument("value");
        if ($setting === "disabled") {
            $bot->disabled = filter_var(is_string($value) ? $value : "", FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                ?? throw new \InvalidArgumentException("disabled must be true or false");
        } else {
            $bot->$setting = is_string($value) ? $value : '';
        }
        $svc->update($bot, "bot");
```

In `showsets()`, replace the row loop so bools render as words (checkbox-style `false` renders as empty string otherwise):

```php
        foreach ($this->settings as $setting) {
            $val = $bot->$setting;
            $rows[] = [$setting, is_bool($val) ? var_export($val, true) : $val];
        }
```

- [ ] **Step 4: Implement `network_set` changes**

In `cli_cmds/network_set.php`:

Change `$settings` to:

```php
    public array $settings = [
        "name",
        "disabled"
        ];
```

Replace the assignment block (lines 58-62, keeping the trailing `showdb::showdb()` call) with:

```php
        $setting = $input->getArgument("setting");
        if ($setting === "disabled") {
            $value = $input->getArgument("value");
            $network->disabled = filter_var(is_string($value) ? $value : "", FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                ?? throw new \InvalidArgumentException("disabled must be true or false");
        } else {
            $network->$setting = $input->getArgument("value");
        }
        $svc->update($network, "network");
        showdb::showdb();
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Config/BotSetCommandTest.php tests/Config/NetworkSetCommandTest.php`
Expected: PASS (6 tests)

- [ ] **Step 6: Commit**

```bash
git add cli_cmds/bot_set.php cli_cmds/network_set.php tests/Config/BotSetCommandTest.php tests/Config/NetworkSetCommandTest.php
git commit -m "feat(cli): bot:set/network:set disabled toggle"
```

---

### Task 6: Web panel — checkboxes on edit forms, badges on lists

**Files:**
- Modify: `web/sections/bots.php` (`web_bots_update`, after the `bindIp` assignment at line 79)
- Modify: `web/sections/networks.php` (`web_networks_update`, after the name assignment at line 36-37)
- Modify: `web/templates/bots/edit.twig` (after the `bindIp` field, line 20)
- Modify: `web/templates/networks/edit.twig` (after the `name` field, line 9)
- Modify: `web/templates/bots/list.twig` (name cell, line 16)
- Modify: `web/templates/networks/list.twig` (name cell, line 14)

There is no automated web-form test coverage in this repo (the `ssl`/`throttle` checkboxes have none either); verification here is syntax + static analysis. Do not invent a web test harness in this task.

- [ ] **Step 1: Bot form — section handler**

In `web/sections/bots.php` `web_bots_update()`, after the `bindIp` assignments add:

```php
    $bot->disabled    = isset($_POST['disabled']);
```

- [ ] **Step 2: Bot form — template checkbox**

In `web/templates/bots/edit.twig`, between the `bindIp` field line and `{{ m.submit('Save') }}` add:

```twig
  <div class="mb-3 form-check">
    <input class="form-check-input" type="checkbox" name="disabled" id="bot_disabled"{{ bot and bot.disabled ? ' checked' : '' }}>
    <label class="form-check-label" for="bot_disabled">disabled (bot will not connect; live bot disconnects)</label>
  </div>
```

- [ ] **Step 3: Bot list — disabled badge**

In `web/templates/bots/list.twig`, replace line 16 (the full `<td>` row start) with:

```twig
        <td>{{ b.id }}</td><td>{{ b.name }}{% if b.isDisabled %} <span class="badge text-bg-danger">{{ b.disabled ? 'disabled' : 'disabled (network)' }}</span>{% endif %}</td><td>{{ b.network.name }}</td>
```

(Twig resolves `b.isDisabled` to the `isDisabled()` getter. The network-distinguishing text uses `b.disabled` directly.)

- [ ] **Step 4: Network form — section handler**

In `web/sections/networks.php` `web_networks_update()`, after the name assignment/validation add:

```php
    $net->disabled = isset($_POST['disabled']);
```

- [ ] **Step 5: Network form — template checkbox**

In `web/templates/networks/edit.twig`, between the `name` field and `{{ m.submit('Save') }}` add:

```twig
  <div class="mb-3 form-check">
    <input class="form-check-input" type="checkbox" name="disabled" id="net_disabled"{{ net and net.disabled ? ' checked' : '' }}>
    <label class="form-check-label" for="net_disabled">disabled (all bots on this network stop)</label>
  </div>
```

- [ ] **Step 6: Network list — disabled badge**

In `web/templates/networks/list.twig`, replace line 14 (the full row) with:

```twig
      <tr><td>{{ n.id }}</td><td>{{ n.name }}{% if n.disabled %} <span class="badge text-bg-danger">disabled</span>{% endif %}</td><td>{{ n.servers|length }}</td><td>{{ n.bots|length }}</td>
```

- [ ] **Step 7: Verify**

Run: `php -l web/sections/bots.php && php -l web/sections/networks.php && vendor/bin/phpstan analyse web/sections/bots.php web/sections/networks.php --no-progress && composer test`
Expected: no syntax errors, no NEW phpstan errors on touched lines, all tests pass.

- [ ] **Step 8: Commit**

```bash
git add web/sections/bots.php web/sections/networks.php web/templates/bots/edit.twig web/templates/bots/list.twig web/templates/networks/edit.twig web/templates/networks/list.twig
git commit -m "feat(web): disabled toggle on bot/network forms + badges"
```

---

### Task 7: Full verification + review

- [ ] **Step 1: Full test suite**

Run: `composer test`
Expected: all pass.

- [ ] **Step 2: Scoped static analysis on everything touched**

Run: `vendor/bin/phpstan analyse entities/Bot.php entities/Network.php library/BotManager.php lolbot.php cli_cmds/bot_set.php cli_cmds/network_set.php web/sections/bots.php web/sections/networks.php tests/Config/ --no-progress`
Expected: no errors attributable to lines added by this plan (repo has a large pre-existing baseline; only new-code errors block completion — fix those).

- [ ] **Step 3: Formatter check on touched files**

Run: `vendor/bin/php-cs-fixer fix entities/Bot.php entities/Network.php library/BotManager.php lolbot.php cli_cmds/bot_set.php cli_cmds/network_set.php Migrations/Version20260903120000.php web/sections/bots.php web/sections/networks.php tests/Config/BotDisabledTest.php tests/Config/BotManagerApplyTest.php tests/Config/BotSetCommandTest.php tests/Config/NetworkSetCommandTest.php --dry-run --diff`
Expected: no diffs on our files. If diffs appear, drop `--dry-run` for those files, re-run tests, and amend nothing (new commit).

- [ ] **Step 4: Confirm clean tree and report to user**

Run: `git status && git log --oneline -8`
Expected: only intentional commits; working tree clean.

Report to the user: feature summary, reminder to run `php admin-cli.php migrations:migrate` before next bot start, and the manual smoke checks (edit a bot in the web panel with the disabled box and watch it QUIT; `bot:list` shows `[disabled]`).

- [ ] **Step 5: Request code review**

Per AGENTS.md, request a code review (requesting-code-review skill) covering both spec compliance and code quality for all commits in this plan.
