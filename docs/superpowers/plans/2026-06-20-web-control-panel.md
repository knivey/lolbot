# Web Control Panel Implementation Plan (Sub-project 3)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a browser control panel (`web/`) that is a third client of the `ConfigService` library (like `admin-cli`), giving an integrated view — live overview + full config CRUD + live bot actions — over the bots, served out-of-process.

**Architecture:** An out-of-process PHP front controller (`web/index.php`) includes `bootstrap.php`, builds `ConfigService` with `HttpPushChangeNotifier` (the S2 push path, same as `admin-cli`), and renders HTMX + Twig templates. Config mutations push `apply` to the running bot; live runtime status is read from the bot via one new read endpoint (`GET /_control/status`). Runs under php-fpm+nginx (prod) or `php -S` (dev) — same entry point.

**Tech Stack:** PHP 8.1+, Doctrine ORM 2, standalone `twig/twig`, vendored HTMX (no build), PHPUnit 13 (SQLite-memory tests via `Tests\Config\ConfigTestCase`).

**Spec:** `docs/superpowers/specs/2026-06-20-web-control-panel-design.md`.

---

## File structure

| Path | Responsibility |
|---|---|
| `web/index.php` | Front controller: bootstrap, session, auth gate, dispatch. Same file for php-fpm and php-S. |
| `web/app.php` | Shared deps + helpers: `web_app()`, `web_render()`, `web_redirect()`, `web_is_htmx()`, `web_bot_http()`, `web_bot_status()`, `web_control_key()`. |
| `web/auth.php` | `web_require_auth()`, `web_is_auth_open()`, `web_csrf_token()`, `web_verify_csrf()`, `web_attempt_login()`. |
| `web/routes.php` | Match `(method, path)` → section handler function. Each section task appends its routes here. |
| `web/sections/bots.php` … `linktitles.php` | One file per section: `web_<section>_<action>()` handler functions. |
| `web/assets/htmx.min.js` | Vendored HTMX (downloaded, committed). |
| `web/assets/panel.css` | Dark control-panel theme. |
| `web/templates/base.twig` | Layout: topbar + sidebar nav + `{% block content %}`. |
| `web/templates/_macros.twig` | Reusable form macros (`field`, `textarea`, `submit`, `csrf_field`). |
| `web/templates/{overview,login}.twig`, `web/templates/<section>/{list,edit}.twig` + `_*.twig` fragments | Page + fragment templates. |
| `library/BotManager.php` | `+botStatus(int): ?array`, `+allBotStatuses(): array`. |
| `library/Irc/Client.php` | `+getServerDesc(): string`. |
| `lolbot.php` | `+GET /_control/status` route. |
| `tests/Config/BotManagerStatusTest.php`, `tests/Config/WebAuthTest.php` | New tests. |
| `config.example.yaml` | Document web entry / reverse-proxy. |
| `docs/config-service-migration-guide.md` | + Sub-project 3 ops section. |

**Conventions:** all panel helper functions are prefixed `web_`; handlers echo+exit (no return). Full-page routes render `base.twig`; HTMX requests (`HX-Request: true`) return a fragment and re-render it; non-HTMX POSTs follow post-redirect-get. The panel builds `ConfigService` exactly as `admin-cli` does: `new \lolbot\config\ConfigService($entityManager, \lolbot\config\build_change_notifier())`.

---

## Task 1: Bot-side live status (Client accessor + BotManager + `/_control/status`)

**Files:**
- Modify: `library/Irc/Client.php` (add `getServerDesc()` near `getNick()`, ~line 275)
- Modify: `library/BotManager.php` (add `botStatus()` + `allBotStatuses()` after `apply()`)
- Modify: `lolbot.php` (add `GET /_control/status` route, after the lifecycle routes ~line 213)
- Test: `tests/Config/BotManagerStatusTest.php`

**Why first:** standalone, fully unit-testable, unblocks the Phase-2 overview; no web entry needed.

- [ ] **Step 1: Write the failing test**

Create `tests/Config/BotManagerStatusTest.php`:

```php
<?php
namespace Tests\Config;

use lolbot\config\ConfigService;
use lolbot\config\ConfigChange;
use lolbot\entities\Bot;
use lolbot\entities\Network;
use library\BotManager;

require_once __DIR__ . '/../../vendor/autoload.php';

class BotManagerStatusTest extends ConfigTestCase
{
    public function test_botStatus_reports_connected_nick_channels_server(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');

        $mgr = new BotManager($this->em);
        $client = $this->createMock(\Irc\Client::class);
        $client->method('isEstablished')->willReturn(true);
        $client->method('getNick')->willReturn('b');
        $client->method('getJoinedChannels')->willReturn(['#dev', '#bots']);
        $client->method('getServerDesc')->willReturn('irc.example.net:6697 ssl');
        $mgr->clients[$bot->id] = $client;
        $mgr->bots[$bot->id] = $bot;
        $mgr->networks[$bot->id] = $net;

        $status = $mgr->botStatus($bot->id);
        $this->assertSame($bot->id, $status['id']);
        $this->assertSame('b', $status['name']);
        $this->assertSame('N', $status['network']);
        $this->assertTrue($status['connected']);
        $this->assertSame('b', $status['nick']);
        $this->assertSame(['#dev', '#bots'], $status['channels']);
        $this->assertSame('irc.example.net:6697 ssl', $status['server']);
    }

    public function test_allBotStatuses_returns_one_entry_per_live_bot(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');

        $mgr = new BotManager($this->em);
        $client = $this->createMock(\Irc\Client::class);
        $client->method('isEstablished')->willReturn(false);
        $client->method('getNick')->willReturn('b');
        $client->method('getJoinedChannels')->willReturn([]);
        $client->method('getServerDesc')->willReturn('irc.example.net:6697 ssl');
        $mgr->clients[$bot->id] = $client;
        $mgr->bots[$bot->id] = $bot;
        $mgr->networks[$bot->id] = $net;

        $all = $mgr->allBotStatuses();
        $this->assertCount(1, $all);
        $this->assertFalse($all[0]['connected']);
    }

    public function test_botStatus_returns_null_for_unknown_bot(): void
    {
        $mgr = new BotManager($this->em);
        $this->assertNull($mgr->botStatus(999));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Config/BotManagerStatusTest.php`
Expected: FAIL — `Call to undefined method Irc\Client::getServerDesc()` / `BotManager::botStatus()`.

- [ ] **Step 3: Add `Client::getServerDesc()`**

In `library/Irc/Client.php`, immediately after `getNick()` (after line 275), add:

```php
public function getServerDesc(): string
{
    return $this->server . ':' . $this->port . ($this->ssl ? ' ssl' : '');
}
```

(`$server`, `$port`, `$ssl` are the constructor/setServer properties; `setServer()` updates all three.)

- [ ] **Step 4: Add `BotManager::botStatus()` + `allBotStatuses()`**

In `library/BotManager.php`, after the `apply()` method (before the closing `}` at line 440), add:

```php
/**
 * Live runtime status for one bot, read from its \Irc\Client.
 *
 * @return array{id:int,name:string,network:string,connected:bool,nick:string,channels:list<string>,server:string}|null
 */
public function botStatus(int $botId): ?array
{
    $client = $this->clients[$botId] ?? null;
    $bot = $this->bots[$botId] ?? null;
    if ($client === null || $bot === null) {
        return null;
    }
    return [
        'id' => $bot->id,
        'name' => $bot->name,
        'network' => $bot->network->name,
        'connected' => $client->isEstablished(),
        'nick' => $client->getNick(),
        'channels' => array_values($client->getJoinedChannels()),
        'server' => $client->getServerDesc(),
    ];
}

/**
 * @return list<array{id:int,name:string,network:string,connected:bool,nick:string,channels:list<string>,server:string}>
 */
public function allBotStatuses(): array
{
    $out = [];
    foreach (array_keys($this->clients) as $botId) {
        $s = $this->botStatus((int)$botId);
        if ($s !== null) {
            $out[] = $s;
        }
    }
    return $out;
}
```

- [ ] **Step 5: Add `GET /_control/status` route**

In `lolbot.php`, after the lifecycle route registrations (after line 213, before the notifier block), add:

```php
        // GET /_control/status  — JSON live status of all running bots (for the web panel).
        $router->addRoute('GET', '/_control/status', new \Amp\Http\Server\RequestHandler\ClosureRequestHandler(
            function (\Amp\Http\Server\Request $request) use ($mgr, $coreKey) {
                if ($coreKey === '' || !hash_equals($coreKey, (string)$request->getHeader('key'))) {
                    return new \Amp\Http\Server\Response(403, ['content-type' => 'text/plain'], "Invalid key");
                }
                return new \Amp\Http\Server\Response(
                    200,
                    ['content-type' => 'application/json'],
                    json_encode(['bots' => $mgr->allBotStatuses()], JSON_UNESCAPED_SLASHES),
                );
            }
        ));
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Config/BotManagerStatusTest.php`
Expected: PASS (3 tests).

- [ ] **Step 7: Full suite + commit**

Run: `vendor/bin/phpunit`
Expected: all green (836 + 3 new = 839).
```bash
git add library/Irc/Client.php library/BotManager.php lolbot.php tests/Config/BotManagerStatusTest.php
git commit -m "feat(bot): live bot status accessor + GET /_control/status for web panel"
```

---

## Task 2: Web scaffold (front controller, app helpers, Twig, HTMX, base layout)

**Files:**
- Create: `web/index.php`, `web/app.php`, `web/routes.php` (skeleton), `web/assets/htmx.min.js`, `web/assets/panel.css`, `web/templates/base.twig`, `web/templates/_macros.twig`, `web/templates/home.twig`
- Modify: `composer.json` (add `twig/twig`)

- [ ] **Step 1: Add Twig dependency**

Run: `composer require twig/twig:^3`
Verify it appears in `composer.json` `require` and `vendor/twig/twig` exists.

- [ ] **Step 2: Vendor HTMX**

Download HTMX into the assets dir (no build step; committed):
```bash
mkdir -p web/assets
curl -sSL -o web/assets/htmx.min.js https://unpkg.com/htmx.org@2.0.4/dist/htmx.min.js
```
Verify `web/assets/htmx.min.js` exists and starts with `(` (minified IIFE). (If the environment has no network, copy from an existing browser cache; the file is ~50KB. Do not leave a placeholder.)

- [ ] **Step 3: Write `web/app.php` (deps + helpers)**

```php
<?php
// Shared deps + helpers for the web panel. All functions are prefixed web_.

/** @return array{twig:\Twig\Environment,svc:\lolbot\config\ConfigService,em:\Doctrine\ORM\EntityManager,config:array<string,mixed>,bot_base:string,bot_key:string} */
function web_app(): array
{
    // Not static-cached, so tests can vary $config / $entityManager per test.
    global $entityManager, $config;
    $twig = new \Twig\Environment(
        new \Twig\Loader\FilesystemLoader(__DIR__ . '/templates'),
        ['strict_variables' => false, 'autoescape' => 'html'],
    );
    return [
        'twig' => $twig,
        'svc' => new \lolbot\config\ConfigService($entityManager, \lolbot\config\build_change_notifier()),
        'em' => $entityManager,
        'config' => is_array($config) ? $config : [],
        'bot_base' => web_bot_base(is_array($config) ? $config : []),
        'bot_key' => web_control_key(is_array($config) ? $config : []),
    ];
}

function web_control_key(array $config): string
{
    return isset($config['control_key']) && is_string($config['control_key']) ? $config['control_key'] : '';
}

function web_bot_base(array $config): string
{
    return isset($config['listen']) && is_string($config['listen']) ? 'http://' . $config['listen'] : '';
}

/** Open mode: no control_key configured (loopback/tunnel intended). Defined here (not auth.php)
 *  so app helpers can use it before auth.php is loaded. */
function web_is_auth_open(): bool
{
    return web_control_key(web_app()['config']) === '';
}

/** cURL to the running bot's /_control/*. Returns decoded JSON (array) or null if unreachable. */
function web_bot_http(array $app, string $method, string $path, ?array $body = null): ?array
{
    if ($app['bot_base'] === '' || $app['bot_key'] === '' || !function_exists('curl_init')) {
        return null;
    }
    $url = $app['bot_base'] . $path;
    try {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $headers = ['key: ' . $app['bot_key']];
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
            $headers[] = 'content-type: application/json';
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    } catch (\Throwable $e) {
        return null;
    }
    if (!is_string($raw) || $code === 0 || $code >= 400) return null;
    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

/** Fetch live bot status from the running bot, or [] if unreachable. @return list<array> */
function web_bot_status(array $app): array
{
    $res = web_bot_http($app, 'GET', '/_control/status');
    return isset($res['bots']) && is_array($res['bots']) ? $res['bots'] : [];
}

function web_is_htmx(): bool
{
    return ($_SERVER['HTTP_HX_REQUEST'] ?? '') === 'true';
}

/** Render a full page (base.twig) and exit. */
function web_render(string $template, array $vars = []): never
{
    $app = web_app();
    $base = ['active' => '', 'authed' => !web_is_auth_open(), 'section' => ''];
    echo $app['twig']->render($template, array_merge($base, $vars));
    exit;
}

/** Render a fragment (no base.twig) and exit. */
function web_render_fragment(string $template, array $vars = []): never
{
    $app = web_app();
    echo $app['twig']->render($template, $vars);
    exit;
}

/** Small error fragment for inline (HTMX) handlers, so an error never renders a full page into a fragment target. */
function web_error_fragment(string $message, int $status = 400): never
{
    http_response_code($status);
    echo '<div class="badge-bad">' . htmlspecialchars($message, ENT_QUOTES) . '</div>';
    exit;
}

function web_redirect(string $path): never
{
    if (web_is_htmx()) {
        header('HX-Redirect: ' . $path); // HTMX navigates the whole page.
        exit;
    }
    header('Location: ' . $path);
    exit;
}
```

- [ ] **Step 4: Write `web/index.php` (front controller)**

```php
<?php
// Front controller — runs identically under php-fpm (nginx) and `php -S`.
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/app.php';

// Reverse-proxy aware secure cookie.
$proto = ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/',
    'httponly' => true, 'samesite' => 'Lax',
    'secure' => $proto === 'https',
]);
session_start();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
// Normalise trailing slash (except root).
if ($path !== '/' && substr($path, -1) === '/') $path = rtrim($path, '/');

require __DIR__ . '/routes.php';
web_dispatch($method, $path);
```

- [ ] **Step 5: Write skeleton `web/routes.php`**

```php
<?php
// (method, path) -> handler. Section tasks append their routes before the 404 fallback.
function web_dispatch(string $method, string $path): void
{
    // Static assets served directly (php-fpm serves these via nginx; php-S needs this).
    // Containment check prevents path-traversal (assets are served before the auth gate).
    if (str_starts_with($path, '/assets/')) {
        $real = realpath(__DIR__ . $path);
        $root = realpath(__DIR__ . '/assets');
        if ($real !== false && $root !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR) && is_file($real)) {
            $ext = pathinfo($real, PATHINFO_EXTENSION);
            header('content-type: ' . match ($ext) { 'js' => 'text/javascript', 'css' => 'text/css', default => 'application/octet-stream' });
            readfile($real);
            exit;
        }
    }

    // Home (auth gate applied inside web_home once auth.php is added in Task 3).
    if ($method === 'GET' && ($path === '/' || $path === '')) {
        web_home();
    }

    http_response_code(404);
    echo "Not found";
}

function web_home(): never
{
    web_render('home.twig', ['active' => '', 'authed' => false, 'section' => 'Home']);
}
```

- [ ] **Step 6: Write `web/templates/_macros.twig`**

```twig
{# Reusable form primitives, auto-escaped by Twig. #}
{% macro field(name, label, value, type='text', placeholder='') %}
  <div class="field">
    <label>{{ label }}</label>
    <input type="{{ type }}" name="{{ name }}" value="{{ value|default('') }}" placeholder="{{ placeholder }}">
  </div>
{% endmacro %}

{% macro textarea(name, label, value, placeholder='') %}
  <div class="field">
    <label>{{ label }}</label>
    <textarea name="{{ name }}" placeholder="{{ placeholder }}" rows="3">{{ value|default('') }}</textarea>
  </div>
{% endmacro %}

{% macro submit(label) %}
  <button class="btn" type="submit">{{ label }}</button>
{% endmacro %}

{% macro csrf_field() %}
  <input type="hidden" name="_csrf" value="{{ csrf() }}">
{% endmacro %}
```

- [ ] **Step 7: Write `web/templates/base.twig`**

