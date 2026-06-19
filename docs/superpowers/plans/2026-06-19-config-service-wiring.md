# Config Service Wiring Implementation Plan (Sub-project 1B)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire the Plan 1A foundation into the app: route the existing `admin-cli` mutating commands through `ConfigService`, migrate the `linktitles` AI consumer and the `help`/`alias` paste consumers to the service/resolver, make `lolbot.php` read linktitles enable from the DB, and clean up `config.yaml`.

**Architecture:** No daemon. Mutating CLI commands call `ConfigService` (so the `ChangeNotifier` seam fires on every edit). Runtime consumers read service config via `ServiceLocator` and per-scope script settings via `SettingsResolver`. The DB is the single source of truth.

**Tech Stack:** PHP 8.1+, Doctrine ORM, Symfony Console 6, PHPUnit 13.

**Depends on:** `docs/superpowers/plans/2026-06-19-config-service-foundation.md` (Plan 1A) completed.
**Spec:** `docs/superpowers/specs/2026-06-19-config-service-design.md`

---

## File map (this plan)

| File | Change |
|---|---|
| `library/config/ConfigService.php` | Add a generic `update()` helper for the `*:set` commands. |
| `cli_cmds/network_add.php`, `network_del.php`, `network_list.php`, `network_set.php` | Route through `ConfigService`. |
| `cli_cmds/bot_add.php`, `bot_del.php`, `bot_list.php`, `bot_set.php`, `bot_addchannel.php`, `bot_delchannel.php` | Route through `ConfigService`. |
| `cli_cmds/server_add.php`, `server_del.php`, `server_set.php` | Route through `ConfigService`. |
| `cli_cmds/ignore_add.php`, `ignore_del.php`, `ignore_list.php`, `ignore_addnetwork.php` | Route through `ConfigService`. (`ignore:test` and `showdb` stay — read-only diagnostics.) |
| `scripts/linktitles/cli_cmds/linktitles_set.php` | Gain the new keys (`enabled`, `url_log_chan`, `ai_vision_model`, `ai_vision_prompt`, `ai_vision_reasoning_effort`, `ai_vision_reasoning`). |
| `scripts/linktitles/linktitles.php` | AI config via `ServiceLocator`; model/prompt/reasoning/url_log_chan via `SettingsResolver`. |
| `scripts/help/help.php`, `artbot_scripts/help.php`, `scripts/alias/alias.php` | Paste host/key via `ServiceLocator`. |
| `lolbot.php` | `:295` linktitles enable read from `SettingsResolver` (resolved once per bot at startup). |
| `config.yaml`, `config.example.yaml` | Remove migrated keys; document transitional ones. |

> **Verification posture:** Plan 1A covers library unit tests. Here, the library is thin delegation, so each task verifies by (a) running the affected command(s), (b) `vendor/bin/phpunit tests/Config` stays green, and (c) `vendor/bin/phpstan analyse <touched paths> --no-progress` on touched files. Run the bot's existing suite too: `vendor/bin/phpunit`.

---

### Task 1: `ConfigService::update()` helper

**Files:**
- Modify: `library/config/ConfigService.php`
- Test: `tests/Config/ConfigServiceUpdateTest.php`

The `*:set` commands assign an arbitrary whitelisted property on an entity then persist. They need a single persistence+notify path so edits fire the `ChangeNotifier`.

- [ ] **Step 1: Write the failing test**

`tests/Config/ConfigServiceUpdateTest.php`:

```php
<?php
namespace Tests\Config;

use lolbot\config\ConfigService;

require_once __DIR__ . '/../../vendor/autoload.php';

class ConfigServiceUpdateTest extends ConfigTestCase
{
    public function test_update_persists_change(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('Old');
        $net->name = 'New';
        $svc->update($net, 'network');
        $this->em->clear();
        $this->assertSame('New', $svc->getNetwork($net->id)->name);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Config/ConfigServiceUpdateTest.php`
Expected: FAIL — `update()` not found.

- [ ] **Step 3: Add the method**

Append inside the `ConfigService` class in `library/config/ConfigService.php`, before the final closing brace:

```php
    /**
     * Generic persistence + notify for the *:set commands, which assign a
     * whitelisted property on an already-managed entity then call this.
     */
    public function update(object $entity, string $type): void
    {
        $this->em->persist($entity);
        $this->em->flush();
        $id = property_exists($entity, 'id') ? ($entity->id ?? null) : null;
        $this->notifier->notify(new ConfigChange($type, $id, 'update'));
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Config/ConfigServiceUpdateTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
git add library/config/ConfigService.php tests/Config/ConfigServiceUpdateTest.php
git commit -m "feat(config): ConfigService::update() for *:set command persistence"
```

---

### Task 2: Route the network commands through `ConfigService`

**Files:**
- Modify: `cli_cmds/network_add.php`, `network_del.php`, `network_list.php`, `network_set.php`

