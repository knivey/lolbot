# Web Control Panel (Sub-project 3)

## Problem

`admin-cli.php` is the only way to configure the bots. Issue #112 calls it "a crude tool
requiring u to use database IDs" and asks for a web frontend for controlling/configuring the
bots. Sub-project 1 made `admin-cli` a client of a shared `ConfigService` library, and
Sub-project 2 added live config sync (push changes to running bots without a restart). What's
missing is the friendly browser UI that sits on top of both — the third client of the library.

This is **Sub-project 3 of three** that together address issues #109, #110, #111, #112, #120:

| # | Sub-project | Issues | Depends on |
|---|---|---|---|
| 1 | Config service layer + config-in-DB | #110, #111, #120 | — |
| 2 | Live config sync | #109 | 1 |
| **3** | **Web control panel (this spec)** | **#112** | **1, 2** |

## Solution

A browser control panel — an **out-of-process PHP web entry** (`web/index.php`, a standard
front controller) that is a third client of the `ConfigService` library (like `admin-cli`),
rendered with **HTMX + Twig** (server-rendered HTML fragments, auto-escaping, no build step).
It gives an **integrated** view: an overview dashboard with live bot status + quick actions,
plus full CRUD for bots/channels/networks/servers/ignores/services/linktitles, and live
operational actions (reconnect/jump/respawn, join/part channels). Changes push live to the
running bots via the Sub-project-2 path; live runtime status is read from the bots via one
new read endpoint.

### Why out-of-process

