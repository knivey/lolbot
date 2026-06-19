# Live Config Sync (Sub-project 2)

## Problem

Sub-project 1 moved configuration into the database and made `admin-cli.php` a
front-end over `ConfigService`, but every change still requires restarting the bots
to take effect. Issue #109 asks for an API so that adding/removing a channel, bot,
network, server, or changing a setting is reflected by the running bots
immediately — "same for networks new bots etc."

Today each bot runs its own notifier HTTP server (`scripts/notifier/notifier.php`)
purely so outside apps can push `PRIVMSG`s / Owncast webhooks through the bot. There
is no mechanism for config changes to reach the running bot process.

## Solution

Add a **global Router-based REST server** in the core channel bot (`lolbot.php`),
expose **control endpoints** that the mutating client (`admin-cli.php`, later the
web UI) pushes `ConfigChange`s to over HTTP, and have a **`BotManager`** apply those
changes live to the running `\Irc\Client`s — joining/parting channels, renaming,
jumping servers, and spawning/dropping bots without a restart.

This is **Sub-project 2 of three**. It depends on Sub-project 1's `ConfigService`
+ `ChangeNotifier` seam. Scope is the **channel bot (`lolbot.php`) only** — the art
bot (`artbots.php`) is untouched (its config isn't DB-backed yet; it keeps its own
`artbot_rest_server`).

| # | Sub-project | Issues | Depends on |
|---|---|---|---|
| 1 | Config service layer + config-in-DB | #110, #111, #120 | — |
| **2** | **Live config sync (this spec)** | **#109** | **Sub-project 1** |
| 3 | Web control panel | #112 | Sub-project 1 |

### Why HTTP push (not polling / LISTEN-NOTIFY)

The mutating client (a short-lived `admin-cli` process, or a future web request)
pushes the change to the running bot process over HTTP. This needs no daemon, no
extra process to supervise, works the moment the bot is running, and fails
graceously (if the bot is down, the change persists in the DB and applies on next
start). Polling adds lag and wasted queries; Postgres `LISTEN/NOTIFY` would be
pgsql-only and doesn't fit the SQLite support.

## Design

### Architecture

```
admin-cli.php ─┐                                                ┌─ /notifier/{botid}/privmsg/{chan}  (notifier key)
(web #112)   ─┤    ConfigService → HttpPushChangeNotifier        │  /notifier/{botid}/owncast/{chan}  (notifier key)
               │     POSTs {entityType,id,action}                │
               └──── http POST ──────────────────────────────►  lolbot.php global REST server (single `listen`)
                       (core key header)                          │  ┌─ /_control/apply              (core key)
                                                                  └──┤  /_control/reconnect/{botid}   (core key)
                                                                     │  /_control/jump/{botid}         (core key)
                                                                     │  /_control/respawn/{botid}      (core key)
                                                                     │
                                                                  BotManager.apply(ConfigChange) → live join/part/rename/jump/spawn/drop/reload
```

- One global Router HTTP server in `lolbot.php`, bound to a single new top-level
  `config.yaml` key `listen` (optional `listen_cert` for TLS). Modeled on
  `artbot_rest_server` (`addRoute($method,$uri,$handler)`).
- Scripts register their own routes on it. The notifier registers
  `/notifier/{botid}/...` (keeps its `notifier_keys.yaml`). Other scripts can do
  the same later.
- Two separate key scopes: a **core/control key** (new top-level `config.yaml`
  `control_key`) authorizes the `/_control/*` endpoints; the notifier key
  authorizes `/notifier/*`. Each route handler checks its own `key` header.
- `listen` should be `127.0.0.1:<port>` — the control surface stays localhost.

### Routes

| Method + path | Key | Purpose |
|---|---|---|
| `POST /_control/apply` | core | Receive a `ConfigChange` payload `{entityType, id, action}`, dispatch live-apply. |
| `POST /_control/reconnect/{botid}` | core | Reconnect bot to its **current** server. |
| `POST /_control/jump/{botid}` | core | Rotate bot to the **next** server (`selectServer()` + `setServer()` + `reconnect()`). |
| `POST /_control/respawn/{botid}` | core | Full drop + spawn (re-runs setup; for sasl/bindIp/onConnect changes). |
| `POST /notifier/{botid}/privmsg/{chan}` | notifier | Send a PRIVMSG from `{botid}` to `{chan}` (existing notifier feature). |
| `POST /notifier/{botid}/owncast/{chan}` | notifier | Owncast webhook → PRIVMSG (existing notifier feature). |

