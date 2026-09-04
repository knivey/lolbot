# Disable-Bots Follow-Ups Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the five acknowledged follow-ups from the disable-bots feature: create paths honor the disabled flag, one shared bool parser with an honest error message, `syncBot()` refresh symmetry, drop-reason cosmetics, and zero PHPUnit mock notices.

**Architecture:** A new `lolbot\config\SettingBool` static helper consolidates 7 duplicated `FILTER_VALIDATE_BOOLEAN` sites. `ConfigService::createBot/createNetwork` gain optional `bool $disabled` params set before the single create flush (so live-apply's existing guard prevents spawn flicker). `BotManager::drop()` gains a QUIT reason; `syncBot()` refreshes the network on the un-held path. Expectation-free `createMock()` doubles become `createStub()` across four test files.

**Tech Stack:** PHP 8.1, Doctrine ORM, PHPUnit 13 (`createStub`), Symfony Console, Twig.

**Spec:** `docs/superpowers/specs/2026-09-03-disable-bots-followups-design.md`

**Key codebase facts (read before coding):**

- PSR-4: `lolbot\config\` → `library/config` (home of `ConfigService`, `SettingsResolver`, etc.); `lolbot\cli_cmds\` → `cli_cmds`; `scripts\` → `scripts`.
- The 12 PHPUnit notices map to exactly these tests (verified via `vendor/bin/phpunit --display-phpunit-notices`):
  - `tests/Config/BotManagerApplyTest.php`: `test_linktitles_setting_update_refreshes_enabled_holder`, `test_bot_update_reenable_spawns`, `test_network_update_disabled_drops_all_bots`, `test_network_update_reenable_spawns_only_enabled_bots`, `test_network_update_uses_fresh_bot_membership_not_startup_snapshot`
  - `tests/Config/BotManagerStatusTest.php`: both tests
  - `tests/Linktitles/FormatImageResponseTest.php`: all 4 tests (mocks in `setUp()`)
  - `tests/Remindme/DeliverTest.php`: `test_deliver_only_sends_once_when_called_twice`
- The notice fires for any `createMock()` double that never receives `->expects(...)` — `->method()->willReturn()` does NOT count. Stubs (`createStub()`) support `->method()->willReturn()` but not `->expects()`.
- `RecordingBotManager` (bottom of `BotManagerApplyTest.php`) currently takes `(EntityManager, TestCase)` and builds clients via `(new MockBuilder($this->tc, \Irc\Client::class))->disableOriginalConstructor()->getMock()`. `MockBuilder` cannot produce stubs, so it gets a client-factory closure instead.
- `bot_set`/`network_set` bool branches guard `is_string($value) ? $value : ""` because console args are `string|null` (null already handled earlier by the show-settings early return).
- `linktitles_set` lines 110-111 and `server_set` lines 66-69 carry the same duplicated expression; `service_set` has a private `parseBool()` (lines 74-81) whose call site is `'bool' => self::parseBool($raw)` inside a `match` (line 66) where `$raw` is already `string`.
- Only tests asserting the old message: `BotSetCommandTest.php:47` and `NetworkSetCommandTest.php:46`.
- AGENTS.md: never remove existing comments, no `git add -f`, never trim art outputs.
- Git discipline for every task: verify `git branch --show-current` shows the working branch before AND after; if detached, STOP and report BLOCKED. Leave the tree clean (no stray files like `.php-cs-fixer.dist.php` — delete if they appear).

---

### Task 1: `SettingBool` parser (TDD)

**Files:**
- Create: `library/config/SettingBool.php`
- Test: `tests/Config/SettingBoolTest.php` (new)

- [ ] **Step 1: Write the failing tests**

Create `tests/Config/SettingBoolTest.php`:

```php
<?php
namespace Tests\Config;

use lolbot\config\SettingBool;

require_once __DIR__ . '/../../vendor/autoload.php';

class SettingBoolTest extends \PHPUnit\Framework\TestCase
{
    /** @return list<array{0: string, 1: bool}> */
    public static function acceptedValues(): array
    {
        return [
            ['true', true], ['1', true], ['on', true], ['yes', true], ['y', true], ['TRUE', true],
            ['false', false], ['0', false], ['off', false], ['no', false], ['n', false], ['', false],
        ];
    }

    /** @dataProvider acceptedValues */
    public function test_accepts_boolean_forms(string $raw, bool $expected): void
    {
        $this->assertSame($expected, SettingBool::parse($raw));
    }

    public function test_rejects_garbage_with_honest_message(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Value must be a boolean value (true/false/1/0/on/off/yes/no)');
        SettingBool::parse('garbage');
    }

    public function test_prefixes_message_with_setting_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('disabled must be a boolean value (true/false/1/0/on/off/yes/no)');
        SettingBool::parse('garbage', 'disabled');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Config/SettingBoolTest.php`
Expected: FAIL — `Class "lolbot\config\SettingBool" not found`.

- [ ] **Step 3: Implement the parser**

Create `library/config/SettingBool.php`:

```php
<?php
namespace lolbot\config;

/**
 * Parses boolean setting values for the *:set CLI commands. Accepts the
 * FILTER_VALIDATE_BOOLEAN forms (true/false/1/0/on/off/yes/no/y/n, "" as
 * false); anything else is rejected with an honest error message.
 */
final class SettingBool
{
    public static function parse(string $value, string $what = 'Value'): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            ?? throw new \InvalidArgumentException("$what must be a boolean value (true/false/1/0/on/off/yes/no)");
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Config/SettingBoolTest.php`
Expected: PASS (14 tests, 14 assertions).

- [ ] **Step 5: Commit**

```bash
git add library/config/SettingBool.php tests/Config/SettingBoolTest.php
git commit -m "feat(config): shared SettingBool parser for CLI bool settings"
```

---

### Task 2: Adopt `SettingBool` in the five commands

**Files:**
- Modify: `cli_cmds/bot_set.php` (~line 67-70)
- Modify: `cli_cmds/network_set.php` (~line 60-64)
- Modify: `cli_cmds/server_set.php` (~lines 66-69)
- Modify: `cli_cmds/service_set.php` (delete `parseBool()`, ~lines 74-81; call site line 66)
- Modify: `scripts/linktitles/cli_cmds/linktitles_set.php` (lines 110-111)
- Modify: `tests/Config/BotSetCommandTest.php` (line 47), `tests/Config/NetworkSetCommandTest.php` (line 46)

- [ ] **Step 1: Update the message assertions first (TDD)**

In `tests/Config/BotSetCommandTest.php` and `tests/Config/NetworkSetCommandTest.php`, change each:

```php
        $this->expectExceptionMessage('disabled must be true or false');
```

to:

```php
        $this->expectExceptionMessage('disabled must be a boolean value (true/false/1/0/on/off/yes/no)');
```

Run: `vendor/bin/phpunit tests/Config/BotSetCommandTest.php tests/Config/NetworkSetCommandTest.php`
Expected: the two `test_rejects_garbage` tests FAIL (message mismatch); the other 4 pass.

- [ ] **Step 2: `bot_set.php`**

Add import after the existing `use` statements:

```php
use lolbot\config\SettingBool;
```

Replace the disabled branch (currently):

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

with:

```php
        $value = $input->getArgument("value");
        if ($setting === "disabled") {
            $bot->disabled = SettingBool::parse(is_string($value) ? $value : "", "disabled");
        } else {
            $bot->$setting = is_string($value) ? $value : '';
        }
        $svc->update($bot, "bot");
```

- [ ] **Step 3: `network_set.php`**

Add the same `use lolbot\config\SettingBool;` import. Replace the disabled branch (currently):

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

with:

```php
        $setting = $input->getArgument("setting");
        if ($setting === "disabled") {
            $value = $input->getArgument("value");
            $network->disabled = SettingBool::parse(is_string($value) ? $value : "", "disabled");
        } else {
            $network->$setting = $input->getArgument("value");
        }
        $svc->update($network, "network");
        showdb::showdb();
```

- [ ] **Step 4: `server_set.php`**

Add the `use lolbot\config\SettingBool;` import. Replace the ssl/throttle match arm (currently):

```php
            'ssl', 'throttle' => is_string($raw)
                ? (filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                    ?? throw new \InvalidArgumentException("Value must be true or false"))
                : false,
```

with:

```php
            'ssl', 'throttle' => is_string($raw)
                ? SettingBool::parse($raw)
                : false,
```

- [ ] **Step 5: `service_set.php`**

Add the `use lolbot\config\SettingBool;` import. Change the call site (line ~66):

```php
                'bool' => self::parseBool($raw),
```

to:

```php
                'bool' => SettingBool::parse($raw),
```

Delete the now-unused private method entirely:

```php
    private static function parseBool(string $raw): bool
    {
        $result = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($result === null) {
            throw new \InvalidArgumentException("Value must be true or false");
        }
        return $result;
    }
```

- [ ] **Step 6: `linktitles_set.php`**

Add `use lolbot\config\SettingBool;` to the imports. Replace lines 110-111 (currently):

```php
            "ai_vision_disabled" => $setting->ai_vision_disabled = filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? throw new \InvalidArgumentException("Value must be true or false"),
            "enabled" => $setting->enabled = filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? throw new \InvalidArgumentException("Value must be true or false"),
```

with:

```php
            "ai_vision_disabled" => $setting->ai_vision_disabled = is_string($val) ? SettingBool::parse($val) : false,
            "enabled" => $setting->enabled = is_string($val) ? SettingBool::parse($val) : false,
```

- [ ] **Step 7: Verify**

Run: `vendor/bin/phpunit tests/Config/BotSetCommandTest.php tests/Config/NetworkSetCommandTest.php tests/Config/SettingBoolTest.php && php -l cli_cmds/service_set.php && php -l scripts/linktitles/cli_cmds/linktitles_set.php && vendor/bin/phpstan analyse cli_cmds/bot_set.php cli_cmds/network_set.php cli_cmds/server_set.php cli_cmds/service_set.php scripts/linktitles/cli_cmds/linktitles_set.php library/config/SettingBool.php --no-progress && composer test`
Expected: all tests pass; no syntax errors; no NEW phpstan errors on touched lines (compare against the pre-existing baseline: `spawn()`-body errors and `ConfigImportTest`/`WebAuthTest` are untouched files/lines); full suite green.

- [ ] **Step 8: Commit**

```bash
git add cli_cmds/bot_set.php cli_cmds/network_set.php cli_cmds/server_set.php cli_cmds/service_set.php scripts/linktitles/cli_cmds/linktitles_set.php tests/Config/BotSetCommandTest.php tests/Config/NetworkSetCommandTest.php
git commit -m "refactor(cli): use shared SettingBool parser in all *:set commands"
```

---

### Task 3: Create paths honor `disabled`

**Files:**
- Modify: `library/config/ConfigService.php` (`createNetwork` ~lines 28-39, `createBot` ~lines 62-78)
- Modify: `web/sections/bots.php` (`web_bots_create`, line ~34)
- Modify: `web/sections/networks.php` (`web_networks_create`, line ~19)
- Test: `tests/Config/ConfigServiceCoreTest.php` (add 2 tests)

- [ ] **Step 1: Write the failing tests**

Add to `ConfigServiceCoreTest` (it has `$this->svc` set up in `setUp()`):

```php
    public function test_createBot_with_disabled(): void
    {
        $net = $this->svc->createNetwork('N');
        $bot = $this->svc->createBot($net, 'b', true);
        $this->assertTrue($bot->disabled);
        $this->assertTrue($bot->isDisabled());

        $bot2 = $this->svc->createBot($net, 'b2');
        $this->assertFalse($bot2->disabled);
    }

    public function test_createNetwork_with_disabled(): void
    {
        $net = $this->svc->createNetwork('N', true);
        $this->assertTrue($net->disabled);

        $net2 = $this->svc->createNetwork('N2');
        $this->assertFalse($net2->disabled);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Config/ConfigServiceCoreTest.php`
Expected: 2 new tests FAIL — `Too few arguments`/unknown named arg or assertion failure (flags not persisted).

- [ ] **Step 3: Implement the ConfigService params**

In `createNetwork()`, change the signature and add the assignment before persist:

```php
    public function createNetwork(string $name, bool $disabled = false): Network
    {
        if ($this->em->getRepository(Network::class)->findOneBy(['name' => $name]) !== null) {
            throw new DuplicateNameException("Network already exists with that name");
        }
        $n = new Network();
        $n->name = $name;
        $n->disabled = $disabled;
        $this->em->persist($n);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('network', $n->id, 'create'));
        return $n;
    }
```

In `createBot()`, same pattern:

```php
    public function createBot(Network $network, string $name, bool $disabled = false): Bot
    {
        if (!isset($network->id)) {
            throw new NotFoundException("Network does not exist (no id)");
        }
        $managed = $this->em->find(Network::class, $network->id);
        if ($managed === null) {
            throw new NotFoundException("Network not found by id {$network->id}");
        }
        $bot = new Bot();
        $bot->name = $name;
        $bot->disabled = $disabled;
        $bot->network = $managed;
        $this->em->persist($bot);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('bot', $bot->id, 'create'));
        return $bot;
    }
```

- [ ] **Step 4: Web create handlers pass the checkbox**

In `web/sections/bots.php` `web_bots_create()`, change:

```php
        $app['svc']->createBot($net, $name);
```

to:

```php
        $app['svc']->createBot($net, $name, isset($_POST['disabled']));
```

In `web/sections/networks.php` `web_networks_create()`, change:

```php
    try { $app['svc']->createNetwork($name); } catch (\Throwable $e) { web_networks_new($e->getMessage()); }
```

to:

```php
    try { $app['svc']->createNetwork($name, isset($_POST['disabled'])); } catch (\Throwable $e) { web_networks_new($e->getMessage()); }
```

(These single-line try/catch blocks are pre-existing style; only the inner call changes.)

- [ ] **Step 5: Verify**

Run: `vendor/bin/phpunit tests/Config/ConfigServiceCoreTest.php && php -l web/sections/bots.php && php -l web/sections/networks.php && vendor/bin/phpstan analyse library/config/ConfigService.php web/sections/bots.php web/sections/networks.php --no-progress && composer test`
Expected: all green; full suite passes (live apply behavior needs no new test — `test_bot_create_disabled_does_not_spawn` in `BotManagerApplyTest` already covers the create+disabled guard).

- [ ] **Step 6: Commit**

```bash
git add library/config/ConfigService.php web/sections/bots.php web/sections/networks.php tests/Config/ConfigServiceCoreTest.php
git commit -m "feat(config): createBot/createNetwork accept disabled flag (web create forms honor checkbox)"
```

---

### Task 4: `syncBot` symmetry, `drop()` reason, cosmetics

**Files:**
- Modify: `library/BotManager.php` (`drop()` ~lines 306-313; `syncBot()` ~lines 375-396; network/update case drop call ~line 442)
- Modify: `entities/Network.php` (`__toString()` ~line 102)
- Modify: `entities/Bot.php` (`$sasl_user`/`$sasl_pass` ~lines 31-35)
- Modify: `cli_cmds/bot_set.php` (`showsets()` ~line 83)
- Test: `tests/Config/BotManagerApplyTest.php` (extend `test_bot_update_disabled_drops_client`)

- [ ] **Step 1: Extend the drop-reason test (TDD)**

In `test_bot_update_disabled_drops_client`, change:

```php
        $client->expects($this->once())->method('exit');
```

to:

```php
        $client->expects($this->once())->method('sendNow')->with('quit :disabled');
        $client->expects($this->once())->method('exit');
```

Run: `vendor/bin/phpunit tests/Config/BotManagerApplyTest.php --filter test_bot_update_disabled_drops_client`
Expected: FAIL — `sendNow` was never called with 'quit :disabled' (current reason is "removed").

- [ ] **Step 2: `drop()` gains a reason**

Replace `drop()` (currently):

```php
    public function drop(int $botId): void
    {
        $client = $this->clients[$botId] ?? null;
        if ($client === null) return;
        try { $client->sendNow("quit :removed"); } catch (\Throwable $e) {}
        $client->exit();
        unset($this->clients[$botId], $this->bots[$botId], $this->networks[$botId], $this->state[$botId]);
    }
```

with:

```php
    public function drop(int $botId, string $reason = "removed"): void
    {
        $client = $this->clients[$botId] ?? null;
        if ($client === null) return;
        try { $client->sendNow("quit :$reason"); } catch (\Throwable $e) {}
        $client->exit();
        unset($this->clients[$botId], $this->bots[$botId], $this->networks[$botId], $this->state[$botId]);
    }
```

- [ ] **Step 3: Pass `"disabled"` from the disable paths**

In `syncBot()` change:

```php
            if ($held->isDisabled()) {
                $this->drop($botId);
                return;
            }
```

to:

```php
            if ($held->isDisabled()) {
                $this->drop($botId, "disabled");
                return;
            }
```

In `apply()`'s `network/update` first loop change:

```php
                            if ($bot->isDisabled()) {
                                $this->drop((int)$bid);
```

to:

```php
                            if ($bot->isDisabled()) {
                                $this->drop((int)$bid, "disabled");
```

- [ ] **Step 4: `syncBot` un-held path refreshes the network**

Change the un-held tail of `syncBot()` (currently):

```php
        $fresh = $this->em->find(\lolbot\entities\Bot::class, $botId);
        if ($fresh === null) return;
        $this->em->refresh($fresh);
        if (!$fresh->isDisabled()) $this->spawn($fresh->network, $fresh);
```

to:

```php
        $fresh = $this->em->find(\lolbot\entities\Bot::class, $botId);
        if ($fresh === null) return;
        $this->em->refresh($fresh->network);
        $this->em->refresh($fresh);
        if (!$fresh->isDisabled()) $this->spawn($fresh->network, $fresh);
```

- [ ] **Step 5: Cosmetics**

In `entities/Network.php` `__toString()`, change:

```php
        if ($this->disabled)
            $s .= " [disabled]";
```

to:

```php
        if ($this->disabled) {
            $s .= " [disabled]";
        }
```

In `entities/Bot.php`, give the two nullable SASL props defaults (currently):

```php
    #[ORM\Column(nullable: true)]
    public ?string $sasl_user;

    #[ORM\Column(nullable: true)]
    public ?string $sasl_pass;
```

to:

```php
    #[ORM\Column(nullable: true)]
    public ?string $sasl_user = null;

    #[ORM\Column(nullable: true)]
    public ?string $sasl_pass = null;
```

In `cli_cmds/bot_set.php` `showsets()`, revert the workaround (currently):

```php
            $val = $bot->$setting ?? null;
```

to:

```php
            $val = $bot->$setting;
```

(All other `Bot` props already have defaults; `trigger`/`trigger_re` are `= null`, `onConnect` `= ""`, `bindIp` `= "0"`, `name` always set on creation.)

- [ ] **Step 6: Verify**

Run: `vendor/bin/phpunit tests/Config/BotManagerApplyTest.php && composer test && vendor/bin/phpstan analyse library/BotManager.php entities/Bot.php entities/Network.php cli_cmds/bot_set.php --no-progress`
Expected: 12 tests in the apply file pass (extended assertion green); full suite green; no NEW phpstan errors on touched lines.

- [ ] **Step 7: Commit**

```bash
git add library/BotManager.php entities/Bot.php entities/Network.php cli_cmds/bot_set.php tests/Config/BotManagerApplyTest.php
git commit -m "feat(botmanager): disabled quit reason, syncBot network refresh, entity cosmetics"
```

---

### Task 5: Zero mock notices — `createStub()` conversions

**Files:**
- Modify: `tests/Config/BotManagerApplyTest.php`
- Modify: `tests/Config/BotManagerStatusTest.php`
- Modify: `tests/Linktitles/FormatImageResponseTest.php`
- Modify: `tests/Remindme/DeliverTest.php`

- [ ] **Step 1: `BotManagerApplyTest` — linktitles test stops using the mock helper**

In `test_linktitles_setting_update_refreshes_enabled_holder`, replace:

```php
        [$mgr, $client] = $this->mgrWithBot($net, $bot);
```

with a manual fleet setup using a stub (this test never configures expectations on the client):

```php
        $mgr = new BotManager($this->em);
        $mgr->clients[$bot->id] = $this->createStub(\Irc\Client::class);
        $mgr->bots[$bot->id] = $bot;
        $mgr->networks[$bot->id] = $net;
        $mgr->state[$bot->id] = new \stdClass();
```

(`mgrWithBot` itself is unchanged — its other callers configure `expects()`.)

- [ ] **Step 2: `BotManagerApplyTest` — `RecordingBotManager` gets a client factory**

Replace the `RecordingBotManager` class (currently uses `(new MockBuilder($this->tc, \Irc\Client::class))->disableOriginalConstructor()->getMock()`) with:

```php
/**
 * BotManager whose spawn() is stubbed: records bot ids and registers client
 * doubles from a factory closure instead of constructing real IRC connections.
 */
class RecordingBotManager extends BotManager
{
    /** @var list<int> */
    public array $spawned = [];
    /** @var \Closure(): \Irc\Client */
    private \Closure $clientFactory;

    public function __construct(\Doctrine\ORM\EntityManager $em, \Closure $clientFactory)
    {
        parent::__construct($em);
        $this->clientFactory = $clientFactory;
    }

    public function spawn(\lolbot\entities\Network $network, \lolbot\entities\Bot $dbBot): \Irc\Client
    {
        $this->spawned[] = $dbBot->id;
        $client = ($this->clientFactory)();
        $this->clients[$dbBot->id] = $client;
        $this->bots[$dbBot->id] = $dbBot;
        $this->networks[$dbBot->id] = $network;
        $this->state[$dbBot->id] = new \stdClass();
        return $client;
    }
}
```

Then update all 4 construction sites from `new RecordingBotManager($this->em, $this)` to:

```php
        $mgr = new RecordingBotManager($this->em, fn(): \Irc\Client => $this->createStub(\Irc\Client::class));
```

(in `test_bot_update_reenable_spawns`, `test_bot_create_disabled_does_not_spawn`, `test_network_update_reenable_spawns_only_enabled_bots`, `test_network_update_uses_fresh_bot_membership_not_startup_snapshot`). Remove the now-unused `MockBuilder` import if the file has one.

- [ ] **Step 3: `BotManagerApplyTest` — network drop test uses stubs**

In `test_network_update_disabled_drops_all_bots`, replace:

```php
            $mgr->clients[$b->id] = $this->createMock(\Irc\Client::class);
```

with:

```php
            $mgr->clients[$b->id] = $this->createStub(\Irc\Client::class);
```

- [ ] **Step 4: `BotManagerStatusTest`**

Replace both occurrences (lines ~20 and ~47):

```php
        $client = $this->createMock(\Irc\Client::class);
```

with:

```php
        $client = $this->createStub(\Irc\Client::class);
```

(`->method()->willReturn()` works on stubs; neither test uses `->expects()`.)

- [ ] **Step 5: `FormatImageResponseTest`**

In `setUp()`, replace all 10 `$this->createMock(...)` calls with `$this->createStub(...)` (repo, entityManager, network, bot, server, client, logger, nicks, chans, router). The `$bot->method('getChannels')...`, `$repo->method(...)`, `$entityManager->method(...)` stubbing calls work unchanged on stubs.

- [ ] **Step 6: `DeliverTest`**

In `test_deliver_only_sends_once_when_called_twice`, replace:

```php
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
```

with:

```php
        $em = $this->createStub(\Doctrine\ORM\EntityManagerInterface::class);
```

(The `$bot` mock in that test keeps `createMock` — it uses `->expects()`.)

- [ ] **Step 7: Verify zero notices**

Run: `vendor/bin/phpunit --display-phpunit-notices 2>&1 | tail -4`
Expected: `PHPUnit Notices: 0`, `Tests: 911, 0 failures` (895 prior + 14 SettingBoolTest + 2 ConfigServiceCoreTest). The hard requirements are: **0 failures, 0 notices**. If any notice remains, its listing names the test and the double — convert that specific `createMock` to `createStub` (never one that receives `->expects()`), re-run.

- [ ] **Step 8: Commit**

```bash
git add tests/Config/BotManagerApplyTest.php tests/Config/BotManagerStatusTest.php tests/Linktitles/FormatImageResponseTest.php tests/Remindme/DeliverTest.php
git commit -m "test: convert expectation-free mocks to stubs (zero PHPUnit notices)"
```

---

### Task 6: Full verification

- [ ] **Step 1: Full suite, zero notices**

Run: `composer test 2>&1 | tail -3`
Expected: 0 failures, **0 PHPUnit notices** (skips may vary by time of day — `ParseDurationTest` skips after 22:00 in some timezone).

- [ ] **Step 2: Scoped static analysis on every touched path**

Run: `vendor/bin/phpstan analyse library/config/SettingBool.php library/config/ConfigService.php library/BotManager.php entities/Bot.php entities/Network.php cli_cmds/bot_set.php cli_cmds/network_set.php cli_cmds/server_set.php cli_cmds/service_set.php scripts/linktitles/cli_cmds/linktitles_set.php web/sections/bots.php web/sections/networks.php tests/ --no-progress`
Expected: only the pre-existing baseline (BotManager `spawn()` body Server|null + script constructor params, `ConfigImportTest`, `WebAuthTest`). Zero errors on lines added by this plan — fix any that appear.

- [ ] **Step 3: Formatter check on new/our lines**

Run per-file (php-cs-fixer requires single paths without a config):
```bash
for f in library/config/SettingBool.php tests/Config/SettingBoolTest.php tests/Config/BotManagerApplyTest.php tests/Config/BotManagerStatusTest.php tests/Linktitles/FormatImageResponseTest.php tests/Remindme/DeliverTest.php tests/Config/ConfigServiceCoreTest.php tests/Config/BotSetCommandTest.php tests/Config/NetworkSetCommandTest.php; do vendor/bin/php-cs-fixer fix "$f" --dry-run --diff --using-cache=no --allow-unsupported-php-version=yes; done
```
Expected: no diffs. If diffs appear on our lines, apply (drop `--dry-run`), re-run tests, include in a `style:` commit. Do NOT reformat pre-existing lines.

- [ ] **Step 4: Tree + log check**

Run: `git status --short && git log --oneline -7`
Expected: clean tree; the 5 feature commits from this plan on top.

- [ ] **Step 5: Final code review**

Per AGENTS.md, request a code review (requesting-code-review skill) over the whole branch range covering both spec compliance and code quality.
