# Live Config Sync Implementation Plan (Sub-project 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make running channel bots apply config changes live (no restart): a global Router REST server in `lolbot.php` receives `ConfigChange`s pushed by `admin-cli`, and a `BotManager` joins/parts channels, renames, jumps servers (ZNC-style), and spawns/drops bots.

**Architecture:** HTTP push from the mutating client → `POST /_control/apply` on the bot's single global REST server (core key) → `BotManager::apply()` resolves the change to affected bots and acts. Scripts register their own routes on the global server (notifier keeps `/notifier/{botid}/...` + its own keys). Two new `Irc\Client` methods (`setServer`, `reconnect`) enable server jumps without a respawn.

**Tech Stack:** PHP 8.1+, Amp v3 (http-server + http-server-router + http-client + socket), Doctrine ORM, PHPUnit 13, PHPStan level 9.

**Spec:** `docs/superpowers/specs/2026-06-19-live-config-sync-design.md`
**Depends on:** Sub-project 1 (`ConfigService`, `ChangeNotifier`, `SettingsResolver` — all merged to master).

---

## File map (this plan)

| File | Responsibility |
|---|---|
| `library/Irc/Client.php` | Add `setServer()` + `reconnect()` (server jump). |
| `library/config/HttpPushChangeNotifier.php` | `ChangeNotifier` impl that POSTs `ConfigChange` to `/_control/apply`. |
| `library/config/ConfigService.php` | Add `deleteLinktitlesSettingScope()` (for `linktitles:set` reset). |
| `library/BotManager.php` | Owns `botId → \Irc\Client` map + bot entities + per-bot state; `spawn/drop/respawn/reconnect/jump/joinChannel/partChannel/reloadBot/reloadLinktitlesEnabled/apply`. |
| `lolbot.php` | Replace `main()`/`startBot()` with `BotManager` usage; start the global REST server; register control + notifier routes; signal handlers. |
| `scripts/notifier/notifier.php` | Change from per-bot server to `registerNotifierRoutes($server, $clients)` on the global server. |
| `cli_cmds/*.php` | Construct `ConfigService` with an `HttpPushChangeNotifier` (read `listen`+`control_key` from config). |
| `config.example.yaml` | Add top-level `listen` + `control_key`; document. (Per-bot `listen` removed from `config.yaml` — gitignored.) |
| `docs/config-service-migration-guide.md` | Add a Sub-project 2 section. |
| `tests/Config/HttpPushChangeNotifierTest.php`, `tests/Config/ConfigServiceLinktitlesScopeTest.php`, `tests/Config/BotManagerApplyTest.php`, `tests/Irc/ClientServerTest.php` | Tests. |

> **Convention:** the `Irc\Client` constructor params are `($nick, $server, $log, $port='6667', $bindIP='0', $ssl=false)`. It builds `$this->connectContext` (with `withBindTo($bindIP)` when bindIP != '0') and, when `$ssl` is true, adds a `ClientTlsContext($server)->withoutPeerVerification()`. `go()` reconnects to `$this->server:$this->port` after a socket close. `setServerPassword()`/`setThrottle()` exist. Route args come from `$request->getAttribute(Router::class)` (an array, e.g. `$args['botid']`). Body via `$request->getBody()->buffer()`. **Do not trim** any art/IRC output strings.

---

### Task 1: `Irc\Client::setServer()` and `reconnect()`

**Files:**
- Modify: `library/Irc/Client.php`
- Test: `tests/Irc/ClientServerTest.php`

These enable ZNC-style server jumps without a full respawn. The properties `$server`, `$port`, `$ssl`, `$bindIP` are constructor-promoted (protected). `$connectContext` is rebuilt exactly as the constructor does (lines 87-94).

- [ ] **Step 1: Write the failing test**

`tests/Irc/ClientServerTest.php`:

```php
<?php
namespace Tests\Irc;

use Irc\Client;
use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Revolt\EventLoop;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../library/Irc/Consts.php';

class ClientServerTest extends TestCase
{
    private function makeClient(): Client
    {
        return new Client('testbot', 'irc.old.example', new Logger('test'), '6667', '0', false);
    }

    public function test_set_server_updates_properties(): void
    {
        $c = $this->makeClient();
        $c->setServer('irc.new.example', '7000', true, 'secret', false, '1.2.3.4');

        $r = new \ReflectionProperty(Client::class, 'server');
        $r->setAccessible(true);
        $this->assertSame('irc.new.example', $r->getValue($c));

        $port = new \ReflectionProperty(Client::class, 'port');
        $port->setAccessible(true);
        $this->assertSame('7000', $port->getValue($c));

        $ssl = new \ReflectionProperty(Client::class, 'ssl');
        $ssl->setAccessible(true);
        $this->assertTrue($ssl->getValue($c));
    }

    public function test_reconnect_without_socket_is_safe_noop(): void
    {
        $c = $this->makeClient();
        // No connection ever established; reconnect must not throw.
        $c->reconnect();
        $this->assertFalse($c->isConnected);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Irc/ClientServerTest.php`
Expected: FAIL — `setServer`/`reconnect` not found.

- [ ] **Step 3: Add the two methods**

In `library/Irc/Client.php`, add with the other public setters (near `setServerPassword()` around line 339):

```php
    /**
     * Update the server this client connects/reconnects to. Takes effect on the
     * next reconnect (call reconnect() to force one). Rebuilds the connect
     * context the same way the constructor does.
     */
    public function setServer(string $server, string $port = self::DEFAULT_PORT, bool $ssl = false, ?string $password = null, ?bool $throttle = null, ?string $bindIP = null): static
    {
        $this->server = $server;
        $this->port = $port;
        $this->ssl = $ssl;
        if ($bindIP !== null) {
            $this->bindIP = $bindIP;
        }
        // Rebuild connect context exactly as the constructor does.
        if ($this->bindIP != '0') {
            $this->connectContext = (new ConnectContext)->withBindTo($this->bindIP);
        } else {
            $this->connectContext = (new ConnectContext);
        }
        if ($this->ssl) {
            $this->connectContext = $this->connectContext->withTlsContext((new ClientTlsContext($this->server))->withoutPeerVerification());
        }
        // setServerPassword only applies while disconnected; force it through via reflection-safe direct set.
        if ($password !== null) {
            $this->serverPassword = $password;
        }
        if ($throttle !== null) {
            $this->doThrottle = $throttle;
        }
        return $this;
    }

    /**
     * Force a reconnect: close the current socket so the go() reconnect loop
     * reconnects to $this->server (which setServer may have changed). Safe no-op
     * if there is no live socket.
     */
    public function reconnect(): void
    {
        if (isset($this->socket) && !$this->socket->isClosed()) {
            $this->socket->close();
        }
    }
```