Only the `execute()` method bodies change; `configure()` and the `#[AsCommand]` stay. Each command constructs `\lolbot\config\ConfigService` from the global `$entityManager` (admin-cli bootstraps it).

- [ ] **Step 1: `network:add` — replace the `execute()` method**

```php
    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager);
        $svc->createNetwork($input->getArgument("name"));
        showdb::showdb();
        return Command::SUCCESS;
    }
```

(`createNetwork()` throws `DuplicateNameException` on a clash — Symfony Console surfaces the message.)

- [ ] **Step 2: `network:del` — replace the `execute()` method**

```php
    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager);
        $network = $svc->getNetwork((int)$input->getArgument('network'));
        if ($network === null) {
            throw new \InvalidArgumentException("Couldn't find that network ID");
        }
        $svc->deleteNetwork($network);
        showdb::showdb();
        return Command::SUCCESS;
    }
```

- [ ] **Step 3: `network:list` — replace the `execute()` method**

```php
    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager);
        foreach ($svc->listNetworks() as $network) {
            $output->writeln($network);
        }
        return Command::SUCCESS;
    }
```

- [ ] **Step 4: `network:set` — replace the `execute()` method**

```php
    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager);
        $network = $svc->getNetwork((int)$input->getArgument("network"));
        if (!$network) {
            throw new \InvalidArgumentException("Network by that ID not found");
        }

        if ($input->getArgument("setting") === null) {
            $output->writeln($network);
            return Command::SUCCESS;
        }

        if (!in_array($input->getArgument("setting"), $this->settings, true)) {
            throw new \InvalidArgumentException("No network setting by that name");
        }

        if ($input->getArgument("value") === null) {
            $output->writeln($network);
            return Command::SUCCESS;
        }

        $setting = $input->getArgument("setting");
        $network->$setting = $input->getArgument("value");
        $svc->update($network, "network");
        showdb::showdb();

        return Command::SUCCESS;
    }
```

- [ ] **Step 5: Verify behavior end to end**

```
php admin-cli.php network:add TestNet
php admin-cli.php network:add TestNet
php admin-cli.php network:list
php admin-cli.php network:set <id> name Renamed
php admin-cli.php network:del <id>
```
Expected: add succeeds; duplicate add errors with "Network already exists with that name"; list/rename/delete all work; `showdb` prints after each mutation.

- [ ] **Step 6: Commit**

```bash
git add cli_cmds/network_add.php cli_cmds/network_del.php cli_cmds/network_list.php cli_cmds/network_set.php
git commit -m "refactor(cli): route network commands through ConfigService"
```

---

### Task 3: Route the bot commands through `ConfigService`

**Files:**
- Modify: `cli_cmds/bot_add.php`, `bot_del.php`, `bot_list.php`, `bot_set.php`, `bot_addchannel.php`, `bot_delchannel.php`

- [ ] **Step 1: `bot:add` — replace the `execute()` method**

```php
    public function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager);
        if ($input->getOption("network") === null) {
            throw new \InvalidArgumentException("Must specify a network");
        }
        $network = $svc->getNetwork((int)$input->getOption("network"));
        if ($network === null) {
            throw new \InvalidArgumentException("Couldn't find that network ID");
        }
        $svc->createBot($network, $input->getArgument("name"));
        showdb::showdb();
        return Command::SUCCESS;
    }
```

- [ ] **Step 2: `bot:del` — replace the `execute()` method**

```php
    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager);
        $bot = $svc->getBot((int)$input->getArgument('bot'));
        if ($bot === null) {
            throw new \InvalidArgumentException("Couldn't find a bot with that ID");
        }
        $svc->deleteBot($bot);
        showdb::showdb();
        return Command::SUCCESS;
    }
```

- [ ] **Step 3: `bot:list` — replace the `execute()` method**

```php
    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager);
        foreach ($svc->listBots() as $bot) {
            $output->writeln($bot);
        }
        return Command::SUCCESS;
    }
```

- [ ] **Step 4: `bot:set` — replace the `execute()` method**

```php
    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager);
        $bot = $svc->getBot((int)$input->getArgument("bot"));
        if (!$bot) {
            throw new \InvalidArgumentException("Bot by that ID not found");
        }

        if ($input->getArgument("setting") === null) {
            $this->showsets($input, $output, $bot);
            return Command::SUCCESS;
        }

        if (!in_array($input->getArgument("setting"), $this->settings, true)) {
            throw new \InvalidArgumentException("No setting by that name");
        }

        if ($input->getArgument("value") === null) {
            $this->showsets($input, $output, $bot);
            return Command::SUCCESS;
        }

        $setting = $input->getArgument("setting");
        $bot->$setting = $input->getArgument("value");
        $svc->update($bot, "bot");

        $this->showsets($input, $output, $bot);
        return Command::SUCCESS;
    }
```