```twig
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>lolbot control — {{ section }}</title>
  <link rel="stylesheet" href="/assets/panel.css">
  <script src="/assets/htmx.min.js"></script>
</head>
<body>
<div class="shell">
  <nav class="topbar">
    <span class="brand">🤖 lolbot control</span>
    <span class="who">{% if authed %}operator · <a href="/logout">logout</a>{% else %}<em>open</em>{% endif %}</span>
  </nav>
  <div class="body">
    <aside class="sidebar">
      <a href="/" class="{{ active == 'overview' ? 'sel' }}">Overview</a>
      <a href="/bots" class="{{ active == 'bots' ? 'sel' }}">Bots</a>
      <a href="/networks" class="{{ active == 'networks' ? 'sel' }}">Networks</a>
      <a href="/ignores" class="{{ active == 'ignores' ? 'sel' }}">Ignores</a>
      <a href="/services" class="{{ active == 'services' ? 'sel' }}">Services</a>
      <a href="/linktitles" class="{{ active == 'linktitles' ? 'sel' }}">Linktitles</a>
    </aside>
    <main class="content">
      {% block content %}{% endblock %}
    </main>
  </div>
</div>
</body>
</html>
```

- [ ] **Step 8: Write `web/templates/home.twig`**

```twig
{% extends 'base.twig' %}
{% block content %}
  <h1>lolbot control</h1>
  <p>Panel scaffold up. Sections arrive in later tasks.</p>
{% endblock %}
```

- [ ] **Step 9: Write `web/assets/panel.css` (dark theme)**

```css
:root { color-scheme: dark; }
* { box-sizing: border-box; }
body { margin:0; font-family: system-ui, sans-serif; background:#0f1115; color:#ddd; }
a { color:#6cb6ff; }
.shell { display:flex; flex-direction:column; min-height:100vh; }
.topbar { display:flex; justify-content:space-between; align-items:center; padding:8px 14px; background:#161b22; border-bottom:1px solid #222; }
.topbar .brand { font-weight:bold; }
.body { display:flex; flex:1; }
.sidebar { width:150px; background:#161b22; border-right:1px solid #222; padding:8px 0; display:flex; flex-direction:column; }
.sidebar a { color:#ccc; text-decoration:none; padding:7px 12px; }
.sidebar a.sel { background:#1f3a5f; color:#fff; }
.content { flex:1; padding:16px; }
h1 { font-size:1.3rem; margin-top:0; }
.field { display:grid; grid-template-columns:120px 1fr; gap:8px; align-items:center; margin-bottom:8px; max-width:560px; }
.field label { color:#9ab; }
input, textarea, select { width:100%; background:#161b22; border:1px solid #243042; color:#ddd; padding:6px; border-radius:3px; font:inherit; }
.btn { background:#1f3a5f; color:#fff; border:1px solid #2c4a72; padding:6px 12px; border-radius:3px; cursor:pointer; }
.btn.ghost { background:#222; border-color:#333; }
.card { background:#161b22; border:1px solid #243042; padding:12px; border-radius:4px; margin-bottom:10px; }
table { border-collapse:collapse; width:100%; }
th, td { text-align:left; padding:6px 8px; border-bottom:1px solid #1e2630; }
.row-actions a { margin-right:8px; }
.chip { display:inline-block; background:#161b22; border:1px solid #243042; padding:3px 8px; border-radius:10px; margin:2px; }
.muted { color:#7a8693; }
.badge-ok { color:#5fd07a; } .badge-bad { color:#e06c75; }
```

- [ ] **Step 10: Manually verify the scaffold serves**

Run the dev server in one terminal: `php -S 127.0.0.1:8088 -t web/ web/index.php`
In another: `curl -s http://127.0.0.1:8088/ | rg -o '<title>.*</title>'`
Expected: `<title>lolbot control — Home</title>`. Also `curl -sI http://127.0.0.1:8088/assets/htmx.min.js` → `200`.

- [ ] **Step 11: Commit**

```bash
git add composer.json composer.lock web/
git commit -m "feat(web): panel scaffold — front controller, Twig, HTMX, base layout"
```

---

## Task 3: Auth (session login + CSRF)

**Files:**
- Create: `web/auth.php`, `web/templates/login.twig`
- Modify: `web/routes.php` (add `/login`, `/logout`; gate handlers)
- Test: `tests/Config/WebAuthTest.php`

**Model:** `control_key` unset → panel open (loopback/tunnel intended). Set → `GET /login` (enter key, verified with `hash_equals`) → `$_SESSION['authed']=true`; every POST verified against a CSRF token in session.

- [ ] **Step 1: Write the failing test**

Create `tests/Config/WebAuthTest.php`:

```php
<?php
namespace Tests\Config;

require_once __DIR__ . '/../../vendor/autoload.php';

/** Auth helpers are tested via the function surface (session state in-process). */
class WebAuthTest extends ConfigTestCase
{
    private function bootAuth(array $config): void
    {
        $GLOBALS['config'] = $config;
        $GLOBALS['entityManager'] = $this->em; // web_app() builds ConfigService from the global EM.
        @session_start();
        $_SESSION = [];
        require_once __DIR__ . '/../../web/app.php';
        require_once __DIR__ . '/../../web/auth.php';
    }

    public function test_is_auth_open_when_no_key(): void
    {
        $this->bootAuth([]);
        $this->assertTrue(web_is_auth_open());
    }

    public function test_is_not_open_when_key_set(): void
    {
        $this->bootAuth(['control_key' => 'sekret']);
        $this->assertFalse(web_is_auth_open());
    }

    public function test_attempt_login_succeeds_with_correct_key(): void
    {
        $this->bootAuth(['control_key' => 'sekret']);
        $this->assertTrue(web_attempt_login('sekret'));
        $this->assertTrue($_SESSION['authed'] ?? false);
    }

    public function test_attempt_login_rejects_wrong_key(): void
    {
        $this->bootAuth(['control_key' => 'sekret']);
        $this->assertFalse(web_attempt_login('nope'));
        $this->assertFalse($_SESSION['authed'] ?? false);
    }

    public function test_csrf_token_roundtrip(): void
    {
        $this->bootAuth(['control_key' => 'sekret']);
        $tok = web_csrf_token();
        $_POST['_csrf'] = $tok;
        web_verify_csrf(); // no exception
        $this->assertTrue(true);
    }

    public function test_csrf_rejects_mismatch(): void
    {
        $this->bootAuth(['control_key' => 'sekret']);
        $_POST['_csrf'] = 'wrong';
        $this->expectException(\RuntimeException::class);
        web_verify_csrf();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Config/WebAuthTest.php`
Expected: FAIL — `web_is_auth_open` undefined.

- [ ] **Step 3: Write `web/auth.php`**

```php
<?php
// Session-based auth + CSRF. Auth is OPEN when control_key is unset (loopback intended).
// (web_is_auth_open() lives in app.php.)

function web_is_authed(): bool
{
    return web_is_auth_open() || ($_SESSION['authed'] ?? false) === true;
}

/** Returns true on success. Uses hash_equals (timing-safe). */
function web_attempt_login(string $key): bool
{
    $real = web_control_key(web_app()['config']);
    if ($real !== '' && hash_equals($real, $key)) {
        session_regenerate_id(true);
        $_SESSION['authed'] = true;
        $_SESSION['csrf']   = bin2hex(random_bytes(16));
        return true;
    }
    return false;
}

function web_require_auth(): void
{
    if (!web_is_authed()) {
        web_redirect('/login');
    }
}

function web_csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

/** Called on every POST. Throws on mismatch (handlers catch → error fragment). */
function web_verify_csrf(): void
{
    $tok = is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : '';
    if (web_is_auth_open()) {
        return; // open mode: no session token exists yet
    }
    $expected = is_string($_SESSION['csrf'] ?? null) ? $_SESSION['csrf'] : '';
    if ($expected === '' || !hash_equals($expected, $tok)) {
        throw new \RuntimeException('Invalid CSRF token');
    }
}

/** Twig-exposed csrf() used by the _csrf macro. */
function web_twig_csrf(): string
{
    return web_is_auth_open() ? '' : web_csrf_token();
}
```

Require `auth.php` from the front controller — add this line to `web/index.php` right after the `app.php` require:

```php
require_once __DIR__ . '/auth.php';
```

Expose `csrf` to Twig by editing `web/app.php`'s `web_app()`: after building `$twig`, add:
```php
    $twig->addFunction(new \Twig\TwigFunction('csrf', 'web_twig_csrf'));
```
(Place this inside `web_app()` right after the `$twig = new \Twig\Environment(...)` line, before assigning `$app`.)

- [ ] **Step 4: Write `web/templates/login.twig`**