### Components

**`HttpPushChangeNotifier`** (`library/config/`, implements `ChangeNotifier`) — the
Sub-project-1 seam's real implementation. Constructed with the bot process's
`listen` URL + `control_key` (read from `config.yaml` by the mutating client).
On `notify(ConfigChange $c)`: POST `{entityType, id, action}` to
`POST /_control/apply` with the `key: <control_key>` header. Fire-and-forget; if
the bot is unreachable, log and move on (the change is already persisted). This
replaces the Sub-project-1 default `NoopChangeNotifier` for CLI/web use.

**`BotManager`** (refactor of `lolbot.php`'s `main()`/`startBot()` into a class)
owns the `botId → \Irc\Client` map (plus the bot entities and per-bot state). It
exposes the live-action primitives and the `apply(ConfigChange)` dispatcher:

- `spawn(Network $network, Bot $bot)` — the existing `startBot` machinery, callable at runtime; stores the client.
- `drop(int $botId)` — `sendNow("quit …")` + `$client->exit()` + unset.
- `respawn(int $botId)` — `drop` + `spawn` (re-reads the entity → picks up new server/settings/setup).
- `reconnect(int $botId)` — `$client->reconnect()` (current server).
- `jump(int $botId)` — `$network->selectServer()` + `$client->setServer(...)` + `$client->reconnect()`.
- `joinChannel(int $botId, string $chan)` / `partChannel(int $botId, string $chan)`.
- `reloadBot(int $botId)` — `$em->refresh($bot)` then apply live-able fields (nick via `setNick`, trigger reads live).
- `reloadLinktitlesEnabled(int $botId)` — re-resolve via `SettingsResolver` and update the per-bot mutable holder.
- `apply(ConfigChange $c)` — resolve the change to affected bot id(s) (see matrix) and call the right primitive(s).

The bot entity, the nick-retry loop closure, and the chat-handler closure all
reference the **same managed entity object** held by `BotManager`, so
`$em->refresh($bot)` propagates new `name`/`trigger`/etc. to them with no respawn.

**`Irc\Client` additions** (the bot's own fork — server/port/ssl are currently
constructor-only, and `go()` reconnects to `$this->server` after a socket close):
- `setServer(string $server, string $port, bool $ssl, ?string $password, bool $throttle)` —
  update the stored server/port/ssl/password/throttle and rebuild the connect context (bindIP).
- `reconnect()` — close the current socket; the existing `go()` reconnect loop then
  reconnects to the now-updated `$this->server` (ZNC-style jump, scripts/handlers persist).

**Notifier refactor** (`scripts/notifier/notifier.php`) — changes from "start its
own per-bot server" to `registerNotifierRoutes($server, $clients)`: registers the
two `/notifier/{botid}/...` routes, each checking `notifier_keys.yaml` and resolving
`$clients[$botid]`. `lolbot.php` passes the global server to it.

**`ConfigService` additions** (carry-forward): `deleteLinktitlesSettingScope(Network, ?Channel)`
(removes the whole scope row + `notify`) so `linktitles:set`'s whole-row reset/inherit
semantics can route through `ConfigService` and fire `notify`.

### Hot-apply matrix

`BotManager::apply(ConfigChange)` resolves the change to affected bot id(s) from the
DB, then acts. Most settings are **already live** because their consumers read the DB
on each use (done in Sub-project 1); only `linktitles enabled` was captured at startup
and gets a mutable holder.

| Change (`entityType`/action) | Affected bots | Live action |
|---|---|---|
| `channel` create/delete | `chan.bot_id` | `joinChannel` / `partChannel` |
| `bot` create | the new bot id | `spawn` |
| `bot` delete | the bot id | `drop` |
| `bot` update | the bot id | `reloadBot` (refresh entity; `setNick` if name changed; trigger reads live). sasl/bindIp/onConnect do **not** auto-apply — log "use /_control/respawn/{botid}". |
| `network` update | bots on network | refresh entity (no structural action) |
| `server` create/delete/update | bots on that server's network | per bot: `selectServer()` + `setServer(...)` + `reconnect()` (jump; no respawn) |
| `ignore` create/delete/update | (none) | no action — ignore cache is 5s TTL (`lolbot.php:146`), auto-applies |
| `service:*` update | all bots | no action — consumers read DB per-use |
| `linktitles_setting` update | its network's bots | `reloadLinktitlesEnabled` on any linktitles_setting change (re-resolves the mutable holder; idempotent — other linktitles fields are already live per-use) |

### `ConfigChange` resolution & payload

`ConfigChange` stays as the Sub-project-1 DTO (`entityType`, `id`, `action`). The
**bot-side** `/_control/apply` handler resolves it to affected bot id(s) using its
own DB view (e.g. load channel → bot; load network → its bots; load server →
network → bots), then calls `BotManager::apply`. Payload stays minimal; live state
lives where the bots do.

### Auth

- Core key: `config.yaml` top-level `control_key`. Every `/_control/*` handler
  checks the `key` request header against it.
- Notifier key: `notifier_keys.yaml` (unchanged). `/notifier/*` handlers check it.
- Distinct keys ⇒ distinct permission scopes (core = full bot control; notifier =
  `PRIVMSG` only).

### Config

- **Add** top-level `config.yaml`: `listen` (e.g. `"127.0.0.1:1339"`), `control_key`.
- **Remove** the now-dead per-bot `bots.<id>.listen` (the notifier no longer starts
  per-bot servers).
- These are bootstrap-level (like `database`), so they stay in `config.yaml`, not the DB.

### Carry-forward from Sub-project 1

- Route `scripts/linktitles/cli_cmds/linktitles_set.php` through `ConfigService`
  (`setLinktitlesSetting` + the new `deleteLinktitlesSettingScope` for reset/inherit)
  so its mutations fire `notify` and reach the running bot.

### Error handling

- `HttpPushChangeNotifier`: unreachable bot → log + move on (change already in DB).
- `/_control/apply`: unknown/unsupported `ConfigChange` → 200 with a "no-op / restart
  needed" note (never 5xx the push for a benign change). Auth failure → 403. Unknown
  bot id → 404.
- `setServer`/`reconnect`/`spawn`/`drop` failures are caught and logged; one bot's
  failure doesn't abort the whole apply.

### Testing

- `BotManager::apply` dispatch: with a mock `Irc\Client` map, feed `ConfigChange`s
  for each row of the matrix; assert the right primitive (join/part/spawn/drop/
  reload/reconnect) is called on the right bot(s). Includes the change→affected-bots
  resolution.
- `HttpPushChangeNotifier`: mock the HTTP client; assert it POSTs the right payload
  + core-key header to `/_control/apply`, and tolerates connection failure.
- New `Irc\Client::setServer`/`reconnect`: covered by integration/manual smoke
  (socket-level; hard to unit-test) — verify a `jump` reconnects to the next server.
- Existing `tests/Config/` (Sub-project 1) stays green.

## Sequencing (for the implementation plan)

1. `Irc\Client`: add `setServer()` + `reconnect()` (+ a small test if feasible).
2. `HttpPushChangeNotifier` + tests (the push side).
3. Global REST server in `lolbot.php` (Router, `listen`, `control_key`, route
   registration API) + the `/_control/*` handlers wired to (4).
4. `BotManager` refactor of `main()`/`startBot()` (spawn/drop/respawn/reconnect/
   jump/join/part/reload + `apply(ConfigChange)` dispatcher) + dispatch tests.
5. Notifier refactor: `registerNotifierRoutes` on the global server; remove per-bot
   notifier startup.
6. Wire `HttpPushChangeNotifier` into `admin-cli.php` (replace `NoopChangeNotifier`).
7. Carry-forward: `ConfigService::deleteLinktitlesSettingScope` + route
   `linktitles:set` through `ConfigService`.
8. Config: add `listen`/`control_key`, remove per-bot `listen`; update
   `config.example.yaml` + the migration guide.
9. End-to-end: add a channel via CLI → bot joins live; change a server → bot jumps;
   rename a bot → nick changes; `respawn`/`reconnect`/`jump` endpoints; restart not
   required.

## Out of scope

- **Art bot** (`artbots.php`): separate config model; would need its config migrated first.
- **Web UI** (#112, Sub-project 3): builds over `ConfigService` + the same push.
- Auto-applying `onConnect`/`sasl`/`bindIp` without a respawn (those run at connect
  time; the manual `/_control/respawn/{botid}` covers them by design).
- Live-reloading the global `listen`/`control_key` (bootstrap-level; needs restart).