(The existing `$settings` list — `name`, `trigger`, `trigger_re`, `onConnect`, `sasl_user`, `sasl_pass`, `bindIp` — and `showsets()` helper are unchanged. `listen` is deliberately **not** added; it is consolidated in Sub-project 2.)

- [ ] **Step 5: `bot:addchannel` — replace the `execute()` method**

```php
    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager);
        $bot = $svc->getBot((int)$input->getArgument("bot"));
        if (!$bot) {
            throw new \InvalidArgumentException("Bot ID not found");
        }
        $svc->addChannel($bot, $input->getArgument("channel"));
        showdb::showdb();
        return Command::SUCCESS;
    }
```

- [ ] **Step 6: `bot:delchannel` — replace the `execute()` method**

```php
    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager);
        $channel = $entityManager->getRepository(\lolbot\entities\Channel::class)->find($input->getArgument("channel"));
        if (!$channel) {
            throw new \InvalidArgumentException("Channel ID not found");
        }
        $svc->deleteChannel($channel);
        showdb::showdb();
        return Command::SUCCESS;
    }
```

- [ ] **Step 7: Verify behavior end to end**

```
php admin-cli.php bot:add --network <netid> TestBot
php admin-cli.php bot:list
php admin-cli.php bot:set <botid> trigger !
php admin-cli.php bot:addchannel <botid> '#test'
php admin-cli.php bot:delchannel <chanid>
php admin-cli.php bot:del <botid>
```
Expected: each works; `showdb`/table output prints as before.

- [ ] **Step 8: Commit**

```bash
git add cli_cmds/bot_add.php cli_cmds/bot_del.php cli_cmds/bot_list.php \
        cli_cmds/bot_set.php cli_cmds/bot_addchannel.php cli_cmds/bot_delchannel.php
git commit -m "refactor(cli): route bot commands through ConfigService"
```

---

### Task 4: Route the server commands through `ConfigService`

**Files:**
- Modify: `cli_cmds/server_add.php`, `server_del.php`, `server_set.php`

- [ ] **Step 1: `server:add` — replace the `execute()` method**

```php
    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager);
        $network = $svc->getNetwork((int)$input->getArgument("network"));
        if (!$network) {
            throw new \InvalidArgumentException("Network ID not found");
        }

        $port = $input->getOption("port");
        $svc->addServer(
            $network,
            $input->getArgument("address"),
            $port !== null ? (int)$port : null,
            (bool)$input->getOption("ssl"),
            !(bool)$input->getOption("no-throttle"),
            $input->getOption("password"),
        );

        showdb::showdb();
        return Command::SUCCESS;
    }
```

(`ConfigService::addServer` validates the port range.)

- [ ] **Step 2: `server:del` — replace the `execute()` method**

```php
    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager);
        $server = $svc->getServer((int)$input->getArgument('server'));
        if ($server === null) {
            throw new \InvalidArgumentException("Couldn't find a server with that ID");
        }
        $svc->deleteServer($server);
        showdb::showdb();
        return Command::SUCCESS;
    }
```

- [ ] **Step 3: `server:set` — replace the `execute()` method**

```php
    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager);
        $server = $svc->getServer((int)$input->getArgument("server"));
        if (!$server) {
            throw new \InvalidArgumentException("Server by that ID not found");
        }

        if ($input->getArgument("setting") === null) {
            $output->writeln($server);
            return Command::SUCCESS;
        }

        if (!in_array($input->getArgument("setting"), $this->settings, true)) {
            throw new \InvalidArgumentException("No setting by that name");
        }

        if ($input->getArgument("value") === null) {
            $output->writeln($server);
            return Command::SUCCESS;
        }

        $setting = $input->getArgument("setting");
        $value = $input->getArgument("value");
        // coerce to the declared property type (port -> int, ssl/throttle -> bool)
        if (in_array($setting, ["port"], true)) {
            $value = (int)$value;
        }
        if (in_array($setting, ["ssl", "throttle"], true)) {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                ?? throw new \InvalidArgumentException("Value must be true or false");
        }
        $server->$setting = $value;
        $svc->update($server, "server");
        showdb::showdb();

        return Command::SUCCESS;
    }
```

- [ ] **Step 4: Verify behavior end to end**

```
php admin-cli.php server:add <netid> irc.example.net --port 7000 --ssl
php admin-cli.php server:set <srvid> port 6697
php admin-cli.php server:del <srvid>
```
Expected: works; invalid port errors.

- [ ] **Step 5: Commit**

```bash
git add cli_cmds/server_add.php cli_cmds/server_del.php cli_cmds/server_set.php
git commit -m "refactor(cli): route server commands through ConfigService"
```

---

### Task 5: Route the ignore commands through `ConfigService`