```twig
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>lolbot control — login</title>
<link rel="stylesheet" href="/assets/panel.css"></head>
<body><div class="content" style="max-width:360px;margin:60px auto">
<h1>lolbot control</h1>
{% if error %}<p class="badge-bad">{{ error }}</p>{% endif %}
<form method="post" action="/login">
  <input type="hidden" name="_csrf" value="{{ csrf() }}">
  <div class="field" style="grid-template-columns:1fr"><label>control key</label>
    <input type="password" name="key" autofocus></div>
  <button class="btn" type="submit">Log in</button>
</form></div></body></html>
```

- [ ] **Step 5: Wire `/login` + `/logout` into `web/routes.php`**

Replace the `web_dispatch` body's home block and add login/logout. The new `web_dispatch`:

```php
function web_dispatch(string $method, string $path): void
{
    // Static assets (containment-checked — assets are served before the auth gate).
    if (str_starts_with($path, '/assets/')) {
        $real = realpath(__DIR__ . $path);
        $root = realpath(__DIR__ . '/assets');
        if ($real !== false && $root !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR) && is_file($real)) {
            $ext = pathinfo($real, PATHINFO_EXTENSION);
            header('content-type: ' . match ($ext) { 'js' => 'text/javascript', 'css' => 'text/css', default => 'application/octet-stream' });
            readfile($real);
            exit;
        }
    }

    // Auth routes are always reachable.
    if ($method === 'GET' && $path === '/login') { web_login_form(); }
    if ($method === 'POST' && $path === '/login') { web_login_submit(); }
    if ($method === 'GET' && $path === '/logout') {
        $_SESSION = []; session_destroy();
        web_redirect('/login');
    }

    // Everything else requires auth (no-op when open).
    web_require_auth();

    if ($method === 'GET' && ($path === '/' || $path === '')) { web_home(); }

    http_response_code(404);
    echo "Not found";
}

function web_home(): never
{
    web_render('home.twig', ['active' => 'overview', 'authed' => !web_is_auth_open(), 'section' => 'Overview']);
}

function web_login_form(?string $error = null): never
{
    echo web_app()['twig']->render('login.twig', ['error' => $error]);
    exit;
}

function web_login_submit(): never
{
    $key = is_string($_POST['key'] ?? null) ? $_POST['key'] : '';
    if (web_attempt_login($key)) {
        web_redirect('/');
    }
    web_login_form('Invalid key');
}
```

(Note: `web_login_form`/`web_login_submit` are `never` and `exit`; remove the bare `web_login_form();` return-by-exit reliance by keeping them as terminal calls — they exit internally. The dispatch lines above rely on that; if a match falls through, the auth gate then runs.)

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Config/WebAuthTest.php`
Expected: PASS (6 tests).

- [ ] **Step 7: Manually verify auth flow**

With `control_key` set in `config.yaml`: `curl -sI http://127.0.0.1:8088/` → `302` to `/login`. With it unset → `200` (open). (Use the `php -S` server from Task 2.)

- [ ] **Step 8: Full suite + commit**

Run: `vendor/bin/phpunit`
Expected: all green.
```bash
git add web/auth.php web/templates/login.twig web/routes.php web/app.php tests/Config/WebAuthTest.php
git commit -m "feat(web): session login + CSRF auth (control_key-gated)"
```

---

## Task 4: Bots section (list/edit/add/delete + channels + live actions)

**Files:**
- Create: `web/sections/bots.php`, `web/templates/bots/list.twig`, `web/templates/bots/edit.twig`, `web/templates/bots/_channels.twig`, `web/templates/bots/_actions.twig`
- Modify: `web/routes.php` (wire bot routes; `require` section files)

This task establishes the canonical section pattern (handler structure, list/edit/fragment, ConfigService mutation, HTMX swap, live-action button) that Tasks 5–8 replicate.

- [ ] **Step 1: Write `web/sections/bots.php`**

```php
<?php
// Bots: list / edit / add / delete + channels (live join/part) + live actions.

function web_bots_list(): never
{
    $app = web_app();
    web_render('bots/list.twig', [
        'active' => 'bots', 'section' => 'Bots',
        'bots' => $app['svc']->listBots(),
    ]);
}

function web_bots_new(): never
{
    $app = web_app();
    web_render('bots/edit.twig', [
        'active' => 'bots', 'section' => 'New bot',
        'bot' => null, 'networks' => $app['svc']->listNetworks(),
        'error' => null,
    ]);
}

function web_bots_create(): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_bots_new_error($e->getMessage()); }
    $name = trim(is_string($_POST['name'] ?? null) ? $_POST['name'] : '');
    $netId = (int)($_POST['network_id'] ?? 0);
    $net = $app['svc']->getNetwork($netId);
    if ($net === null || $name === '') {
        web_bots_new_error('Network and name are required');
    }
    try {
        $bot = $app['svc']->createBot($net, $name);
    } catch (\Throwable $e) {
        web_bots_new_error($e->getMessage());
    }
    web_redirect('/bots');
}

function web_bots_new_error(string $error): never
{
    $app = web_app();
    web_render('bots/edit.twig', [
        'active' => 'bots', 'section' => 'New bot',
        'bot' => null, 'networks' => $app['svc']->listNetworks(), 'error' => $error,
    ]);
}

function web_bots_edit(int $id, ?string $error = null): never
{
    $app = web_app();
    $bot = $app['svc']->getBot($id);
    if ($bot === null) { http_response_code(404); echo "No such bot"; exit; }
    web_render('bots/edit.twig', [
        'active' => 'bots', 'section' => 'Edit ' . $bot->name,
        'bot' => $bot, 'networks' => $app['svc']->listNetworks(), 'error' => $error,
    ]);
}

function web_bots_update(int $id): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_bots_edit($id, $e->getMessage()); }
    $bot = $app['svc']->getBot($id);
    if ($bot === null) { http_response_code(404); echo "No such bot"; exit; }
    $bot->name        = trim($_POST['name'] ?? $bot->name);
    $bot->trigger     = ($_POST['trigger'] ?? '') !== '' ? trim($_POST['trigger']) : null;
    $bot->trigger_re  = ($_POST['trigger_re'] ?? '') !== '' ? trim($_POST['trigger_re']) : null;
    $bot->onConnect   = is_string($_POST['onConnect'] ?? null) ? $_POST['onConnect'] : '';
    $bot->sasl_user   = ($_POST['sasl_user'] ?? '') !== '' ? trim($_POST['sasl_user']) : null;
    $bot->sasl_pass   = ($_POST['sasl_pass'] ?? '') !== '' ? trim($_POST['sasl_pass']) : null;
    $bot->bindIp      = is_string($_POST['bindIp'] ?? null) ? trim($_POST['bindIp']) : '0';
    try {
        $app['svc']->update($bot, 'bot'); // pushes apply → nick/trigger live; sasl/bindIp/onConnect need respawn
    } catch (\Throwable $e) {
        web_bots_edit($id, $e->getMessage());
    }
    web_redirect('/bots/' . $id);
}

function web_bots_delete(int $id): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_bots_edit($id, $e->getMessage()); }
    $bot = $app['svc']->getBot($id);
    if ($bot !== null) { $app['svc']->deleteBot($bot); } // pushes apply → drop
    web_redirect('/bots');
}

// Channels (live join/part via ConfigService → apply).
function web_bots_add_channel(int $botId): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_error_fragment($e->getMessage()); }
    $bot = $app['svc']->getBot($botId);
    $chan = trim(is_string($_POST['channel'] ?? null) ? $_POST['channel'] : '');
    if ($bot !== null && $chan !== '') {
        try { $app['svc']->addChannel($bot, $chan); } catch (\Throwable $e) { web_bots_edit($botId, $e->getMessage()); }
    }
    web_bots_channels_fragment($botId);
}

function web_bots_del_channel(int $botId, int $chanId): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_error_fragment($e->getMessage()); }
    $chan = $app['em']->find(\lolbot\entities\Channel::class, $chanId);
    if ($chan !== null) { $app['svc']->deleteChannel($chan); } // pushes apply → part
    web_bots_channels_fragment($botId);
}

function web_bots_channels_fragment(int $botId): never
{
    $app = web_app();
    $bot = $app['svc']->getBot($botId);
    web_render_fragment('bots/_channels.twig', ['bot' => $bot, 'csrf' => web_twig_csrf()]);
}

// Live actions: reconnect / jump / respawn → bot's /_control/* endpoints.
function web_bots_action(int $botId, string $action): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_error_fragment($e->getMessage()); }
    if (in_array($action, ['reconnect', 'jump', 'respawn'], true)) {
        web_bot_http($app, 'POST', '/_control/' . $action . '/' . $botId);
    }
    web_render_fragment('bots/_actions.twig', ['botId' => $botId, 'csrf' => web_twig_csrf(), 'queued' => $action]);
}
```