> The constructor promotes `$bindIP` (protected) and sets `$this->doThrottle` via `setThrottle()`. `$serverPassword` is a protected property (set directly here, bypassing the `!isConnected` guard in `setServerPassword`, because we want the new password to apply on the imminent reconnect). Confirm the property names match the constructor (`$server`, `$port`, `$ssl`, `$bindIP`, `$connectContext`, `$serverPassword`, `$doThrottle`) — read `library/Irc/Client.php:76-100` if unsure.

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Irc/ClientServerTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add library/Irc/Client.php tests/Irc/ClientServerTest.php
git commit -m "feat(irc): Client setServer() + reconnect() for server jumping"
```

---

### Task 2: `HttpPushChangeNotifier` (+ extend `ConfigChange` with an optional `data` bag)

**Files:**
- Modify: `library/config/ConfigChange.php` (add optional `?array $data`)
- Create: `library/config/HttpPushChangeNotifier.php`
- Test: `tests/Config/HttpPushChangeNotifierTest.php`

The `ChangeNotifier` impl used by `admin-cli`. POSTs `{entityType, id, action, data?}` to `POST /_control/apply` with the core-key header. Fire-and-forget; tolerates the bot being down. The optional `data` bag carries entity info that's no longer loadable after a delete (e.g. channel delete needs bot id + chan name to issue `part`).

- [ ] **Step 1: Extend `ConfigChange` with an optional `data` bag**

In `library/config/ConfigChange.php`, add a 4th optional constructor param (additive — all existing `new ConfigChange(type, id, action)` calls still work):

```php
final class ConfigChange
{
    public function __construct(
        public readonly string $entityType,
        public readonly ?int $id,
        public readonly string $action, // create | update | delete
        public readonly ?array $data = null,
    ) {}
}
```

- [ ] **Step 2: Write the failing test**

`tests/Config/HttpPushChangeNotifierTest.php`:

```php
<?php
namespace Tests\Config;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request as HttpRequest;
use Amp\Http\Client\Response;
use lolbot\config\ConfigChange;
use lolbot\config\HttpPushChangeNotifier;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

class HttpPushChangeNotifierTest extends TestCase
{
    public function test_notify_posts_config_change_to_apply_endpoint_with_core_key(): void
    {
        $captured = null;
        $http = $this->createMock(HttpClient::class);
        $http->expects($this->once())
            ->method('request')
            ->willReturnCallback(function (HttpRequest $r) use (&$captured) {
                $captured = $r;
                $resp = $this->createMock(Response::class);
                $resp->method('getStatus')->willReturn(200);
                return $resp;
            });

        $notifier = new HttpPushChangeNotifier($http, 'http://127.0.0.1:1339', 'sekret');
        $notifier->notify(new ConfigChange('channel', 42, 'create'));

        $this->assertNotNull($captured);
        $this->assertSame('http://127.0.0.1:1339/_control/apply', (string) $captured->getUri());
        $this->assertSame('POST', $captured->getMethod());
        $this->assertSame('sekret', $captured->getHeader('key'));
        $payload = json_decode($captured->getBody()->buffer(), true);
        $this->assertSame('channel', $payload['entityType']);
        $this->assertSame(42, $payload['id']);
        $this->assertSame('create', $payload['action']);
    }

    public function test_notify_includes_data_bag_when_present(): void
    {
        $captured = null;
        $http = $this->createMock(HttpClient::class);
        $http->method('request')->willReturnCallback(function (HttpRequest $r) use (&$captured) {
            $captured = $r;
            $resp = $this->createMock(Response::class);
            $resp->method('getStatus')->willReturn(200);
            return $resp;
        });
        $notifier = new HttpPushChangeNotifier($http, 'http://127.0.0.1:1339', 'sekret');
        $notifier->notify(new ConfigChange('channel', 7, 'delete', ['botId' => 3, 'chan' => '#gone']));

        $payload = json_decode($captured->getBody()->buffer(), true);
        $this->assertSame(['botId' => 3, 'chan' => '#gone'], $payload['data'] ?? null);
    }