**Files:**
- Modify: `cli_cmds/ignore_add.php`, `ignore_del.php`, `ignore_list.php`, `ignore_addnetwork.php`

(`ignore:test` stays — it uses `IgnoreRepository::findMatching`, a read-only diagnostic.)

- [ ] **Step 1: `ignore:add` — replace the `execute()` method**

```php
    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager);

        if (count($input->getOption("network")) == 0) {
            throw new \InvalidArgumentException("Must specify a network");
        }

        $networks = [];
        foreach ($input->getOption("network") as $netId) {
            $network = $svc->getNetwork((int)$netId);
            if ($network === null) {
                throw new \InvalidArgumentException("Couldn't find that network ID ($netId)");
            }
            $networks[] = $network;
        }

        $svc->addIgnore(
            $input->getArgument('hostmask'),
            $input->getArgument('reason'),
            $networks,
        );

        showdb::showdb();
        return Command::SUCCESS;
    }
```

- [ ] **Step 2: `ignore:addnetwork` — replace the `execute()` method**

```php
    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager);
        if (count($input->getOption("network")) == 0) {
            throw new \InvalidArgumentException("Must specify a network");
        }

        $ignore = $svc->getIgnore((int)$input->getArgument("ignore"));
        if ($ignore === null) {
            throw new \InvalidArgumentException("Couldn't find that ignore ID");
        }

        $networks = [];
        foreach ($input->getOption("network") as $netId) {
            $network = $svc->getNetwork((int)$netId);
            if ($network === null) {
                throw new \InvalidArgumentException("Couldn't find that network ID ($netId)");
            }
            $networks[] = $network;
        }
        $svc->addIgnoreNetworks($ignore, $networks);

        showdb::showdb();
        return Command::SUCCESS;
    }
```

- [ ] **Step 3: `ignore:del` — replace the `execute()` method**

```php
    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager);
        $ignore = $svc->getIgnore((int)$input->getArgument('ignore'));
        if ($ignore === null) {
            throw new \InvalidArgumentException("Couldn't find an ignore by that ID");
        }
        $svc->deleteIgnore($ignore);
        showdb::showdb();
        return Command::SUCCESS;
    }
```

- [ ] **Step 4: `ignore:list` — replace the `execute()` method**

```php
    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager);
        $ignores = $svc->listIgnores();
        if ($input->getOption("network") !== null) {
            $network = $svc->getNetwork((int)$input->getOption("network"));
            if ($network === null) {
                throw new \InvalidArgumentException("Network by that ID not found");
            }
            $ignores = $network->getIgnores()->toArray();
        }
        if ($input->getOption("orphaned") !== null) {
            $ignores = array_filter($ignores, fn ($i) => count($i->getNetworks()) == 0);
        }
        $this->print_ignores($ignores, $output);
        return Command::SUCCESS;
    }
```

- [ ] **Step 5: Verify behavior end to end**

```
php admin-cli.php ignore:add --network <netid> '*@*.bad' spam
php admin-cli.php ignore:list
php admin-cli.php ignore:list --orphaned
php admin-cli.php ignore:del <ignoreid>
```
Expected: works as before.

- [ ] **Step 6: Commit**

```bash
git add cli_cmds/ignore_add.php cli_cmds/ignore_del.php cli_cmds/ignore_list.php cli_cmds/ignore_addnetwork.php
git commit -m "refactor(cli): route ignore commands through ConfigService"
```

---

### Task 6: Expand `linktitles:set` with the new keys

**Files:**
- Modify: `scripts/linktitles/cli_cmds/linktitles_set.php`

Add the new keys to the `$settings` list and teach the value-coercion `match` about them. The existing `--network`/`--channel`/`--reset`/`inherit` machinery already handles create/reset.

- [ ] **Step 1: Expand the settings list and coercion**

In `scripts/linktitles/cli_cmds/linktitles_set.php`:

Replace the `$settings` array:

```php
    /** @var array<string> */
    public array $settings = [
        "ai_vision_disabled",
        "enabled",
        "url_log_chan",
        "ai_vision_model",
        "ai_vision_prompt",
        "ai_vision_reasoning_effort",
        "ai_vision_reasoning",
    ];
```

Replace the `match` block (currently only `ai_vision_disabled`) with one covering all keys:

```php
        $val = $input->getArgument("value");
        match ($input->getArgument("setting")) {
            "ai_vision_disabled" => $setting->ai_vision_disabled = filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? throw new \InvalidArgumentException("Value must be true or false"),
            "enabled" => $setting->enabled = filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? throw new \InvalidArgumentException("Value must be true or false"),
            "url_log_chan" => $setting->url_log_chan = (string)$val,
            "ai_vision_model" => $setting->ai_vision_model = (string)$val,
            "ai_vision_prompt" => $setting->ai_vision_prompt = (string)$val,
            "ai_vision_reasoning_effort" => $setting->ai_vision_reasoning_effort = (string)$val,
            "ai_vision_reasoning" => $setting->ai_vision_reasoning = json_decode($val, true) ?? throw new \InvalidArgumentException("Value must be valid JSON"),
        };
```