- [ ] **Step 2: Wire bot routes + section requires into `web/routes.php`**

At the top of `web/routes.php`, after `<?php`, add:
```php
require_once __DIR__ . '/sections/bots.php';
```
Inside `web_dispatch`, **after** the `web_require_auth();` line and the home route, add (before the 404):
```php
    if ($method === 'GET' && $path === '/bots') { web_bots_list(); }
    if ($method === 'GET' && $path === '/bots/new') { web_bots_new(); }
    if ($method === 'POST' && $path === '/bots') { web_bots_create(); }
    if ($method === 'GET' && preg_match('#^/bots/(\d+)$#', $path, $m)) { web_bots_edit((int)$m[1]); }
    if ($method === 'POST' && preg_match('#^/bots/(\d+)$#', $path, $m)) { web_bots_update((int)$m[1]); }
    if ($method === 'POST' && preg_match('#^/bots/(\d+)/delete$#', $path, $m)) { web_bots_delete((int)$m[1]); }
    if ($method === 'POST' && preg_match('#^/bots/(\d+)/channels$#', $path, $m)) { web_bots_add_channel((int)$m[1]); }
    if ($method === 'POST' && preg_match('#^/bots/(\d+)/channels/(\d+)/delete$#', $path, $m)) { web_bots_del_channel((int)$m[1], (int)$m[2]); }
    if ($method === 'POST' && preg_match('#^/bots/(\d+)/(reconnect|jump|respawn)$#', $path, $m)) { web_bots_action((int)$m[1], $m[2]); }
```

- [ ] **Step 3: Write `web/templates/bots/list.twig`**

```twig
{% extends 'base.twig' %}
{% import '_macros.twig' as m %}
{% block content %}
<h1>Bots</h1>
<p><a class="btn" href="/bots/new">+ add bot</a></p>
<table>
  <tr><th>id</th><th>name</th><th>network</th><th>trigger</th><th>channels</th><th></th></tr>
  {% for b in bots %}
  <tr>
    <td>{{ b.id }}</td><td>{{ b.name }}</td><td>{{ b.network.name }}</td>
    <td>{{ b.trigger ?: (b.trigger_re ?: '') }}</td><td>{{ b.channels|length }}</td>
    <td class="row-actions"><a href="/bots/{{ b.id }}">edit</a></td>
  </tr>
  {% endfor %}
</table>
{% endblock %}
```

- [ ] **Step 4: Write `web/templates/bots/edit.twig`**

```twig
{% extends 'base.twig' %}
{% import '_macros.twig' as m %}
{% block content %}
<h1>{{ bot ? 'Edit ' ~ bot.name : 'New bot' }}</h1>
{% if error %}<p class="badge-bad">{{ error }}</p>{% endif %}
{% if saved|default(false) %}<p class="badge-ok">saved (live-applied; sasl/bindIp/onConnect need respawn)</p>{% endif %}
<form {% if bot %}action="/bots/{{ bot.id }}"{% else %}action="/bots"{% endif %} method="post">
  {{ m.csrf_field() }}
  {{ m.field('name', 'nick', bot ? bot.name : '') }}
  {% if not bot %}
    <div class="field"><label>network</label>
      <select name="network_id">{% for n in networks %}<option value="{{ n.id }}">{{ n.name }}</option>{% endfor %}</select></div>
  {% endif %}
  {{ m.field('trigger', 'trigger', bot ? bot.trigger : '', 'text', 'e.g. !') }}
  {{ m.field('trigger_re', 'trigger regex', bot ? bot.trigger_re : '') }}
  {{ m.textarea('onConnect', 'onConnect', bot ? bot.onConnect : '', 'raw IRC commands, $me = nick') }}
  {{ m.field('sasl_user', 'sasl user', bot ? bot.sasl_user : '') }}
  {{ m.field('sasl_pass', 'sasl pass', bot ? bot.sasl_pass : '', 'password') }}
  {{ m.field('bindIp', 'bind ip', bot ? bot.bindIp : '0') }}
  {{ m.submit('Save') }}
</form>

{% if bot %}
  {% include 'bots/_actions.twig' with {botId: bot.id, csrf: csrf(), queued: ''} %}
  {% include 'bots/_channels.twig' with {bot: bot, csrf: csrf()} %}
  <form class="inline" method="post" action="/bots/{{ bot.id }}/delete"
        onsubmit="return confirm('Delete bot {{ bot.name }}?')">
    {{ m.csrf_field() }} <button class="btn ghost" type="submit">Delete bot</button>
  </form>
{% endif %}
{% endblock %}
```

- [ ] **Step 5: Write `web/templates/bots/_channels.twig`**

```twig
<div id="channels">
  <h3>Channels <span class="muted">(add/remove = live join/part)</span></h3>
  <div>
    {% for c in bot.channels %}
      <span class="chip">{{ c.name }}
        <form class="inline" method="post" action="/bots/{{ bot.id }}/channels/{{ c.id }}/delete"
              hx-post="/bots/{{ bot.id }}/channels/{{ c.id }}/delete" hx-target="#channels" hx-swap="outerHTML">
          <input type="hidden" name="_csrf" value="{{ csrf }}"> <button class="btn ghost" type="submit">−</button>
        </form>
      </span>
    {% endfor %}
  </div>
  <form method="post" action="/bots/{{ bot.id }}/channels" hx-post="/bots/{{ bot.id }}/channels"
        hx-target="#channels" hx-swap="outerHTML">
    <input type="hidden" name="_csrf" value="{{ csrf }}">
    <input name="channel" placeholder="#channel">
    <button class="btn" type="submit">+ add channel</button>
  </form>
</div>
```

- [ ] **Step 6: Write `web/templates/bots/_actions.twig`**

```twig
<div id="actions">
  {% if queued %}<p class="badge-ok">{{ queued }} queued</p>{% endif %}
  {% for act in ['reconnect','jump','respawn'] %}
    <form class="inline" method="post" action="/bots/{{ botId }}/{{ act }}"
          hx-post="/bots/{{ botId }}/{{ act }}" hx-target="#actions" hx-swap="outerHTML">
      <input type="hidden" name="_csrf" value="{{ csrf }}">
      <button class="btn ghost" type="submit">{{ act }}</button>
    </form>
  {% endfor %}
</div>
```

- [ ] **Step 7: Manually verify the full bots flow**

With the bot running (S2 live-sync on) + `php -S` dev server + `control_key` unset:
- `GET /bots` shows the table.
- `GET /bots/new` → create a bot → it spawns live (check bot process log).
- `GET /bots/<id>` → edit nick, Save → nick changes live on IRC.
- Add a channel → bot JOINs live; remove → PARTs.
- Click reconnect → bot reconnects.
Confirm each via the running bot's IRC presence.

- [ ] **Step 8: Commit**

```bash
git add web/sections/bots.php web/templates/bots web/routes.php
git commit -m "feat(web): bots section — CRUD + live channels + reconnect/jump/respawn"
```

---

## Task 5: Networks + Servers section

**Files:**
- Create: `web/sections/networks.php`, `web/templates/networks/list.twig`, `web/templates/networks/edit.twig`, `web/templates/networks/_servers.twig`
- Modify: `web/routes.php` (require + wire)

Servers nest under a network (status quo; per-bot shelved). `addServer(Network $net, string $address, int $port=6667, bool $ssl=false, bool $throttle=true, ?string $password=null)`.

- [ ] **Step 1: Write `web/sections/networks.php`**