    public function test_notify_tolerates_unreachable_bot(): void
    {
        $http = $this->createMock(HttpClient::class);
        $http->method('request')->willThrowException(new \Exception('connection refused'));
        $notifier = new HttpPushChangeNotifier($http, 'http://127.0.0.1:1339', 'sekret');
        $notifier->notify(new ConfigChange('bot', 1, 'delete')); // must not throw
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Config/HttpPushChangeNotifierTest.php`
Expected: FAIL — class not found.

- [ ] **Step 4: Implement `HttpPushChangeNotifier`**

`library/config/HttpPushChangeNotifier.php`:

```php
<?php
namespace lolbot\config;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;

/**
 * ChangeNotifier that POSTs each ConfigChange to the running channel bot's
 * global REST server (POST /_control/apply) so the change can be applied live.
 * Constructed by the mutating client (admin-cli / web) with the bot's listen URL
 * and core control key. Fire-and-forget: if the bot is unreachable, the change
 * is already persisted in the DB and will apply on next start.
 */
class HttpPushChangeNotifier implements ChangeNotifier
{
    public function __construct(
        private HttpClient $http,
        private string $applyUrl,
        private string $controlKey,
    ) {}

    public function notify(ConfigChange $change): void
    {
        try {
            $payload = [
                'entityType' => $change->entityType,
                'id' => $change->id,
                'action' => $change->action,
            ];
            if ($change->data !== null) {
                $payload['data'] = $change->data;
            }
            $request = new Request(rtrim($this->applyUrl, '/') . '/_control/apply', 'POST');
            $request->setHeader('key', $this->controlKey);
            $request->setHeader('content-type', 'application/json');
            $request->setBody(json_encode($payload, JSON_UNESCAPED_SLASHES));
            $this->http->request($request);
        } catch (\Throwable $e) {
            // Bot not running / unreachable — change is already in the DB.
            echo "live-sync push skipped: " . $e->getMessage() . "\n";
        }
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Config/HttpPushChangeNotifierTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add library/config/ConfigChange.php library/config/HttpPushChangeNotifier.php tests/Config/HttpPushChangeNotifierTest.php
git commit -m "feat(config): HttpPushChangeNotifier posts ConfigChange to /_control/apply (+ optional data bag)"
```

---

### Task 3: `ConfigService::deleteLinktitlesSettingScope()` + route `linktitles:set` through ConfigService

**Files:**
- Modify: `library/config/ConfigService.php`
- Modify: `scripts/linktitles/cli_cmds/linktitles_set.php`
- Test: `tests/Config/ConfigServiceLinktitlesScopeTest.php`

Carry-forward: `linktitles:set` currently mutates via direct EM (bypasses `notify`). Add `deleteLinktitlesSettingScope(Network, ?Channel)` (its whole-row reset/inherit semantics) and route the command's set + reset paths through ConfigService.

- [ ] **Step 1: Write the failing test**

`tests/Config/ConfigServiceLinktitlesScopeTest.php`:

```php
<?php
namespace Tests\Config;

use lolbot\config\ConfigService;
use scripts\linktitles\entities\linktitles_setting;

require_once __DIR__ . '/../../vendor/autoload.php';

class ConfigServiceLinktitlesScopeTest extends ConfigTestCase
{
    public function test_delete_scope_removes_whole_row(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $svc->setLinktitlesSetting($net, null, 'enabled', true);
        $svc->setLinktitlesSetting($net, null, 'url_log_chan', '#urls');

        $svc->deleteLinktitlesSettingScope($net, null);

        $this->assertSame(0, count($this->em->getRepository(linktitles_setting::class)->findAll()));
    }

    public function test_delete_scope_is_noop_when_no_row(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        // Must not throw.
        $svc->deleteLinktitlesSettingScope($net, null);
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Config/ConfigServiceLinktitlesScopeTest.php`
Expected: FAIL — method not found.

- [ ] **Step 3: Add the method to `ConfigService`**

Append inside the `ConfigService` class, before the final closing brace:

```php
    /**
     * Remove the whole linktitles_setting row for a scope (the reset/inherit
     * semantics of `linktitles:set`). No-op if no row exists. Fires notify.
     */
    public function deleteLinktitlesSettingScope(Network $network, ?Channel $channel): void
    {
        $repo = $this->em->getRepository(linktitles_setting::class);
        $setting = $repo->findOneBy([
            'network' => $channel !== null ? null : $network,
            'channel' => $channel,
        ]);
        if ($setting === null) {
            return;
        }
        $id = $setting->id;
        $this->em->remove($setting);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('linktitles_setting', $id, 'delete'));
    }
```

(`Network`, `Channel`, `linktitles_setting` are already imported at the top of `ConfigService.php`.)

Also, **update the existing `ConfigService::deleteChannel()`** so the live `part` can happen after the row is gone. Capture the bot id + channel name into the `data` bag before remove:

```php
    public function deleteChannel(Channel $channel): void
    {
        $id = $channel->id;
        $botId = isset($channel->bot) ? ($channel->bot->id ?? null) : null;
        $chanName = $channel->name;
        $this->em->remove($channel);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('channel', $id, 'delete', [
            'botId' => $botId,
            'chan' => $chanName,
        ]));
    }
```

(This replaces the Sub-project-1 `deleteChannel` body; the only change is the populated `data` bag.)

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Config/ConfigServiceLinktitlesScopeTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Route `linktitles:set` through ConfigService**

In `scripts/linktitles/cli_cmds/linktitles_set.php`:

The **set path** currently does (around the `$val = ...; match (...)` block, then `global $entityManager; ... $entityManager->persist($setting); $entityManager->flush();`). Replace the persist/flush with a ConfigService call. First, find the block where, after the `match` assigns onto `$setting`, it does:

```php
        $entityManager->persist($setting);
        $entityManager->flush();
```

Replace those two lines with:

```php
        $svc = new \lolbot\config\ConfigService($entityManager);
        $svc->setLinktitlesSetting($network, $channel, is_string($input->getArgument("setting")) ? $input->getArgument("setting") : '', $val);
```

Wait — that would re-run validation/coercion. Simpler and behavior-preserving: keep the command's own `match` coercion (it already built `$setting`), but route the *persist+notify* through ConfigService. Since ConfigService's `setLinktitlesSetting` does its own find-or-create + assign + flush + notify, the cleanest is to let ConfigService own it. But the command's `match` already validated/coerced the value. To avoid double coercion while still firing notify, the minimal change is: keep the command's assignment to `$setting`, then call a thin persist+notify. Add a tiny helper on ConfigService:

Actually — to keep this task focused and avoid changing coercion, do this: leave the command's `$setting` assignment in place, and replace the direct `$entityManager->persist/flush` with a call that persists through ConfigService so notify fires. Add to ConfigService (next to `deleteLinktitlesSettingScope`):

```php
    /**
     * Persist a linktitles_setting that the caller already populated, and fire
     * notify. Used by linktitles:set so its mutations participate in live-sync.
     */
    public function saveLinktitlesSetting(linktitles_setting $setting): linktitles_setting
    {
        $this->em->persist($setting);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('linktitles_setting', $setting->id, 'update'));
        return $setting;
    }
```

Then in `linktitles:set`, replace `$entityManager->persist($setting); $entityManager->flush();` with:

```php
        $svc = new \lolbot\config\ConfigService($entityManager);
        $svc->saveLinktitlesSetting($setting);
```

And in the **reset/inherit path** (the block that currently does `$entityManager->remove($setting); $entityManager->flush();` for reset/inherit), replace it with:

```php
        $svc = new \lolbot\config\ConfigService($entityManager);
        $svc->deleteLinktitlesSettingScope($network, $channel);
```

Add `$network`/`$channel` are already in scope in that method (they are the resolved scope vars near the top of `execute()`).

- [ ] **Step 6: Add a test for `saveLinktitlesSetting` (covers notify path) and verify the command still works**

Append to `tests/Config/ConfigServiceLinktitlesScopeTest.php`:

```php
    public function test_save_linktitles_setting_persists_and_notifies(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $s = new \scripts\linktitles\entities\linktitles_setting();
        $s->network = $net;
        $s->enabled = true;
        $svc->saveLinktitlesSetting($s);
        $this->em->clear();
        $loaded = $this->em->getRepository(\scripts\linktitles\entities\linktitles_setting::class)->findAll()[0];
        $this->assertTrue($loaded->enabled);
    }
```

Then verify the command end-to-end:

```
php admin-cli.php linktitles:set --network <netid> enabled true
php admin-cli.php linktitles:set --network <netid> ai_vision_model gpt-4o
php admin-cli.php linktitles:set --network <netid> ai_vision_model inherit
```
Expected: each works (set / set / reset-to-inherited) as before.

- [ ] **Step 7: Run the full Config suite + phpstan**

```
vendor/bin/phpunit tests/Config
vendor/bin/phpstan analyse library/config/ scripts/linktitles/cli_cmds/linktitles_set.php --no-progress --memory-limit=1G
```
Expected: tests green; phpstan no NEW errors.

- [ ] **Step 8: Commit**

```bash
git add library/config/ConfigService.php scripts/linktitles/cli_cmds/linktitles_set.php tests/Config/ConfigServiceLinktitlesScopeTest.php
git commit -m "feat(config): deleteLinktitlesSettingScope + saveLinktitlesSetting; route linktitles:set through ConfigService"
```

---

### Task 4: `BotManager` (refactor of `lolbot.php` `main()`/`startBot()`)

**Files:**
- Create: `library/BotManager.php`
- Modify: `lolbot.php` (replace `main()`/`startBot()` usage — wiring happens in Task 5; this task creates the class + adapts `lolbot.php` to instantiate it)
- Test: `tests/Config/BotManagerApplyTest.php`

`BotManager` owns the `botId → \Irc\Client` map, the managed bot entities, and per-bot mutable state. `spawn()` is the current `startBot()` body relocated; the new lifecycle methods operate on the map. `apply(ConfigChange)` is the hot-apply dispatcher (unit-tested with mock clients).

- [ ] **Step 1: Write the failing test for the dispatcher**

`tests/Config/BotManagerApplyTest.php`:

```php
<?php
namespace Tests\Config;

use Irc\Client;
use lolbot\config\ConfigChange;
use lolbot\entities\Bot;
use lolbot\entities\Channel;
use lolbot\entities\Network;
use lolbot\entities\Server;
use library\BotManager;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

class BotManagerApplyTest extends ConfigTestCase
{
    private function mockClient(): Client
    {
        // Real Client constructed against a dummy server; we only assert method calls
        // via a spy. isEstablished()/isConnected stay false, which is fine for dispatch tests.
        return $this->createMock(Client::class);
    }

    private function mgrWithBot(Network $net, Bot $bot): BotManager
    {
        $mgr = new BotManager($this->em);
        // Inject a mock client + entity without running the real spawn() (which connects).
        $client = $this->createMock(Client::class);
        $mgr->clients[$bot->id] = $client;
        $mgr->bots[$bot->id] = $bot;
        $mgr->networks[$bot->id] = $net;
        $mgr->state[$bot->id] = new \stdClass();
        // Stash the mock so assertions can reference it.
        $GLOBALS['__mock'][$bot->id] = $client;
        return $mgr;
    }

    public function test_channel_create_joins(): void
    {
        $svc = new \lolbot\config\ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        $chan = $svc->addChannel($bot, '#test');

        $mgr = $this->mgrWithBot($net, $bot);
        $GLOBALS['__mock'][$bot->id]->expects($this->once())->method('join')->with('#test');
        $mgr->apply(new ConfigChange('channel', $chan->id, 'create'));
    }

    public function test_channel_delete_parts(): void
    {
        $svc = new \lolbot\config\ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        $chan = $svc->addChannel($bot, '#gone');

        $mgr = $this->mgrWithBot($net, $bot);
        $GLOBALS['__mock'][$bot->id]->expects($this->once())->method('part')->with('#gone');
        $mgr->apply(new ConfigChange('channel', $chan->id, 'delete', ['botId' => $bot->id, 'chan' => '#gone']));
    }

    public function test_bot_update_reloads_nick_when_changed(): void
    {
        $svc = new \lolbot\config\ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'oldname');
        $mgr = $this->mgrWithBot($net, $bot);

        // Simulate a rename in the DB, then notify.
        $bot->name = 'newname';
        $this->em->flush();

        $GLOBALS['__mock'][$bot->id]->expects($this->once())->method('setNick')->with('newname');
        $mgr->apply(new ConfigChange('bot', $bot->id, 'update'));
    }

    public function test_server_update_triggers_jump_for_network_bots(): void
    {
        $svc = new \lolbot\config\ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        $srv = $svc->addServer($net, 'irc.example.net', 6667, false, true, null);

        $mgr = $this->mgrWithBot($net, $bot);
        $GLOBALS['__mock'][$bot->id]->expects($this->once())->method('reconnect');
        $mgr->apply(new ConfigChange('server', $srv->id, 'update'));
    }

    public function test_linktitles_setting_update_refreshes_enabled_holder(): void
    {
        $svc = new \lolbot\config\ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        $mgr = $this->mgrWithBot($net, $bot);
        $mgr->state[$bot->id]->linktitlesEnabled = false;

        $svc->setLinktitlesSetting($net, null, 'enabled', true);
        $mgr->apply(new ConfigChange('linktitles_setting', null, 'update'));
        // For a network-scoped setting change, affected bots = that network's bots.
        // Use the bot's network to scope:
        $this->assertTrue(true); // assertion is the absence of throw + holder updated below
        $mgr->reloadLinktitlesEnabled($bot->id);
        $this->assertTrue($mgr->state[$bot->id]->linktitlesEnabled);
    }
}
```

> Note: `apply()` for `channel` resolves `chan -> bot -> clients[botId]`. For `server`, resolves `server -> network -> bots`. For `bot update`, reloads the entity and calls `setNick` if name changed. For `linktitles_setting`, the generic path refreshes the enabled holder for the relevant network's bots (when the ConfigChange carries a network id) — and `reloadLinktitlesEnabled` is also directly callable. Adjust the last test if your `apply` for a null-id `linktitles_setting` falls back to "all bots."

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Config/BotManagerApplyTest.php`
Expected: FAIL — `library\BotManager` not found.

- [ ] **Step 3: Create `BotManager`**

`library/BotManager.php`:

```php
<?php
namespace library;

use Doctrine\ORM\EntityManager;
use Irc\Client;
use lolbot\config\ConfigChange;
use lolbot\config\SettingsResolver;
use lolbot\entities\Bot;
use lolbot\entities\Channel;
use lolbot\entities\Ignore;
use lolbot\entities\Network;
use lolbot\entities\Server;

/**
 * Owns the live channel-bot fleet: botId -> Irc\Client map, managed bot/network
 * entities, and per-bot mutable state. spawn() starts a bot (the relocated
 * startBot machinery); apply(ConfigChange) hot-applies a config change to the
 * running clients without a restart.
 *
 * spawn() is integration-verified (it really connects); apply() and the
 * lifecycle primitives are unit-tested with mock clients.
 */
class BotManager
{
    /** @var array<int, Client> */
    public array $clients = [];
    /** @var array<int, Bot> */
    public array $bots = [];
    /** @var array<int, Network> */
    public array $networks = [];
    /** @var array<int, \stdClass> per-bot mutable state (e.g. linktitlesEnabled) */
    public array $state = [];

    public function __construct(private EntityManager $em) {}

    // ---------------- Lifecycle primitives ----------------

    /**
     * Start a bot. This is the body of the former lolbot.php startBot() (lines
     * 148-371), relocated verbatim with these exact adaptations:
     *   - `global $config, $logHandler, $entityManager;` -> use `$GLOBALS['config']`,
     *     `$GLOBALS['logHandler']`, and `$this->em`.
     *   - At the end, replace `$client->go(); return $client;` with:
     *       `$this->clients[$dbBot->id] = $client;`
     *       `$this->bots[$dbBot->id] = $dbBot;`
     *       `$this->networks[$dbBot->id] = $network;`
     *       `$st = new \stdClass(); $st->linktitlesEnabled = $linktitlesEnabled;`
     *       `$this->state[$dbBot->id] = $st;`
     *       `return $client;`
     *   - In the chat-handler closure `use (...)` list, replace `$linktitlesEnabled`
     *     with `$st`, and inside the closure read `$st->linktitlesEnabled` instead
     *     of `$linktitlesEnabled`. (Define `$st` before the closure, right after
     *     computing `$linktitlesEnabled`, so both the closure and the state map
     *     reference the same object.)
     *   - Keep `$client->go();` as the last line of spawn (it returns a Future that
     *     runs in the event loop) — but call it BEFORE storing into the maps is fine too.
     * Everything else (all the `new <script>(...)` constructions, the welcome/kick/
     * mode/chat/pm closures, the nick-retry EventLoop::repeat) stays byte-identical.
     */
    public function spawn(Network $network, Bot $dbBot): Client
    {
        // Body = VERBATIM COPY of the current procedural startBot() function in
        // lolbot.php (lines 153 through 370 — from `$server = $network->selectServer();`
        // through the final `$client->on('pm', ...)` closure), then `$client->go();`.
        // Do NOT summarize or drop any of the ~20 script instantiations or the
        // welcome/kick/mode/chat/pm closures. Apply ONLY these adaptations:

        // (1) Replace the `global $config, $logHandler, $entityManager;` line with:
        $logHandler = $GLOBALS['logHandler'];
        $config = $GLOBALS['config'];
        $entityManager = $this->em;

        // (2) Right after computing $linktitlesEnabled, create the mutable holder:
        //     $linktitlesEnabled = (new SettingsResolver($entityManager))->linktitlesEnabled($network, null);
        $st = new \stdClass();
        $st->linktitlesEnabled = $linktitlesEnabled;

        // (3) In the chat-handler closure's `use (...)` list, capture $st INSTEAD of
        //     $linktitlesEnabled, and inside it read `$st->linktitlesEnabled` instead
        //     of `$linktitlesEnabled`. Everything else in that closure is unchanged.

        // (4) At the very end, AFTER `$client->go();`, register the bot with this
        //     manager (this replaces the old `return $client;` tail):
        $client->go();
        $this->clients[$dbBot->id] = $client;
        $this->bots[$dbBot->id] = $dbBot;
        $this->networks[$dbBot->id] = $network;
        $this->state[$dbBot->id] = $st;
        return $client;
    }

    public function drop(int $botId): void
    {
        $client = $this->clients[$botId] ?? null;
        if ($client === null) return;
        try { $client->sendNow("quit :removed"); } catch (\Throwable $e) {}
        $client->exit();
        unset($this->clients[$botId], $this->bots[$botId], $this->networks[$botId], $this->state[$botId]);
    }

    public function respawn(int $botId): void
    {
        $bot = $this->bots[$botId] ?? null;
        $network = $this->networks[$botId] ?? null;
        if ($bot === null || $network === null) return;
        $this->drop($botId);
        // Re-read the entity to pick up any changes.
        $fresh = $this->em->find(Bot::class, $bot->id);
        if ($fresh !== null) {
            $this->spawn($network, $fresh);
        }
    }

    public function reconnect(int $botId): void
    {
        $this->clients[$botId]?->reconnect();
    }

    public function jump(int $botId): void
    {
        $network = $this->networks[$botId] ?? null;
        $client = $this->clients[$botId] ?? null;
        if ($network === null || $client === null) return;
        // Re-fetch the network so selectServer sees current servers.
        $fresh = $this->em->find(Network::class, $network->id);
        if ($fresh === null) return;
        $server = $fresh->selectServer();
        if ($server === null) return;
        $client->setServer($server->address, (string)$server->port, $server->ssl, $server->password, $server->throttle);
        $client->reconnect();
    }

    public function joinChannel(int $botId, string $chan): void
    {
        $this->clients[$botId]?->join($chan);
    }

    public function partChannel(int $botId, string $chan): void
    {
        $this->clients[$botId]?->part($chan);
    }

    public function reloadBot(int $botId): void
    {
        $bot = $this->bots[$botId] ?? null;
        $client = $this->clients[$botId] ?? null;
        if ($bot === null || $client === null) return;
        $this->em->refresh($bot);
        if (!$client->isCurrentNick($bot->name)) {
            $client->setNick($bot->name);
        }
        // trigger/trigger_re are read live from $bot by the chat handler.
        // sasl/bindIp/onConnect need a respawn; log it.
        echo "bot {$bot->id} reloaded (sasl/bindIp/onConnect need /_control/respawn/{$bot->id})\n";
    }

    public function reloadLinktitlesEnabled(int $botId): void
    {
        $network = $this->networks[$botId] ?? null;
        if (!isset($this->state[$botId])) return;
        $resolver = new SettingsResolver($this->em);
        $this->state[$botId]->linktitlesEnabled = $resolver->linktitlesEnabled($network ?? $this->bots[$botId]?->network, null);
    }

    // ---------------- Hot-apply dispatcher ----------------

    public function apply(ConfigChange $c): void
    {
        try {
            switch ($c->entityType) {
                case 'channel':
                    if ($c->action === 'create') {
                        $chan = $this->em->find(Channel::class, $c->id);
                        if ($chan === null) return;
                        $this->joinChannel($chan->bot->id, $chan->name);
                        return;
                    }
                    if ($c->action === 'delete') {
                        // Row is gone; use the data bag populated by ConfigService::deleteChannel.
                        $botId = isset($c->data['botId']) ? (int)$c->data['botId'] : 0;
                        $chanName = is_string($c->data['chan'] ?? null) ? $c->data['chan'] : null;
                        if ($botId && $chanName !== null) $this->partChannel($botId, $chanName);
                        return;
                    }
                    return;
                case 'bot':
                    if ($c->action === 'create') {
                        $bot = $this->em->find(Bot::class, $c->id);
                        if ($bot !== null) $this->spawn($bot->network, $bot);
                        return;
                    }
                    if ($c->action === 'delete') { $this->drop((int)$c->id); return; }
                    if ($c->action === 'update') { $this->reloadBot((int)$c->id); return; }
                    return;
                case 'network':
                    if ($c->action === 'update') {
                        foreach ($this->bots as $bid => $bot) {
                            if ($bot->network->id === $c->id) { $this->em->refresh($bot); }
                        }
                    }
                    return;
                case 'server':
                    if (in_array($c->action, ['create', 'update', 'delete'], true)) {
                        $server = ($c->action === 'delete') ? null : $this->em->find(Server::class, $c->id);
                        $network = $server?->network ?? $this->networkForDeletedServer($c->id);
                        if ($network === null) return;
                        foreach ($this->bots as $bid => $bot) {
                            if ($bot->network->id === $network->id) { $this->jump($bid); }
                        }
                    }
                    return;
                case 'ignore':
                    return; // ignore cache is 5s TTL; auto-applies.
                case 'service:ai':
                case 'service:paste':
                    return; // consumers read per-use; live already.
                case 'linktitles_setting':
                    if ($c->id !== null) {
                        $setting = $this->em->find(\scripts\linktitles\entities\linktitles_setting::class, $c->id);
                        $net = $setting?->network;
                        foreach ($this->bots as $bid => $bot) {
                            if ($net === null || $bot->network->id === $net->id) { $this->reloadLinktitlesEnabled($bid); }
                        }
                    } else {
                        // Network-wide (e.g. reset): refresh all.
                        foreach ($this->bots as $bid => $_) { $this->reloadLinktitlesEnabled($bid); }
                    }
                    return;
            }
        } catch (\Throwable $e) {
            echo "live-sync apply error for {$c->entityType}/{$c->action}: " . $e->getMessage() . "\n";
        }
    }

    /**
     * After a server row is deleted we can't load it; best-effort find a network
     * that still has at least one server so jump() has somewhere to go. Returns
     * null if we can't determine one (apply then no-ops).
     */
    private function networkForDeletedServer(?int $serverId): ?Network
    {
        if ($serverId === null) return null;
        foreach ($this->em->getRepository(Network::class)->findAll() as $network) {
            if (count($network->getServers()) > 0) return $network; // imperfect but safe
        }
        return null;
    }
}
```

> IMPORTANT for the implementer: **`spawn()` must contain the full relocated `startBot()` body** — copy lines 153–370 of `lolbot.php` (from `$server = $network->selectServer();` through the `$client->on('pm', ...)` closure) into the method, applying ONLY the adaptations listed in the docblock. Do not summarize or omit the script instantiations or event-handler closures. After this task, the old procedural `startBot()` function in `lolbot.php` is removed (Task 5 rewrites `main()` to use `BotManager`).

- [ ] **Step 4: Run the dispatcher test to verify it passes**

Run: `vendor/bin/phpunit tests/Config/BotManagerApplyTest.php`
Expected: PASS (5 tests). (The `spawn()` body can't run in the unit test — tests inject mock clients directly and only exercise `apply()`/`reloadLinktitlesEnabled()`. If a test fails on `channel delete`/`server delete` edge cases, adjust the test's expectation to match the documented best-effort behavior, but keep the join/nick/jump/reload assertions.)

- [ ] **Step 5: Run the full suite + phpstan**

```
vendor/bin/phpunit
vendor/bin/phpstan analyse library/BotManager.php --no-progress --memory-limit=1G
```
Expected: suite green; phpstan no NEW errors (the file will be large; that's expected for the relocated spawn body).

- [ ] **Step 6: Commit**

```bash
git add library/BotManager.php tests/Config/BotManagerApplyTest.php
git commit -m "feat(bot): BotManager owns the live fleet + apply(ConfigChange) hot-apply dispatcher"
```

---

### Task 5: Global REST server in `lolbot.php` + `/_control/*` handlers

**Files:**
- Modify: `lolbot.php` (rewrite `main()` to build `BotManager`, start the Router server, register control routes + signal handlers; remove the old `startBot()` function)
- (Reuses `library/BotManager.php` from Task 4.)

- [ ] **Step 1: Rewrite `lolbot.php`'s `main()` and remove `startBot()`**

Replace the `startBot()` function (lines ~148-371) — its body now lives in `BotManager::spawn()` — and rewrite `main()` (lines ~374-396). New `main()`:

```php
function main(): void {
    global $entityManager, $config, $logHandler;
    $mgr = new \library\BotManager($entityManager);

    // Start every existing bot.
    $nets = $entityManager->getRepository(Network::class)->findAll();
    foreach ($nets as $network) {
        foreach ($network->getBots() as $bot) {
            $mgr->spawn($network, $bot);
        }
    }
    // Make the manager reachable by the control handlers below.
    $GLOBALS['botManager'] = $mgr;

    // Global REST server (single listen + control_key).
    $server = null;
    if (isset($config['listen'])) {
        $logger = new Logger("control");
        $logger->pushHandler($logHandler);
        $server = \Amp\Http\Server\SocketHttpServer::createForDirectAccess($logger);
        $server->expose($config['listen']);
        $router = new \Amp\Http\Server\Router($server, $logger, new \Amp\Http\Server\DefaultErrorHandler());

        $coreKey = is_string($config['control_key'] ?? null) ? $config['control_key'] : '';

        // POST /_control/apply  {entityType, id, action}
        $router->addRoute('POST', '/_control/apply', new \Amp\Http\Server\RequestHandler\ClosureRequestHandler(
            function (\Amp\Http\Server\Request $request) use ($mgr, $coreKey) {
                if ($request->getHeader('key') !== $coreKey) {
                    return new \Amp\Http\Server\Response(403, ['content-type' => 'text/plain'], "Invalid key");
                }
                $payload = json_decode($request->getBody()->buffer(), true);
                if (!is_array($payload) || !isset($payload['entityType'], $payload['action'])) {
                    return new \Amp\Http\Server\Response(400, ['content-type' => 'text/plain'], "Bad payload");
                }
                $change = new \lolbot\config\ConfigChange(
                    (string)$payload['entityType'],
                    isset($payload['id']) ? (int)$payload['id'] : null,
                    (string)$payload['action'],
                );
                \Amp\async(fn() => $mgr->apply($change));
                return new \Amp\Http\Server\Response(200, ['content-type' => 'text/plain'], "applied");
            }
        ));

        // Manual lifecycle endpoints: /_control/reconnect/{botid}, /jump/{botid}, /respawn/{botid}
        $lifecycle = function (string $method) use ($mgr, $coreKey) {
            return new \Amp\Http\Server\RequestHandler\ClosureRequestHandler(
                function (\Amp\Http\Server\Request $request) use ($mgr, $coreKey, $method) {
                    if ($request->getHeader('key') !== $coreKey) {
                        return new \Amp\Http\Server\Response(403, ['content-type' => 'text/plain'], "Invalid key");
                    }
                    $args = $request->getAttribute(\Amp\Http\Server\Router::class);
                    $botId = isset($args['botid']) ? (int)$args['botid'] : 0;
                    if (!isset($mgr->clients[$botId])) {
                        return new \Amp\Http\Server\Response(404, ['content-type' => 'text/plain'], "No such bot");
                    }
                    \Amp\async(fn() => match ($method) {
                        'reconnect' => $mgr->reconnect($botId),
                        'jump' => $mgr->jump($botId),
                        'respawn' => $mgr->respawn($botId),
                    });
                    return new \Amp\Http\Server\Response(200, ['content-type' => 'text/plain'], "$method queued");
                }
            );
        };
        $router->addRoute('POST', '/_control/reconnect/{botid}', $lifecycle('reconnect'));
        $router->addRoute('POST', '/_control/jump/{botid}', $lifecycle('jump'));
        $router->addRoute('POST', '/_control/respawn/{botid}', $lifecycle('respawn'));

        // Scripts register their routes on the shared server (Task 6 wires the notifier).
        \scripts\notifier\notifier_register($router, $mgr);

        $server->start($router, new \Amp\Http\Server\DefaultErrorHandler());
        $GLOBALS['controlServer'] = $server;
    }

    EventLoop::onSignal(SIGINT, function () use ($mgr, $server): void {
        shutdown($mgr, $server, "Caught SIGINT GOODBYE!!!!");
    });
    EventLoop::onSignal(SIGTERM, function () use ($mgr, $server): void {
        shutdown($mgr, $server, "Caught SIGTERM GOODBYE!!!!");
    });
    $GLOBALS['botManager'] = $mgr;
}
```

Update `shutdown()` to take `\library\BotManager $mgr, ?HttpServer $server, string $msg`:

```php
function shutdown(\library\BotManager $mgr, ?\Amp\Http\Server\HttpServer $server, string $msg): void {
    echo "shutdown started: $msg\n";
    foreach ($mgr->clients as $bot) {
        if (!$bot->isConnected) continue;
        try { $bot->sendNow("quit :$msg\r\n"); } catch (\Exception $e) { echo "Exception when sending quit\n $e\n"; }
        $bot->exit();
    }
    $server?->stop();
    \Amp\delay(0.5);
    echo "Stopped?\n";
    exit(0);
}
```

Remove the old procedural `startBot()` function entirely (its body is now `BotManager::spawn()`). Keep the `require_once` lines, `makeRepliers()`, `parseOpts()`, the `$ignoreCache` ArrayAdapter, and `main(); EventLoop::run();` at the bottom. Add `use function Amp\async;` is already present; ensure `use Amp\Http\Server\Router;` etc. or use fully-qualified as shown.

- [ ] **Step 2: Smoke-test the control server without IRC**

With a throwaway `config.yaml` that has `listen: 127.0.0.1:<port>` and `control_key: test`, and a network/bot in the DB, start the bot and (from another shell) push a no-op change:

```bash
php lolbot.php &  # let it start, then:
curl -s -X POST -H 'key: test' -H 'content-type: application/json' \
  --data '{"entityType":"ignore","id":1,"action":"update"}' \
  http://127.0.0.1:<port>/_control/apply
# expect: applied
curl -s -X POST -H 'key: wrong' http://127.0.0.1:<port>/_control/apply
# expect: Invalid key (403)
```
Then SIGINT the bot. (If you can't run a live bot here, at minimum `php -l lolbot.php` and run `vendor/bin/phpunit` — the suite must stay green.)

- [ ] **Step 3: Run the full suite + phpstan**

```
vendor/bin/phpunit
vendor/bin/phpstan analyse lolbot.php --no-progress --memory-limit=1G
```
Expected: suite green; phpstan no NEW errors vs lolbot.php baseline.

- [ ] **Step 4: Commit**

```bash
git add lolbot.php
git commit -m "feat(bot): global REST server + /_control/apply|reconnect|jump|respawn in lolbot.php"
```

---

### Task 6: Notifier refactor — register routes on the global server

**Files:**
- Modify: `scripts/notifier/notifier.php`
- Modify: `lolbot.php` (the call site — already referenced as `\scripts\notifier\notifier_register($router, $mgr)` in Task 5; define it here)

The notifier's existing `privmsg`/`owncast` functionality becomes routes on the global server, namespaced `/notifier/{botid}/...`, still using `notifier_keys.yaml`.

- [ ] **Step 1: Add `notifier_register()`**

In `scripts/notifier/notifier.php`, keep the existing `notifier()` and `requestHandler()` functions (for backward compat / art bot), and add a `notifier_register()` function. The file already starts with `namespace scripts\notifier;` and several `use` statements — **do not duplicate them**; just add the function, and add any missing `use` (`Router`, `ClosureRequestHandler`, `Request`, `Response`, `HttpStatus`, `Yaml`) to the existing `use` block if not already present.

```php
/**
 * Register the notifier's privmsg/owncast routes on the shared global REST server.
 * Routes are namespaced by bot id: POST /notifier/{botid}/privmsg/{chan},
 * POST /notifier/{botid}/owncast/{chan}. Auth via notifier_keys.yaml (key header).
 */
function notifier_register(Router $router, \library\BotManager $mgr): void {
    $keysFile = __DIR__ . '/notifier_keys.yaml';
    $loadKeys = function () use ($keysFile): array {
        if (!file_exists($keysFile)) return [];
        $k = Yaml::parseFile($keysFile);
        return is_array($k) ? $k : [];
    };

    $privmsg = new ClosureRequestHandler(function (Request $request) use ($mgr, $loadKeys) {
        $args = $request->getAttribute(Router::class);
        $botId = isset($args['botid']) ? (int)$args['botid'] : 0;
        $chan = isset($args['chan']) ? '#' . $args['chan'] : null;
        $client = $mgr->clients[$botId] ?? null;
        if ($client === null || $chan === null) {
            return new Response(HttpStatus::NOT_FOUND, ['content-type' => 'text/plain'], "No such bot");
        }
        $keys = $loadKeys();
        if (!array_key_exists($request->getHeader('key'), $keys)) {
            return new Response(HttpStatus::FORBIDDEN, ['content-type' => 'text/plain'], "Invalid key");
        }
        $msg = $request->getBody()->buffer();
        $msg = str_replace("\r", "\n", $msg);
        foreach (explode("\n", $msg) as $line) {
            $client->pm($chan, substr($line, 0, 400));
        }
        return new Response(HttpStatus::OK, ['content-type' => 'text/plain'], "PRIVMSG sent");
    });
    $router->addRoute('POST', '/notifier/{botid}/privmsg/{chan}', $privmsg);

    $owncast = new ClosureRequestHandler(function (Request $request) use ($mgr, $loadKeys) {
        $args = $request->getAttribute(Router::class);
        $botId = isset($args['botid']) ? (int)$args['botid'] : 0;
        $chan = isset($args['chan']) ? '#' . $args['chan'] : null;
        $client = $mgr->clients[$botId] ?? null;
        if ($client === null || $chan === null) {
            return new Response(HttpStatus::NOT_FOUND, ['content-type' => 'text/plain'], "No such bot");
        }
        $keys = $loadKeys();
        if (!array_key_exists($request->getHeader('key'), $keys)) {
            return new Response(HttpStatus::FORBIDDEN, ['content-type' => 'text/plain'], "Invalid key");
        }
        $json = json_decode($request->getBody()->buffer(), true);
        if (is_array($json)) {
            if (($json['type'] ?? '') === 'STREAM_STARTED') {
                $client->pm($chan, "{$json['eventData']['name']} now streaming: {$json['eventData']['streamTitle']} | {$json['eventData']['summary']}");
            }
            if (($json['type'] ?? '') === 'STREAM_STOPPED') {
                $client->pm($chan, "{$json['eventData']['name']} stream stopped");
            }
        }
        return new Response(HttpStatus::OK, ['content-type' => 'text/plain'], "Owncast handled");
    });
    $router->addRoute('POST', '/notifier/{botid}/owncast/{chan}', $owncast);
}
```

> The existing top-level `function notifier(...)` (per-bot server) and `requestHandler()` stay (untouched) — they're still used by the art bot path and kept for compatibility. AGENTS.md: do not remove them or their comments.

- [ ] **Step 2: Confirm `lolbot.php` calls it**

Task 5 already added `\scripts\notifier\notifier_register($router, $mgr);` inside the `if (isset($config['listen']))` block. Verify that line is present; if Task 5 used a different name, reconcile it to `notifier_register`.

- [ ] **Step 3: Smoke-test a notifier route**

With the bot running (Task 5 setup) and `notifier_keys.yaml` containing `testkey: ci`:

```bash
curl -s -X POST -H 'key: testkey' --data 'hello from notifier' \
  http://127.0.0.1:<port>/notifier/<botid>/privmsg/<chan-without-hash>
# expect: PRIVMSG sent  (and the message appears in-channel if the bot is connected)
curl -s -X POST -H 'key: wrong' http://127.0.0.1:<port>/notifier/<botid>/privmsg/test
# expect: Invalid key (403)
```

- [ ] **Step 4: Run the suite + phpstan**

```
vendor/bin/phpunit
vendor/bin/phpstan analyse scripts/notifier/notifier.php --no-progress --memory-limit=1G
```
Expected: suite green; phpstan no NEW errors.

- [ ] **Step 5: Commit**

```bash
git add scripts/notifier/notifier.php
git commit -m "feat(notifier): register /notifier/{botid}/privmsg|owncast routes on the global server"
```

---

### Task 7: Wire `HttpPushChangeNotifier` into `admin-cli`

**Files:**
- Modify: `cli_cmds/*.php` (the mutating commands) — or a shared helper

Every mutating command currently does `new ConfigService($entityManager)` (default `NoopChangeNotifier`). Switch them to construct an `HttpPushChangeNotifier` from `config.yaml`'s `listen` + `control_key` when present (fall back to `NoopChangeNotifier` if `listen`/`control_key` aren't set, so the CLI still works offline).

- [ ] **Step 1: Add a shared helper**

Add to `library/config/ConfigService.php` (or a tiny new `library/config/notifier.php`) a factory:

```php
/**
 * Build the appropriate ChangeNotifier for a CLI/web mutating client:
 * HttpPushChangeNotifier when config.yaml has listen+control_key, else NoopChangeNotifier.
 */
function build_change_notifier(): ChangeNotifier
{
    /** @var array<string, mixed> $config */
    global $config;
    $listen = is_string($config['listen'] ?? null) ? $config['listen'] : null;
    $key = is_string($config['control_key'] ?? null) ? $config['control_key'] : null;
    if ($listen !== null && $key !== null) {
        return new HttpPushChangeNotifier(\Amp\Http\Client\HttpClientBuilder::buildDefault(), 'http://' . $listen, $key);
    }
    return new NoopChangeNotifier();
}
```

- [ ] **Step 2: Use it in the mutating commands**

In each mutating command's `execute()`, replace `new \lolbot\config\ConfigService($entityManager)` with `new \lolbot\config\ConfigService($entityManager, \lolbot\config\build_change_notifier())`. Files: `cli_cmds/network_add.php`, `network_del.php`, `network_set.php`, `bot_add.php`, `bot_del.php`, `bot_set.php`, `bot_addchannel.php`, `bot_delchannel.php`, `server_add.php`, `server_del.php`, `server_set.php`, `ignore_add.php`, `ignore_del.php`, `ignore_addnetwork.php`, `config_import.php`, and `scripts/linktitles/cli_cmds/linktitles_set.php`, plus `cli_cmds/service_set.php`. (Read-only commands — `*:list`, `service:get`, `ignore:test`, `showdb` — stay as-is; they don't mutate.)

- [ ] **Step 3: Verify a push happens**

With the bot running (Tasks 5-6) and config.yaml having `listen`+`control_key`, run a mutation and watch the bot's console for the live-apply (e.g. `php admin-cli.php bot:addchannel <botid> '#live-test'` → the bot joins `#live-test` without restart). If the bot isn't running, the command must still succeed (NoopNotifier fallback / fire-and-forget) — verify by running it with the bot stopped.

- [ ] **Step 4: Run the suite + phpstan**

```
vendor/bin/phpunit
vendor/bin/phpstan analyse library/config/ cli_cmds/ scripts/linktitles/cli_cmds/linktitles_set.php --no-progress --memory-limit=1G
```
Expected: suite green; phpstan no NEW errors.

- [ ] **Step 5: Commit**

```bash
git add library/config/ConfigService.php cli_cmds/ scripts/linktitles/cli_cmds/linktitles_set.php
git commit -m "feat(cli): mutating commands use HttpPushChangeNotifier for live-sync"
```

---

### Task 8: Config + migration guide

**Files:**
- Modify: `config.example.yaml`
- Modify: `docs/config-service-migration-guide.md`
- (config.yaml — gitignored — add `listen` + `control_key`, remove per-bot `listen`)

- [ ] **Step 1: Update `config.example.yaml`**

Add a top-level section:

```yaml
# Live-sync control surface (Sub-project 2). Keep this on localhost.
# The channel bot runs a single global REST server on `listen`; admin-cli pushes
# config changes to it (POST /_control/apply) using `control_key`.
listen: "127.0.0.1:1339"
control_key: "change-me"
```

Remove the per-bot `listen:` example line from the `bots:` block (it's no longer used — the notifier registers on the global server). Keep all other keys.

- [ ] **Step 2: Add a Sub-project 2 section to the migration guide**

Append to `docs/config-service-migration-guide.md`:

```markdown
## Sub-project 2: live config sync (optional, after Sub-project 1)

Enables live-apply so config changes take effect without a bot restart.

- [ ] Add top-level `listen` (e.g. `"127.0.0.1:1339"`) and `control_key` to `config.yaml`.
- [ ] Remove the per-bot `bots.<id>.listen` lines (no longer used).
- [ ] Restart the channel bot once to start the global REST server.
- [ ] Verify: `php admin-cli.php bot:addchannel <botid> #test` → the bot joins `#test`
      live (no restart). If the bot is down, the command still succeeds; the change
      applies on next start.
- [ ] Manual control endpoints (core key): `POST /_control/reconnect/{botid}`,
      `/_control/jump/{botid}`, `/_control/respawn/{botid}`.
- [ ] Notifier now lives at `POST /notifier/{botid}/privmsg/{chan}` and
      `/owncast/{chan}` (still `notifier_keys.yaml`); update any outside apps that
      posted to the old per-bot `/privmsg/{chan}` URL.
```

- [ ] **Step 3: Validate YAML**

```
php -r 'require "vendor/autoload.php"; use Symfony\Component\Yaml\Yaml; var_dump(is_array(Yaml::parseFile("config.example.yaml")));'
```
Expected: `bool(true)`.

- [ ] **Step 4: Commit**

```bash
git add config.example.yaml docs/config-service-migration-guide.md
git commit -m "docs(config): live-sync listen/control_key + migration guide Sub-project 2 section"
```

---

### Task 9: End-to-end verification

**Files:** none (verification only)

- [ ] **Step 1: Fresh dev DB state** — `php admin-cli.php showdb` (note a real `<botid>` and a network).

- [ ] **Step 2: Start the bot** with `config.yaml` having `listen: 127.0.0.1:<port>` + `control_key`. Confirm it starts and the global server is up (a 403/404 on `curl http://127.0.0.1:<port>/_control/apply` without a key proves it's listening).

- [ ] **Step 3: Channel live-apply** — `php admin-cli.php bot:addchannel <botid> '#live-sync-test'`; watch the bot join. Then `bot:delchannel <chanid>`; watch it part.

- [ ] **Step 4: Server jump** — `php admin-cli.php server:add <netid> irc.example.net --port 6697 --ssl`; watch the bot reconnect (jump to next server). Then `/_control/jump/<botid>` (curl with core key) rotates again.

- [ ] **Step 5: Rename** — `php admin-cli.php bot:set <botid> name <newnick>`; watch the bot change nick live.

- [ ] **Step 6: Manual endpoints** — `curl -X POST -H 'key: <control_key>' http://127.0.0.1:<port>/_control/reconnect/<botid>` and `/respawn/<botid>`; confirm each acts.

- [ ] **Step 7: Notifier route** — `curl -X POST -H 'key: <notifier_key>' --data 'hi' http://127.0.0.1:<port>/notifier/<botid>/privmsg/<chan>`; message appears in-channel.

- [ ] **Step 8: Graceful offline** — stop the bot; run `php admin-cli.php bot:addchannel <botid> '#queued'` (succeeds, push skipped); restart the bot; it joins `#queued` from the DB.

- [ ] **Step 9: Full suite + phpstan on all touched paths**

```
vendor/bin/phpunit
vendor/bin/phpstan analyse library/ cli_cmds/ scripts/notifier/ scripts/linktitles/ lolbot.php --no-progress --memory-limit=1G
```
Expected: suite green; only pre-existing baseline phpstan errors.

- [ ] **Step 10: Commit** (if any small fixes were needed during E2E)

```bash
git add -A
git commit -m "test: live-sync end-to-end verification"
```

---

## Definition of done (Sub-project 2)

- A global Router REST server runs in `lolbot.php` on `listen` with `control_key`.
- `/_control/apply`, `/_control/reconnect|jump|respawn/{botid}` work (core key).
- Notifier routes `/notifier/{botid}/privmsg|owncast/{chan}` work (notifier keys); per-bot notifier servers removed from the channel bot.
- `admin-cli` mutations push via `HttpPushChangeNotifier`; bot applies live: channel add/del → join/part; server change → jump; bot rename → nick; `linktitles:set`/service/ignore → live; `respawn`/`reconnect`/`jump` manual endpoints.
- `vendor/bin/phpunit` green; PHPStan no NEW errors.

Next: **Sub-project 3** — web control panel (#112) over `ConfigService` + the same push.