- [ ] **Step 2: Verify end to end**

```
php admin-cli.php linktitles:set --network <netid> enabled true
php admin-cli.php linktitles:set --network <netid> url_log_chan '#urls'
php admin-cli.php linktitles:set --network <netid> ai_vision_model gpt-4o-mini
php admin-cli.php linktitles:set --network <netid> ai_vision_reasoning_effort low
php admin-cli.php linktitles:set --network <netid> ai_vision_model inherit
```
Expected: each sets/prints the table; `inherit` resets the override (row deleted or nulled).

- [ ] **Step 3: Commit**

```bash
git add scripts/linktitles/cli_cmds/linktitles_set.php
git commit -m "feat(linktitles): expand linktitles:set with enabled/url_log_chan/model/prompt/reasoning"
```

---

### Task 7: Migrate the `linktitles` AI + `url_log_chan` consumers

**Files:**
- Modify: `scripts/linktitles/linktitles.php`

AI config now comes from the AI service (`ServiceLocator`); `model`/`prompt`/`reasoning` come from `linktitles_setting` (`SettingsResolver`); `url_log_chan` comes from `linktitles_setting`. The existing `isAiVisionDisabled()` two-step logic is preserved as-is (different resolution semantics from the new fields).

- [ ] **Step 1: Add imports**

In `scripts/linktitles/linktitles.php`, add with the other `use` statements near the top:

```php
use lolbot\config\ServiceLocator;
use lolbot\config\SettingsResolver;
use lolbot\entities\AiServiceConfig;
use lolbot\entities\Channel;
```

- [ ] **Step 2: Add a channel-name → entity helper**

Add this private method to the `linktitles` class (next to `isAiVisionDisabled`):

```php
    private function channelEntityForChan(string $chan): ?Channel
    {
        foreach ($this->bot->getChannels() as $ch) {
            if (strtolower($ch->name) === strtolower($chan)) {
                return $ch;
            }
        }
        return null;
    }
```

- [ ] **Step 3: Rewrite `logUrl()`'s config read**

In `logUrl()`, replace the body that reads `$config['bots'][...]['url_log_chan']` (the `global $config;` line and the two-line guard/fetch) with a resolver lookup:

```php
    function logUrl(\Irc\Client $bot, string $nick, string $chan, string $line, string|array $title): void
    {
        global $entityManager;
        $resolver = new SettingsResolver($entityManager);
        $logChan = $resolver->urlLogChan($this->network, null);
        if ($logChan === null) {
            return;
        }
        static $max = 0;
        $max = max(strlen($chan), $max);
        // …rest unchanged…
```

(Keep everything from `$chan = str_pad(...)` onward exactly as-is.)

- [ ] **Step 4: Rewrite `getAiDescription()` to use the service + setting**

Change its signature to take `$chan`, and replace the config reads. New signature and top of method (the part from `global $config;` through the `$reasoning` block) become:

```php
    private function getAiDescription(string $body, string $url, string $chan, string &$profile = '', float $dlMs = 0.0): ?string
    {
        global $entityManager;

        $ai = (new ServiceLocator($entityManager))->getServiceConfig('ai');
        if ($ai === null || $ai->apiKey === null || $ai->apiKey === '') {
            return null;
        }

        $setting = (new SettingsResolver($entityManager))->getLinktitlesSetting($this->network, $this->channelEntityForChan($chan));

        try {
            $maxDim = $ai->maxDim;
            $quality = $ai->jpgQuality;
```

(From here, the existing image-resize block `$img = new \Imagick();` … through `$profile .= " resize=…";` stays unchanged — it already uses `$maxDim` and `$quality`.)

Then replace the AI-client / prompt / model / reasoning block (the lines that read `$config['ai_vision_timeout']`, `$config['ai_vision_key']`, `$config['ai_vision_base_url']`, `$config['ai_vision_prompt']`, `$config['ai_vision_model']`, `$config['ai_vision_reasoning']`, `$config['ai_vision_reasoning_effort']`) with:

```php
            $aiStart = hrtime(true);
            $ampClient = HttpClientBuilder::buildDefault();
            $timeout = $ai->timeout;
            $openAiHttp = new OpenAiHttpClient($ai->apiKey, $ampClient, new TimeoutCancellation($timeout));
            $aiClient = new OpenAiClient(
                apiKey: $ai->apiKey,
                baseUrl: $ai->baseUrl ?? 'https://api.openai.com/v1',
                httpClient: $openAiHttp,
            );

            $prompt = $setting?->ai_vision_prompt ?? self::defaultVisionPrompt;
            $model = $setting?->ai_vision_model ?? 'gpt-4o';

            $reasoning = null;
            $reasoningConfig = $setting?->ai_vision_reasoning ?? $ai->reasoning ?? null;
            if ($reasoningConfig !== null) {
                $reasoning = new Reasoning(
                    effort: $reasoningConfig['effort'] ?? null,
                    maxTokens: isset($reasoningConfig['max_tokens']) ? (int)$reasoningConfig['max_tokens'] : null,
                    exclude: isset($reasoningConfig['exclude']) ? (bool)$reasoningConfig['exclude'] : null,
                    enabled: isset($reasoningConfig['enabled']) ? (bool)$reasoningConfig['enabled'] : null,
                );
            } else {
                $effort = $setting?->ai_vision_reasoning_effort ?? $ai->reasoningEffort ?? null;
                if ($effort !== null) {
                    $reasoning = Reasoning::effort($effort);
                }
            }
```

(From `new ChatRequest(...)` onward stays unchanged.)

- [ ] **Step 5: Update the call site**

The single call to `getAiDescription` is `private function … getAiDescription($body, $cacheKey, $profile, $dlMs)`. Add `$chan` as the new third argument:

```php
        $aiDesc = $this->isAiVisionDisabled($chan) ? null : (self::$ai_desc_cache[$cacheKey] ?? $this->getAiDescription($body, $cacheKey, $chan, $profile, $dlMs));
```

- [ ] **Step 6: Remove the now-unused `global $config;` in `getAiDescription` if fully unused**

After the rewrite, `getAiDescription` no longer references `$config`. If the `global $config;` line remains and PHPStan flags it, remove it. (Other methods in the file still use `global $config`, so leave those.)

- [ ] **Step 7: Type-check the touched file**

Run: `vendor/bin/phpstan analyse scripts/linktitles/linktitles.php --no-progress`
Expected: no new errors attributable to these edits (the file has pre-existing baseline errors; only confirm your edits introduce none).

- [ ] **Step 8: Run existing linktitles test**