```php
<?php
function web_networks_list(): never
{
    web_render('networks/list.twig', ['active' => 'networks', 'section' => 'Networks', 'networks' => web_app()['svc']->listNetworks()]);
}

function web_networks_new(?string $error = null): never
{
    web_render('networks/edit.twig', ['active' => 'networks', 'section' => 'New network', 'net' => null, 'error' => $error]);
}

function web_networks_create(): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_networks_new($e->getMessage()); }
    $name = trim(is_string($_POST['name'] ?? null) ? $_POST['name'] : '');
    if ($name === '') { web_networks_new('Name required'); }
    try { $app['svc']->createNetwork($name); } catch (\Throwable $e) { web_networks_new($e->getMessage()); }
    web_redirect('/networks');
}

function web_networks_edit(int $id, ?string $error = null): never
{
    $net = web_app()['svc']->getNetwork($id);
    if ($net === null) { http_response_code(404); echo "No such network"; exit; }
    web_render('networks/edit.twig', ['active' => 'networks', 'section' => 'Edit ' . $net->name, 'net' => $net, 'error' => $error]);
}

function web_networks_update(int $id): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_networks_edit($id, $e->getMessage()); }
    $net = $app['svc']->getNetwork($id);
    if ($net === null) { http_response_code(404); echo "No such network"; exit; }
    $net->name = trim($_POST['name'] ?? $net->name);
    try { $app['svc']->update($net, 'network'); } catch (\Throwable $e) { web_networks_edit($id, $e->getMessage()); }
    web_redirect('/networks/' . $id);
}

function web_networks_delete(int $id): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_networks_edit($id, $e->getMessage()); }
    $net = $app['svc']->getNetwork($id);
    if ($net !== null) { $app['svc']->deleteNetwork($net); }
    web_redirect('/networks');
}

// Servers nested under a network.
function web_networks_add_server(int $netId): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_error_fragment($e->getMessage()); }
    $net = $app['svc']->getNetwork($netId);
    if ($net === null) { http_response_code(404); echo "No such network"; exit; }
    $addr = trim($_POST['address'] ?? '');
    if ($addr !== '') {
        try {
            $app['svc']->addServer($net, $addr, (int)($_POST['port'] ?? 6667), isset($_POST['ssl']), isset($_POST['throttle']), ($_POST['password'] ?? '') !== '' ? $_POST['password'] : null);
        } catch (\Throwable $e) { web_networks_edit($netId, $e->getMessage()); }
    }
    web_servers_fragment($netId);
}

function web_networks_del_server(int $netId, int $srvId): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_error_fragment($e->getMessage()); }
    $srv = $app['svc']->getServer($srvId);
    if ($srv !== null) { $app['svc']->deleteServer($srv); } // pushes apply → live jump
    web_servers_fragment($netId);
}

function web_servers_fragment(int $netId): never
{
    $net = web_app()['svc']->getNetwork($netId);
    web_render_fragment('networks/_servers.twig', ['net' => $net, 'csrf' => web_twig_csrf()]);
}
```

- [ ] **Step 2: Wire routes into `web/routes.php`**

Add at top: `require_once __DIR__ . '/sections/networks.php';`
In `web_dispatch` (before 404):
```php
    if ($method === 'GET' && $path === '/networks') { web_networks_list(); }
    if ($method === 'GET' && $path === '/networks/new') { web_networks_new(); }
    if ($method === 'POST' && $path === '/networks') { web_networks_create(); }
    if ($method === 'GET' && preg_match('#^/networks/(\d+)$#', $path, $m)) { web_networks_edit((int)$m[1]); }
    if ($method === 'POST' && preg_match('#^/networks/(\d+)$#', $path, $m)) { web_networks_update((int)$m[1]); }
    if ($method === 'POST' && preg_match('#^/networks/(\d+)/delete$#', $path, $m)) { web_networks_delete((int)$m[1]); }
    if ($method === 'POST' && preg_match('#^/networks/(\d+)/servers$#', $path, $m)) { web_networks_add_server((int)$m[1]); }
    if ($method === 'POST' && preg_match('#^/networks/(\d+)/servers/(\d+)/delete$#', $path, $m)) { web_networks_del_server((int)$m[1], (int)$m[2]); }
```

- [ ] **Step 3: Write `web/templates/networks/list.twig`**

```twig
{% extends 'base.twig' %}
{% block content %}
<h1>Networks</h1>
<p><a class="btn" href="/networks/new">+ add network</a></p>
<table><tr><th>id</th><th>name</th><th>servers</th><th>bots</th><th></th></tr>
{% for n in networks %}
  <tr><td>{{ n.id }}</td><td>{{ n.name }}</td><td>{{ n.servers|length }}</td><td>{{ n.bots|length }}</td>
  <td class="row-actions"><a href="/networks/{{ n.id }}">edit</a></td></tr>
{% endfor %}
</table>
{% endblock %}
```

- [ ] **Step 4: Write `web/templates/networks/edit.twig`**

```twig
{% extends 'base.twig' %}
{% import '_macros.twig' as m %}
{% block content %}
<h1>{{ net ? 'Edit ' ~ net.name : 'New network' }}</h1>
{% if error %}<p class="badge-bad">{{ error }}</p>{% endif %}
<form {% if net %}action="/networks/{{ net.id }}"{% else %}action="/networks"{% endif %} method="post">
  {{ m.csrf_field() }}
  {{ m.field('name', 'name', net ? net.name : '') }}
  {{ m.submit('Save') }}
</form>
{% if net %}
  {% include 'networks/_servers.twig' with {net: net, csrf: csrf()} %}
  <form method="post" action="/networks/{{ net.id }}/delete"
        onsubmit="return confirm('Delete network {{ net.name }} and its servers?')">
    {{ m.csrf_field() }} <button class="btn ghost" type="submit">Delete network</button>
  </form>
{% endif %}
{% endblock %}
```

- [ ] **Step 5: Write `web/templates/networks/_servers.twig`**

```twig
<div id="servers">
  <h3>Servers <span class="muted">(change = live jump)</span></h3>
  <table><tr><th>address</th><th>port</th><th>ssl</th><th>throttle</th><th></th></tr>
  {% for s in net.servers %}
    <tr><td>{{ s.address }}</td><td>{{ s.port }}</td><td>{{ s.ssl ? '✓' }}</td><td>{{ s.throttle ? '✓' }}</td>
    <td><form class="inline" method="post" action="/networks/{{ net.id }}/servers/{{ s.id }}/delete"
              hx-post="/networks/{{ net.id }}/servers/{{ s.id }}/delete" hx-target="#servers" hx-swap="outerHTML">
      <input type="hidden" name="_csrf" value="{{ csrf }}"><button class="btn ghost" type="submit">−</button></form></td></tr>
  {% endfor %}
  </table>
  <form method="post" action="/networks/{{ net.id }}/servers" hx-post="/networks/{{ net.id }}/servers"
        hx-target="#servers" hx-swap="outerHTML">
    <input type="hidden" name="_csrf" value="{{ csrf }}">
    <input name="address" placeholder="irc.example.net">
    <input name="port" placeholder="6667" style="max-width:70px">
    <label><input type="checkbox" name="ssl"> ssl</label>
    <label><input type="checkbox" name="throttle" checked> throttle</label>
    <input name="password" type="password" placeholder="password (opt)">
    <button class="btn" type="submit">+ add server</button>
  </form>
</div>
```

- [ ] **Step 6: Manually verify + commit**

Verify: list/edit/add-network/delete, add-server → bot jumps live, delete-server → bot jumps. Then:
```bash
git add web/sections/networks.php web/templates/networks web/routes.php
git commit -m "feat(web): networks + servers section (servers nested under network)"
```

---

## Task 6: Ignores section

**Files:**
- Create: `web/sections/ignores.php`, `web/templates/ignores/list.twig`
- Modify: `web/routes.php`

`addIgnore(string $hostmask, ?string $reason, array $networks)` — `$networks` is an array of Network entities. `listIgnores()`, `deleteIgnore()`. Ignore changes auto-apply (5s TTL cache); no live action.

- [ ] **Step 1: Write `web/sections/ignores.php`**

```php
<?php
function web_ignores_list(?string $error = null): never
{
    $app = web_app();
    web_render('ignores/list.twig', [
        'active' => 'ignores', 'section' => 'Ignores',
        'ignores' => $app['svc']->listIgnores(),
        'networks' => $app['svc']->listNetworks(),
        'error' => $error,
    ]);
}

function web_ignores_create(): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_ignores_list($e->getMessage()); }
    $host = trim($_POST['hostmask'] ?? '');
    $reason = trim($_POST['reason'] ?? '') !== '' ? trim($_POST['reason']) : null;
    $nets = [];
    foreach (($_POST['networks'] ?? []) as $nid) {
        $n = $app['svc']->getNetwork((int)$nid);
        if ($n !== null) $nets[] = $n;
    }
    if ($host !== '' && $nets) {
        try { $app['svc']->addIgnore($host, $reason, $nets); } catch (\Throwable $e) { web_ignores_list($e->getMessage()); }
    } else {
        web_ignores_list('Hostmask and at least one network required');
    }
    web_redirect('/ignores');
}

function web_ignores_delete(int $id): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_ignores_list($e->getMessage()); }
    $ig = $app['svc']->getIgnore($id);
    if ($ig !== null) { $app['svc']->deleteIgnore($ig); }
    web_redirect('/ignores');
}
```

- [ ] **Step 2: Wire routes**