The bot's S2 REST server runs on the **Amp event loop shared with the IRC clients**, and
`ConfigService`/Doctrine work is **blocking**. Serving the UI in-process would let heavy page
renders or DB queries stall IRC latency. A separate web entry keeps all blocking web work off
the IRC loop entirely, is available even when the bot is down (true to #111's "configure
changes before they are started"), and reuses the **exact** push path `admin-cli` already uses.
It is served by a standard production web environment (nginx + php-fpm) or the PHP built-in
server for dev — **the same front controller either way**.

```
                         ┌──────────────────────────────────────┐
reverse proxy (TLS) ────►│  web entry: web/index.php            │  standard PHP front controller
                         │   bootstrap.php → Doctrine EM        │  (fresh EM/request, like admin-cli)
                         │   ConfigService + HttpPushNotifier ──┼──┐  mutations auto-push apply
                         │   $_SESSION auth · CSRF              │  │
                         │   Twig templates + HTMX assets       │  │
                         └──────────────────────────────────────┘  │  + reads live status
                                                                   ▼
                         ┌──────────────────────────────────────┐
                         │  running bot: lolbot.php             │
                         │   S2 global Router server            │
                         │     POST /_control/apply          ──► BotManager::apply  (live join/part/spawn…)
                         │     POST /_control/reconnect|jump|respawn/{botid}         (S2, core-key auth)
                         │     GET  /_control/status   ◄── NEW ── JSON {connected,nick,channels,server}
                         │     /notifier/{botid}/…              │
                         └──────────────────────────────────────┘
```

## Design

### Scope: integrated panel

The panel mirrors `admin-cli`'s full surface, organized for browsing. Channels and servers are
**nested** (channels belong to a bot; servers belong to a network), not top-level.

| View | Operations | Live behaviour |
|---|---|---|
| **Overview** | All bots at a glance: network · connected · current nick · #chans · server | status fragment polls ~5s (web entry proxies the bot's `GET /_control/status`); quick **reconnect / jump / respawn** per bot |
| **Bots** | list · add · delete · edit (nick, trigger, sasl, bindIp, onConnect, server) | edits push `apply` (rename → nick changes live); **reconnect/jump/respawn** buttons |
| **Bot › Channels** | list · add · remove (bot-scoped) | add/remove → **live join/part** |
| **Networks** | list · add · edit (name) · delete · drill-in → its servers | edits push `apply` |
| **Network › Servers** | list · add · edit (host/port/ssl/password/throttle) · delete | server change → **live jump** |
| **Ignores** | list · add mask · add network-ignore · delete · **test** (match a hostmask) | 5s TTL cache auto-applies (no action) |
| **Services** | AI (apiKey, baseUrl, maxDim, jpgQuality, timeout, reasoning…) · paste (host, key) — view + edit | consumers read DB per-use (no action) |
| **Linktitles** | per network (+ optional channel override): enabled, urlLogChan, aiVisionModel/Prompt/Reasoning… + its own host/ignore lists | `enabled` reload live; rest read per-use |

Deferred out of v1 (YAGNI — `admin-cli` still covers them): `config:import` (one-time) as a
button only if trivial; `showdb` (raw debug dump). Live `onConnect`/`sasl`/`bindIp` changes
still require a **respawn** by design (same as S2) — the button is there; no auto-magic.

### Serving model

- `web/index.php` is a standard front controller that runs identically under **php-fpm** (nginx
  serves static assets, `try_files` falls back to `index.php`, `fastcgi_pass` to fpm) and the
  **PHP built-in server** for dev (`php -S 127.0.0.1:PORT -t web/ web/index.php`; the router
  returns `false` for real files so the built-in server serves them, and handles routing
  otherwise). One entry point, both runtimes.
- The entry includes `bootstrap.php` (Doctrine EM + parsed `config.yaml`), exactly like
  `admin-cli.php`. It does **not** start the Amp event loop or any bot. `dieIfPendingMigration()`
  still guards it (the panel should not serve with pending migrations).
- `ConfigService` is constructed with the S2 `HttpPushChangeNotifier` (the `build_change_notifier()`
  factory, same as `admin-cli`), so every successful mutation auto-pushes `apply` to the running
  bot — instant live join/part/rename/jump.

### Frontend: HTMX + Twig

- **Twig** templates under `web/templates/`, with **auto-escaping on by default**. The panel
  renders untrusted-ish IRC strings everywhere (nicks, channel names, ignore masks, linktitles
  prompts, `onConnect` commands); auto-escaping removes the per-interpolation `htmlspecialchars`
  footgun. Added as the **standalone `twig/twig` library** (this is a custom front controller, not
  a Symfony app — do **not** pull `symfony/twig-bundle`); a `Twig\Environment` with a filesystem
  loader on `web/templates` is constructed in the entry. `base.twig` provides the layout (topbar +
  sidebar + `{% block content %}`); HTMX swap targets are partial templates (conventionally
  `_`-prefixed, e.g. `_row.twig`, `_actions.twig`).
- **HTMX** (`web/assets/htmx.min.js`, vendored — **no build step**, self-hosted so it works
  offline/behind the proxy). `hx-*` attributes drive partial swaps: reconnect refreshes its
  fragment, add-channel appears inline, the status fragment polls, etc. A small amount of
  vanilla JS only where HTMX alone is insufficient.
- **Visual style:** dark control-panel theme; a small `web/assets/panel.css`.

### Layout & navigation

Left sidebar (Overview, Bots, Networks, Ignores, Services, Linktitles) + top auth bar (panel
name, operator, logout). Main area is the active view. Overview = one card per bot (live status
+ reconnect/jump/respawn); Bot edit = form fields + live join/part channel chips + Save/Delete.

### Auth

Driven by the existing top-level `control_key` (shared with S2's `/_control/*`):

- **`control_key` unset → panel is open.** Intended for loopback / SSH-tunnel access. Behind a
  reverse proxy (or bound to a non-loopback interface) the operator is expected to set a key.
- **`control_key` set → login required.** `GET /login` (enter the key) → verify with
  `hash_equals` → `$_SESSION['authed'] = true` + regenerate the session id. All panel routes sit
  behind `require_auth()`. `GET /logout` clears it.
- **CSRF:** double-submit token — `csrf_token()` stored in the session and rendered as a hidden
  field on every form; `verify_csrf()` on every POST. Rejects on mismatch.
- **Reverse-proxy aware:** `session.cookie_secure` follows `X-Forwarded-Proto: https`;
  `cookie_httponly = on`; `cookie_samesite = "lax"`.

### New bot-side endpoint: `GET /_control/status`

The one new piece the out-of-process UI cannot get from the DB: **live runtime state** that
lives in `BotManager`'s memory.

- **Auth:** core `key` header (`control_key`), same as the other `/_control/*` routes.
- **Returns JSON:** `{ bots: [ { id, name, network, connected: bool, nick, channels: [...], server: "host:port[ ssl]" } ] }`.
- The web entry fetches it **server-side** (with the key) to render the overview status
  fragment; HTMX polls that fragment roughly every 5s.

### New accessor: `BotManager::botStatus()`

- `BotManager::botStatus(int $botId): ?array` (and an `allBotStatuses(): array` convenience)
  read straight from the live `\Irc\Client` map — connected, current nick, joined channels,
  current server. Pure read; no mutation. Used by the new `/_control/status` handler.

### Mutations & live actions

- **Config writes** (CRUD across all sections) → `ConfigService` (validate + DB write) which, via
  its `HttpPushChangeNotifier`, pushes `apply` to the running bot → live join/part/rename/jump.
  Identical path to `admin-cli`.
- **Operational actions** (reconnect/jump/respawn) → the web entry POSTs to the existing S2
  `POST /_control/{reconnect|jump|respawn}/{botid}` endpoints with the `key` header; the handler
  returns a refreshed fragment (or a small status toast).
- **Channel add/remove** → `ConfigService` writes the channel + pushes `apply` → live join/part.

### Two client types, two auth lanes (unchanged)

- **Browser panel** → session-cookie auth on its routes (`/`, `/bots`, …).
- **Programmatic clients** (`admin-cli`) → `key` header on `/_control/*`. Unchanged from S2.

### File layout

```
web/
  index.php                 front controller (php -S router AND php-fpm)
  routes.php                PATH_INFO → handler dispatch
  auth.php                  session + login + require_auth() + csrf_token()/verify_csrf()
  assets/
    htmx.min.js             vendored HTMX (no build)
    panel.css               dark control-panel theme
  templates/
    base.twig               layout: topbar + sidebar + {% block content %}
    overview.twig, login.twig
    bots/{list,edit}.twig + fragments _row.twig, _channels.twig, _actions.twig
    networks/, ignores/, services/, linktitles/   (same list/edit + fragments pattern)
library/BotManager.php      +botStatus(int): ?array , +allBotStatuses(): array
lolbot.php                  +GET /_control/status  (JSON, core-key auth)
config.example.yaml         document: web entry served behind nginx/php-fpm or php -S
docs/config-service-migration-guide.md   + Sub-project 3 ops section
```

`web/` is source (committed), including the vendored `htmx.min.js`. The web entry does **not**
add a PSR-4 autoload entry — it includes `bootstrap.php` and uses explicit `require`s for its
own modules, like `admin-cli.php` and the `cli_cmds` do.

### Error handling

- `ConfigService` throws typed domain exceptions (`EntityNotFound`, `InvalidSetting`,
  `DuplicateName`); the web handlers catch them and re-render the form with an error message
  (Twig fragment swap), mirroring how `admin-cli` maps them to friendly console errors.
- `/_control/status`: unreachable/unknown bot id omitted from the list (never 5xx the read); auth
  failure → 403.
- `HttpPushChangeNotifier`: unreachable bot → log and move on (change is already persisted); same
  best-effort semantics as `admin-cli`.
- Live-action POSTs to the bot that fail return a non-fatal error fragment; one bot's failure
  does not abort the page.

### Testing

PHPUnit (SQLite, per repo convention):

- `BotManager::botStatus()` / `allBotStatuses()` with a mocked `\Irc\Client` map — assert the
  right connected/nick/channels/server payload per bot.
- Auth + CSRF helpers: `hash_equals` login verify (correct/incorrect key), session set on
  success, CSRF token round-trip, rejected bad token.
- `ConfigService` coverage carries over from Sub-project 1 (no new mutation logic here — the web
  entry calls the same methods).
- Full HTTP-handler testing is limited (no PSR-15 / Symfony HttpKernel test harness in-repo);
  covered by manual end-to-end (below).

### End-to-end (manual, like Sub-project 2)

- Start the bot; start the web entry (`php -S` dev) + point a browser at it.
- Wrong/empty key behaves per the auth model; correct key logs in.
- Add a channel via the panel → bot JOINs on IRC live; remove → PARTs.
- Edit a bot's nick → nick changes live; edit a server → bot jumps.
- Hit reconnect/jump/respawn → bot does so; Overview reflects live nick/connected/channels.
- Restart not required at any point.

## Sequencing (for the implementation plan)

1. **Bot side:** `BotManager::botStatus()`/`allBotStatuses()` + `GET /_control/status` route +
   tests.
2. **Web scaffold:** `web/index.php` front controller + `routes.php` + `auth.php` (session,
   login, CSRF) + Twig bootstrap + HTMX/panel.css assets + `base.twig` layout.
3. **Config CRUD (Phase 1):** Bots (+ Channels), Networks (+ Servers), Ignores, Services,
   Linktitles — list/edit/fragments, mutations via `ConfigService` + `HttpPushChangeNotifier`,
   per-bot live-action buttons (reconnect/jump/respawn).
4. **Live Overview (Phase 2):** status fragment polling the bot's `/_control/status`, per-bot
   cards, quick actions.
5. **Docs:** `config.example.yaml` note + migration-guide Sub-project 3 section; manual E2E.

## Out of scope

- **Per-bot servers** — the standing TODO at `library/BotManager.php:80` ("check for per bot
  servers first") is deliberately **shelved**. Servers remain network-level (current schema:
  `Server` → `ManyToOne Network`). A future nullable-`bot_id` change would add per-bot pools
  with a network-shared fallback; not now.
- **Art bot** (`artbots.php`): separate config model; would need its config migrated first.
- **`config:import` / `showdb`** in the panel: `admin-cli` already covers these; a button only if
  trivial.
- **Auto-applying `onConnect`/`sasl`/`bindIp`** without a respawn (those run at connect time; the
  manual `/_control/respawn/{botid}` covers them by design — same as S2).
- **Live-reloading `listen`/`control_key`** (bootstrap-level; the web entry reads them at
  request time from `config.yaml`, so a change takes effect on the next request without a bot
  restart, but the bot's own `listen` still needs a restart).
- **A JSON API / SPA framework / Hotwire:** considered and set aside in favour of HTMX + Twig for
  this interaction model and stack.