Run: `vendor/bin/phpunit tests/Linktitles`
Expected: PASS (the `FormatImageResponseTest` doesn't touch AI; confirms nothing structural broke).

- [ ] **Step 9: Commit**

```bash
git add scripts/linktitles/linktitles.php
git commit -m "refactor(linktitles): read AI from ServiceLocator, model/prompt/reasoning/url_log_chan from SettingsResolver"
```

---

### Task 8: Migrate the paste consumers to `ServiceLocator`

**Files:**
- Modify: `scripts/help/help.php`, `artbot_scripts/help.php`, `scripts/alias/alias.php`

`library/paste.php`'s `createPaste($content, $title, $host, $key)` signature is unchanged; callers now fetch `$host`/`$key` from the paste service.

- [ ] **Step 1: `scripts/help/help.php` — `showHelpPaste()`**

Replace the body of `showHelpPaste()`:

```php
    function showHelpPaste(string $chan, \Irc\Client $bot, string $content): void
    {
        global $entityManager;
        $paste = (new \lolbot\config\ServiceLocator($entityManager))->getServiceConfig('paste');
        if ($paste === null || $paste->host === null || $paste->key === null) {
            $bot->msg($chan, "help: paste service not configured");
            return;
        }
        try {
            $url = \createPaste($content, "Bot Commands", $paste->host, $paste->key);
            $bot->msg($chan, "help: $url");
        } catch (\Throwable $e) {
            $bot->msg($chan, "help: trouble creating paste :( " . $e->getMessage());
        }
    }
```

- [ ] **Step 2: `artbot_scripts/help.php` — `showHelpPaste()`**

Replace the body of `showHelpPaste()`:

```php
function showHelpPaste(\Irc\Client $bot, string $chan, string $content): void
{
    global $entityManager;
    $paste = (new \lolbot\config\ServiceLocator($entityManager))->getServiceConfig('paste');
    if ($paste === null || $paste->host === null || $paste->key === null) {
        $bot->msg($chan, "help: paste service not configured");
        return;
    }
    try {
        $url = \createPaste($content, "Bot Commands", $paste->host, $paste->key);
        $bot->msg($chan, "help: $url");
    } catch (\Throwable $e) {
        $bot->msg($chan, "help: trouble creating paste :( " . $e->getMessage());
    }
}
```

- [ ] **Step 3: `scripts/alias/alias.php` — the aliases paste block**

Replace the `$usePaste` guard and `createPaste` call:

```php
        $usePaste = $cmdArgs->optEnabled('--web') || count($aliases) > 20;
        global $entityManager;
        $paste = (new \lolbot\config\ServiceLocator($entityManager))->getServiceConfig('paste');
        if ($usePaste && $paste !== null && $paste->host !== null && $paste->key !== null) {
            try {
                $content = $this->formatAliasesMarkdown($aliases, $args->chan);
                $url = \createPaste($content, "Aliases for {$args->chan}", $paste->host, $paste->key);
                $rpl($url, 'list');
                return;
            } catch (\Throwable $e) {
                echo "Paste error for aliases: " . $e->getMessage() . "\n";
            }
        }
```

- [ ] **Step 4: Type-check the touched files**

Run: `vendor/bin/phpstan analyse scripts/help/help.php artbot_scripts/help.php scripts/alias/alias.php --no-progress`
Expected: no new errors from these edits.

- [ ] **Step 5: Commit**

```bash
git add scripts/help/help.php artbot_scripts/help.php scripts/alias/alias.php
git commit -m "refactor(paste): read paste host/key from ServiceLocator in help/alias consumers"
```

---

### Task 9: `lolbot.php` reads linktitles enable from the DB

**Files:**
- Modify: `lolbot.php`

Resolve linktitles enable once per bot at startup (network scope) via `SettingsResolver`, and use that in the chat handler instead of `$config['bots'][$dbBot->id]['linktitles']`. (Live re-enable waits for Sub-project 2.)

- [ ] **Step 1: Resolve enable in `startBot()`**

In `lolbot.php` `startBot()`, the first statement is `global $config, $logHandler;`. Add `$entityManager` and resolve once:

```php
function startBot(lolbot\entities\Network $network, lolbot\entities\Bot $dbBot): \Irc\Client
{
    global $config, $logHandler, $entityManager;
    $linktitlesEnabled = (new \lolbot\config\SettingsResolver($entityManager))->linktitlesEnabled($network, null);
```

- [ ] **Step 2: Use it in the chat handler**

In the `$client->on('chat', …)` closure's `use (...)` list, add `$linktitlesEnabled`. Then replace the enable check (the `if ($config['bots'][$dbBot->id]['linktitles'] ?? false) {` line) with:

```php
            if ($linktitlesEnabled) {
                async(fn() => $linktitles->linktitles($bot, $args->nick, $args->chan, $args->identhost, $args->text));
            }
```

- [ ] **Step 3: Type-check**

Run: `vendor/bin/phpstan analyse lolbot.php --no-progress`
Expected: no new errors from these edits.

- [ ] **Step 4: Commit**

```bash
git add lolbot.php
git commit -m "refactor(lolbot): read linktitles enable from DB via SettingsResolver"
```

---

### Task 10: `config.yaml` cleanup + final verification

**Files:**
- Modify: `config.yaml`, `config.example.yaml`

Remove the keys that now live in the DB. Keep transitional keys (`codesand*`, `pump_*`, `youtube_*`, `listen`) and not-yet-service global keys.

- [ ] **Step 1: Backfill the real DB from the still-present keys**

Before touching `config.yaml`, run the importer so the current values land in the DB:

Run: `php admin-cli.php config:import`
Expected: reports `imported N value(s)` for the ai/paste/linktitles/url_log_chan values currently in `config.yaml`.

- [ ] **Step 2: Verify services and settings are populated**

```
php admin-cli.php service:get ai
php admin-cli.php service:get paste
php admin-cli.php linktitles:set --network <netid>
```
Expected: AI/paste configs show the imported values; the linktitles settings table reflects imported `enabled`/`url_log_chan`.

- [ ] **Step 3: Remove migrated keys from `config.yaml`**

Now that the DB holds them, remove from `config.yaml`:
- All `ai_vision_*` top-level keys.
- `paste_host`, `paste_key`.
- From the `bots:` block: the `linktitles:` and `url_log_chan:` lines (the rest of each bot's block — `codesand`, `pump_*`, `youtube_*`, `listen` — stays transitional).

- [ ] **Step 4: Update `config.example.yaml` to document the new state**

In `config.example.yaml`:
- Remove the `ai_vision_*` block and the `paste_host`/`paste_key` lines from the global section; add a comment pointing to the DB instead, e.g.:

```yaml
# AI vision service config now lives in the DB:
#   php admin-cli.php service:set ai apiKey 'sk-...'
#   php admin-cli.php service:set ai baseUrl 'https://api.openai.com/v1'
# Per-network/channel linktitles overrides (model, prompt, reasoning, enabled):
#   php admin-cli.php linktitles:set --network <id> ...
# Paste service now in the DB:
#   php admin-cli.php service:set paste host 'http://localhost:8080'
#   php admin-cli.php service:set paste key '...'
```

- In the `bots:` example block, remove `linktitles:` and `url_log_chan:`; keep `codesand`, `youtube_*`, `pump_*`, `listen` with a comment that these are transitional (moving to DB in follow-ups).

- [ ] **Step 5: Full test suite + static analysis on touched paths**

```
vendor/bin/phpunit
vendor/bin/phpstan analyse library/config/ scripts/linktitles/ scripts/help/ scripts/alias/ cli_cmds/ lolbot.php --no-progress
```
Expected: PHPUnit green; PHPStan shows only pre-existing baseline errors (no new ones from this work).

- [ ] **Step 6: Smoke-start the channel bot**

Run: `php lolbot.php` (then SIGINT to stop).
Expected: bot starts, joins configured channels; if a URL is pasted, linktitles behaves as before (title fetch; AI description if AI service is configured and not disabled). Paste-backed commands (`!help` when long, alias listing) work via the paste service.

- [ ] **Step 7: Commit**

```bash
git add config.yaml config.example.yaml
git commit -m "chore(config): retire migrated keys from config.yaml (ai_vision/paste/linktitles now in DB)"
```

---

## Definition of done (Plan 1B)

- All mutating `admin-cli` commands route through `ConfigService`; mutations fire the `ChangeNotifier` seam (no-op now, ready for Sub-project 2).
- `linktitles` reads AI from the AI service and `model`/`prompt`/`reasoning`/`url_log_chan` from `linktitles_setting`.
- `help` (×2) and `alias` read paste from the paste service.
- `lolbot.php` reads linktitles enable from the DB.
- `config.yaml` no longer carries migrated keys; `config:import` has backfilled the DB.
- `vendor/bin/phpunit` green; PHPStan introduces no new errors on touched paths.

This completes **Sub-project 1**. Next: **Sub-project 2** — live config sync (#109): consolidate the notifier to one global listen with per-bot-id routes, implement the HTTP-push `ChangeNotifier` (originating from the mutating client, pushed to each bot's configured notifier port — no registration), and add bot-side hot-apply (join/part, reload settings, spawn/drop connections).

---

## Implementation notes (deviations made during execution)

1. **PHPStan level-9 arg handling in refactored commands.** Symfony `getArgument()`/`getOption()` return `mixed`; the refactored `cli_cmds/*` commands add `is_string`/`is_array`/`(int)`/`(bool)` guards (the `(int)$x` cast on `mixed` trips PHPStan's `cast.int`, so narrow to `string` first). Mirrors the pattern in `service_set.php`. Behavior unchanged.
2. **`server:set` now coerces** `port`→int and `ssl`/`throttle`→bool (was a raw string assignment). Correctness improvement (entity fields are typed); behavior for the existing string settings (`address`/`password`) unchanged.
3. **`ignore:list` pre-existing bug fixed** (`dda0a45`): `InputOption::VALUE_NONE` returns `false` when absent, so the original `!== null` orphaned-filter check was always truthy — `ignore:list` silently showed only orphaned ignores. Now `if ($input->getOption("orphaned"))`. Also cast Ignore to `(string)` in `print_ignores` (`writeln` is `string|iterable`).
4. **`linktitles:set` rendering/validation hardened** (`2e7bcf5`): added `parseReasoningJson` (validates the decoded JSON is an array — a scalar like `5` previously TypeError'd) and `fmtVal` (renders the `ai_vision_reasoning` array as JSON instead of "Array to string conversion").
5. **Paste consumers** narrow `getServiceConfig('paste')` with `instanceof PasteServiceConfig` (returns `?object`).
6. **`linktitles` AI consumer** uses `!$ai instanceof AiServiceConfig` (narrow `?object`), an `if ($setting !== null)` block instead of `?-> ?? fallback` (`nullsafe.neverNull`), and `is_string`/`is_int` guards in the Reasoning build — all PHPStan-driven, behavior-preserving. `logUrl` resolves `url_log_chan` at network scope (matches the original per-bot behavior; channel-scoped url_log_chan is not meaningful).
7. **`config:import` backfills `ai_vision_model`/`ai_vision_prompt`** (`213900f`) — closes a migration gap that could silently lose the configured model on import.
8. **`Bot.php` nullable annotations** fixed in Plan 1A (carried here): `trigger`/`trigger_re`/`sasl_user`/`sasl_pass` → `nullable: true` to match the real migrations and `?string` types.

## Carry-forward to Sub-project 2 (from final review)

- **Route `scripts/linktitles/cli_cmds/linktitles_set.php` through `ConfigService`** so its mutations fire `notify()`. It still uses direct `$entityManager` (so live-sync would miss `linktitles:set` edits). Note its reset/inherit path deletes the *whole* scope row, whereas `ConfigService::resetLinktitlesSetting` nulls a single field — Sub-project 2 should add a `ConfigService::deleteLinktitlesSettingScope(network, channel)` (row delete + notify) and route both set and reset through ConfigService, preserving the row-delete semantics.
- **`SettingsResolver` is linktitles-only** (no legacy `config.yaml` fallback merge). Transitional scripts (codesand/youtube/pump) read `$config['bots'][...]` directly; expand the resolver as each migrates.
- Implement the HTTP-push `ChangeNotifier`, consolidate `listen` to one global route, add bot-side hot-apply.