Top: `require_once __DIR__ . '/sections/ignores.php';`
In dispatch (before 404):
```php
    if ($method === 'GET' && $path === '/ignores') { web_ignores_list(); }
    if ($method === 'POST' && $path === '/ignores') { web_ignores_create(); }
    if ($method === 'POST' && preg_match('#^/ignores/(\d+)/delete$#', $path, $m)) { web_ignores_delete((int)$m[1]); }
```

- [ ] **Step 3: Write `web/templates/ignores/list.twig`**

```twig
{% extends 'base.twig' %}
{% import '_macros.twig' as m %}
{% block content %}
<h1>Ignores</h1>
{% if error %}<p class="badge-bad">{{ error }}</p>{% endif %}
<table><tr><th>id</th><th>hostmask</th><th>reason</th><th>networks</th><th></th></tr>
{% for ig in ignores %}
  <tr><td>{{ ig.id }}</td><td>{{ ig.hostmask }}</td><td>{{ ig.reason }}</td>
  <td>{% for n in ig.networks %}<span class="chip">{{ n.name }}</span>{% endfor %}</td>
  <td><form class="inline" method="post" action="/ignores/{{ ig.id }}/delete"><input type="hidden" name="_csrf" value="{{ csrf() }}"><button class="btn ghost" type="submit">−</button></form></td></tr>
{% endfor %}
</table>
<h3>Add ignore</h3>
<form method="post" action="/ignores">
  {{ m.csrf_field() }}
  {{ m.field('hostmask', 'hostmask', '', 'text', '*nick!user@host') }}
  {{ m.field('reason', 'reason (opt)', '') }}
  <div class="field"><label>networks</label>
    <select name="networks[]" multiple size="4">{% for n in networks %}<option value="{{ n.id }}">{{ n.name }}</option>{% endfor %}</select></div>
  {{ m.submit('Add') }}
</form>
<p class="muted">Ignores auto-apply within ~5s (cache TTL) — no restart needed.</p>
{% endblock %}
```

- [ ] **Step 4: Manually verify + commit**

Verify add/delete; ignore takes effect live. Then:
```bash
git add web/sections/ignores.php web/templates/ignores web/routes.php
git commit -m "feat(web): ignores section (auto-applies via cache TTL)"
```

---

## Task 7: Services section (AI + paste)

**Files:**
- Create: `web/sections/services.php`, `web/templates/services.twig`
- Modify: `web/routes.php`

Global service config via `setServiceConfigValue(string $type, string $key, mixed $value)`. Types: `ai`, `paste`. Keys (read from the entities via `ServiceLocator`/repo): ai — `apiKey, baseUrl, maxDim, jpgQuality, timeout, reasoningEffort, reasoning`; paste — `host, key`. Consumers read DB per-use, so no live action.

- [ ] **Step 1: Write `web/sections/services.php`**

```php
<?php
function web_services(?string $error = null): never
{
    $app = web_app();
    $ai = $app['em']->getRepository(\lolbot\entities\AiServiceConfig::class)->findOneBy([]) ?? new \lolbot\entities\AiServiceConfig();
    $paste = $app['em']->getRepository(\lolbot\entities\PasteServiceConfig::class)->findOneBy([]) ?? new \lolbot\entities\PasteServiceConfig();
    web_render('services.twig', ['active' => 'services', 'section' => 'Services', 'ai' => $ai, 'paste' => $paste, 'error' => $error]);
}

function web_services_save(string $type): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_services($e->getMessage()); }
    $keys = $type === 'ai'
        ? ['apiKey', 'baseUrl', 'maxDim', 'jpgQuality', 'timeout', 'reasoningEffort']
        : ['host', 'key'];
    foreach ($keys as $k) {
        if (!array_key_exists($k, $_POST)) continue;
        $val = trim((string)$_POST[$k]);
        if ($val === '') continue;
        $app['svc']->setServiceConfigValue($type, $k, in_array($k, ['maxDim','jpgQuality','timeout']) ? (int)$val : $val);
    }
    web_redirect('/services');
}
```

(`reasoning` is a JSON field; defer a JSON editor to a follow-up — leave it out of the form rather than guess at structure. The listed scalar keys cover the common knobs.)

- [ ] **Step 2: Wire routes**

Top: `require_once __DIR__ . '/sections/services.php';`
In dispatch:
```php
    if ($method === 'GET' && $path === '/services') { web_services(); }
    if ($method === 'POST' && $path === '/services/ai') { web_services_save('ai'); }
    if ($method === 'POST' && $path === '/services/paste') { web_services_save('paste'); }
```

- [ ] **Step 3: Write `web/templates/services.twig`**

```twig
{% extends 'base.twig' %}
{% import '_macros.twig' as m %}
{% block content %}
<h1>Services</h1>
{% if error %}<p class="badge-bad">{{ error }}</p>{% endif %}
<div class="card">
  <h3>AI vision</h3>
  <form method="post" action="/services/ai">{{ m.csrf_field() }}
    {{ m.field('apiKey','api key', ai.apiKey, 'password') }}
    {{ m.field('baseUrl','base url', ai.baseUrl) }}
    {{ m.field('maxDim','max dim', ai.maxDim, 'number') }}
    {{ m.field('jpgQuality','jpg quality', ai.jpgQuality, 'number') }}
    {{ m.field('timeout','timeout (s)', ai.timeout, 'number') }}
    {{ m.field('reasoningEffort','reasoning effort', ai.reasoningEffort) }}
    {{ m.submit('Save AI') }}
  </form>
</div>
<div class="card">
  <h3>Paste</h3>
  <form method="post" action="/services/paste">{{ m.csrf_field() }}
    {{ m.field('host','host', paste.host) }}
    {{ m.field('key','key', paste.key, 'password') }}
    {{ m.submit('Save paste') }}
  </form>
</div>
<p class="muted">Consumers read these per-use — no restart needed.</p>
{% endblock %}
```

- [ ] **Step 4: Verify AiServiceConfig/PasteServiceConfig field names match**

Run: `rg "public " entities/AiServiceConfig.php entities/PasteServiceConfig.php`
Confirm the properties used in the template (`apiKey, baseUrl, maxDim, jpgQuality, timeout, reasoningEffort` / `host, key`) exist; fix any mismatch before committing.

- [ ] **Step 5: Manually verify + commit**

Set AI key via panel → linktitles vision works live. Then:
```bash
git add web/sections/services.php web/templates/services.twig web/routes.php
git commit -m "feat(web): services section (AI + paste global config)"
```

---

## Task 8: Linktitles section

**Files:**
- Create: `web/sections/linktitles.php`, `web/templates/linktitles.twig`
- Modify: `web/routes.php`

Per-network (+ optional channel override) settings via `setLinktitlesSetting(Network, ?Channel, key, value)` / `resetLinktitlesSetting(...)`. Keys: `enabled, urlLogChan, aiVisionModel, aiVisionPrompt, aiVisionReasoningEffort`. `enabled` reloads live; the rest read per-use.

- [ ] **Step 1: Write `web/sections/linktitles.php`**

```php
<?php
use scripts\linktitles\entities\linktitles_setting;

function web_linktitles(?string $error = null): never
{
    $app = web_app();
    $networks = $app['svc']->listNetworks();
    // One network-scoped row each (channel = null) for the common case.
    $rows = $app['em']->getRepository(linktitles_setting::class)->findBy(['channel' => null]);
    $byNet = [];
    foreach ($rows as $r) { $byNet[$r->network->id] = $r; }
    web_render('linktitles.twig', ['active' => 'linktitles', 'section' => 'Linktitles', 'networks' => $networks, 'byNet' => $byNet, 'error' => $error]);
}

function web_linktitles_save(int $netId): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_linktitles($e->getMessage()); }
    $net = $app['svc']->getNetwork($netId);
    if ($net === null) { http_response_code(404); echo "No such network"; exit; }
    // Checkbox: absent means false.
    $app['svc']->setLinktitlesSetting($net, null, 'enabled', array_key_exists('enabled', $_POST));
    foreach (['url_log_chan','ai_vision_model','ai_vision_prompt','ai_vision_reasoning_effort'] as $k) {
        $raw = trim(is_string($_POST[$k] ?? null) ? $_POST[$k] : '');
        if ($raw === '') { $app['svc']->resetLinktitlesSetting($net, null, $k); continue; }
        $app['svc']->setLinktitlesSetting($net, null, $k, $raw);
    }
    web_redirect('/linktitles');
}
```

- [ ] **Step 2: Wire routes**

Top: `require_once __DIR__ . '/sections/linktitles.php';`
In dispatch:
```php
    if ($method === 'GET' && $path === '/linktitles') { web_linktitles(); }
    if ($method === 'POST' && preg_match('#^/linktitles/(\d+)$#', $path, $m)) { web_linktitles_save((int)$m[1]); }
```

- [ ] **Step 3: Write `web/templates/linktitles.twig`**

```twig
{% extends 'base.twig' %}
{% import '_macros.twig' as m %}
{% block content %}
<h1>Linktitles</h1>
{% if error %}<p class="badge-bad">{{ error }}</p>{% endif %}
{% for n in networks %}
  {% set r = byNet[n.id] ?? null %}
  <div class="card">
    <h3>{{ n.name }}</h3>
    <form method="post" action="/linktitles/{{ n.id }}">{{ m.csrf_field() }}
      <div class="field"><label>enabled</label>
        <label><input type="checkbox" name="enabled" value="1" {{ r and r.enabled ? 'checked' }}></label></div>
      {{ m.field('url_log_chan','url log chan', r ? r.url_log_chan : '') }}
      {{ m.field('ai_vision_model','ai model', r ? r.ai_vision_model : '') }}
      {{ m.textarea('ai_vision_prompt','ai prompt', r ? r.ai_vision_prompt : '') }}
      {{ m.field('ai_vision_reasoning_effort','reasoning effort', r ? r.ai_vision_reasoning_effort : '') }}
      {{ m.submit('Save') }}
    </form>
  </div>
{% endfor %}
<p class="muted"><code>enabled</code> applies live; other fields are read per-use. Empty field = inherit/reset.</p>
{% endblock %}
```

- [ ] **Step 4: Verify `linktitles_setting` field names**

Run: `rg "public " scripts/linktitles/entities/linktitles_setting.php`
Confirm `enabled, urlLogChan, aiVisionModel, aiVisionPrompt, aiVisionReasoningEffort` exist; fix any mismatch (e.g. snake vs camel) before committing.

- [ ] **Step 5: Manually verify + commit**

Toggle enabled for a network → linktitles live-reloads (re-check via the bot reacting/​not reacting to a URL). Then:
```bash
git add web/sections/linktitles.php web/templates/linktitles.twig web/routes.php
git commit -m "feat(web): linktitles per-network settings (enabled live; rest per-use)"
```

---

## Task 9: Live Overview (Phase 2 dashboard)

**Files:**
- Create: `web/templates/overview.twig`, `web/templates/_status.twig`
- Modify: `web/routes.php` (overview route + status fragment), `web/sections/` (add `overview.php`)

- [ ] **Step 1: Write `web/sections/overview.php`**

```php
<?php
function web_overview(): never
{
    web_render('overview.twig', ['active' => 'overview', 'section' => 'Overview']);
}

// HTMX-polled fragment: fetches live status from the running bot.
function web_overview_status(): never
{
    $app = web_app();
    web_render_fragment('_status.twig', ['bots' => web_bot_status($app)]);
}
```

- [ ] **Step 2: Wire routes**

Top: `require_once __DIR__ . '/sections/overview.php';`
Change the home route to render the overview, and add the status fragment route. In dispatch replace `if ($method === 'GET' && ($path === '/' || $path === '')) { web_home(); }` with:
```php
    if ($method === 'GET' && ($path === '/' || $path === '')) { web_overview(); }
    if ($method === 'GET' && $path === '/_status') { web_overview_status(); }
```
Remove the now-unused `web_home()` (or leave it; the spec calls for overview as home).

- [ ] **Step 3: Write `web/templates/overview.twig`**

```twig
{% extends 'base.twig' %}
{% block content %}
<h1>Overview</h1>
<div id="status" hx-get="/_status" hx-trigger="load, every 5s" hx-swap="innerHTML">
  <p class="muted">loading live status…</p>
</div>
{% endblock %}
```

- [ ] **Step 4: Write `web/templates/_status.twig`**

```twig
{% if bots is empty %}
  <p class="badge-bad">No live status — bot not running or unreachable.</p>
{% else %}
  {% for b in bots %}
  <div class="card">
    <strong>{{ b.name }}</strong>
    <span class="{{ b.connected ? 'badge-ok' : 'badge-bad' }}">{{ b.connected ? '🟢 connected' : '🔴 disconnected' }}</span>
    <span class="muted">· nick {{ b.nick }} · {{ b.channels|length }} chans · {{ b.server }} · {{ b.network }}</span>
    <div style="margin-top:6px">
      {% for act in ['reconnect','jump','respawn'] %}
        <form class="inline" method="post" action="/bots/{{ b.id }}/{{ act }}" hx-post="/bots/{{ b.id }}/{{ act }}" hx-target="#actions-{{ b.id }}" hx-swap="innerHTML">
          <input type="hidden" name="_csrf" value="{{ csrf() }}"><button class="btn ghost" type="submit">{{ act }}</button>
        </form>
      {% endfor %}
      <span id="actions-{{ b.id }}"></span>
    </div>
  </div>
  {% endfor %}
{% endif %}
```

- [ ] **Step 5: Manually verify the live overview**

Bot running + panel open: home shows each bot's live nick/connected/channels/server, refreshing every 5s; reconnect/jump/respawn act on the bot. Confirm via IRC.

- [ ] **Step 6: Commit**

```bash
git add web/sections/overview.php web/templates/overview.twig web/templates/_status.twig web/routes.php
git commit -m "feat(web): live overview dashboard (HTMX-polled /_control/status)"
```

---

## Task 10: Docs + end-to-end

**Files:**
- Modify: `config.example.yaml` (web/serving note), `docs/config-service-migration-guide.md` (+ Sub-project 3 section)

- [ ] **Step 1: Document serving in `config.example.yaml`**

Add a comment block near the existing `listen`/`control_key` keys:
```yaml
# Web control panel (Sub-project 3): served from web/ out-of-process.
# Dev:   php -S 127.0.0.1:8088 -t web/ web/index.php
# Prod:  nginx + php-fpm (or a reverse proxy → php-fpm). The same web/index.php
#        runs under both. The panel reuses `control_key` for login (leave unset
#        only for loopback/SSH-tunnel access).
```

- [ ] **Step 2: Add Sub-project 3 section to the migration guide**

Append to `docs/config-service-migration-guide.md`:
- `composer require twig/twig` (already a dep after install).
- Dev run command; prod nginx + php-fpm snippet (root → web/, `try_files $uri /index.php;`, `fastcgi_pass` to fpm).
- Auth: set `control_key` to require login; leave unset only for loopback.
- The one new bot endpoint `GET /_control/status` (core-key auth).
- Note: panel mutates via `ConfigService` + the S2 push; no bot restart needed.

Include a concrete nginx server block:
```nginx
server {
    listen 443 ssl http2;
    server_name lolbot.panel.example;
    # ssl_certificate ...
    root /path/to/lolbot/web;
    location / { try_files $uri /index.php$is_args$args; }
    location ~ \.php$ { fastcgi_pass unix:/run/php/php-fpm.sock; include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param HTTPS on; }
}
```
(SCRIPT_FILENAME fixed to index.php so the front controller always handles routing; static assets match the `location /` try_files first.)

- [ ] **Step 3: Full end-to-end checklist**

With the bot running + `php -S` dev server + `control_key` set:
- [ ] `/` redirects to `/login`; wrong key rejected; correct key logs in.
- [ ] Overview shows live nick/connected/channels per bot, refreshing.
- [ ] Bots: add (spawns live), edit nick (changes live), add/remove channel (join/part live), delete (drops), reconnect/jump/respawn act.
- [ ] Networks: add/edit/delete; add/remove server (live jump).
- [ ] Ignores: add/delete (auto-applies within 5s).
- [ ] Services: set AI key (linktitles vision works), set paste host/key.
- [ ] Linktitles: toggle enabled (live-reloads).
- [ ] No restart used at any point.

- [ ] **Step 4: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: all green (836 prior + BotManagerStatus 3 + WebAuth 6 = 845).

- [ ] **Step 5: Commit**

```bash
git add config.example.yaml docs/config-service-migration-guide.md
git commit -m "docs(web): serving + ops guide for the control panel (Sub-project 3)"
```

---

## Out of scope (per spec)

- Per-bot servers (shelved TODO at `library/BotManager.php:80`).
- Art bot (`artbots.php`) — separate config model.
- `config:import` / `showdb` in the panel (admin-cli covers them).
- Auto-applying `onConnect`/`sasl`/`bindIp` without a respawn (respawn button by design).
- Live-reloading `listen`/`control_key` (bootstrap-level; the web entry reads them per request, but the bot's own `listen` needs a restart).
