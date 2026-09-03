# Disable Bots (per-bot and per-network kill-switch)

Date: 2026-09-03
Status: Approved (Approach A — independent booleans, OR semantics)

## Problem

There is no way to take a bot — or every bot on a network — offline without
deleting it. Operators need a temporary kill-switch: the bot should not connect
at startup, a running bot should QUIT when the switch is flipped, and
re-enabling should bring it back. Applies to the channel bot (`lolbot.php`)
only; the art bots (`artbots.php`) are yaml-configured and unaffected.

## Semantics

- `Bot.disabled` — bool, default `false`. When true, that bot is stopped.
- `Network.disabled` — bool, default `false`. When true, every bot on that
  network is stopped, regardless of the bot's own flag.
- Effective state: a bot is stopped iff `bot.disabled || network.disabled`.
  The two flags are independent; re-enabling a network restores only the bots
  that are not individually disabled. "Disabled" means **fully stopped**: no
  IRC connection at all (not merely silent).

## Components

### 1. Entities + migration

- `entities/Bot.php`: add `#[ORM\Column] public bool $disabled = false;`
  and a helper `isDisabled(): bool` returning
  `$this->disabled || $this->network->disabled`.
- `entities/Network.php`: add `#[ORM\Column] public bool $disabled = false;`.
- New migration `Migrations/Version20260903120000.php` adding NOT NULL
  `disabled` BOOLEAN columns (default false) to `Bots` and `Networks`, using
  the portable schema-comparator technique from `Version20260621120000.php`
  so ALTERs work on both SQLite (tests) and Postgres (prod). `down()` drops
  the columns.

### 2. Startup (lolbot.php)

In `main()`, skip spawning for networks where `disabled` is true, and for
bots where `isDisabled()` is true. A disabled bot never gets a client.

### 3. Live sync (BotManager::apply)

- `bot/create`: after fetching the new bot, spawn only if `!isDisabled()`.
- `bot/update`: refresh the entity; then
  - if `isDisabled()` → `drop()` (QUITs a live bot; no-op if not running);
  - elseif no live client for its id → `spawn()` (covers re-enable, since
    a disabled bot has no client; a bot update on an already-running,
    enabled bot keeps the existing `reloadBot()` path);
  - else → `reloadBot()` (unchanged).
- `network/update`: refresh network; then for each bot on it:
  - network (or bot) disabled → `drop()`;
  - enabled and not running → `spawn()` (covers network re-enable).
  The existing "refresh each bot entity" loop is replaced by this logic —
  the refresh is folded into the re-fetch.

### 4. CLI (admin-cli.php commands)

- `bot:set <id> disabled <true|false|1|0>` — add `disabled` to the settings
  whitelist. `bot_set` currently string-casts all values; give `disabled` a
  bool branch (`FILTER_VALIDATE_BOOLEAN`, rejecting anything that isn't a
  clean bool, mirroring `linktitles:set`).
- `network:set <id> disabled <true|false|1|0>` — same; `network_set` needs
  the same bool handling added to its currently-untyped assignment.
- Display: `Bot::__toString()` and `Network::__toString()` append
  `disabled` state, so `bot:list` / `network:list` / `showdb` show it with
  no command changes.

### 5. Web panel

- Bot edit form (`templates/bots/edit.twig` + `web/sections/bots.php`
  `web_bots_update`): `disabled` checkbox, parsed `isset($_POST['disabled'])`.
- Network edit form (`templates/networks/edit.twig` +
  `web_networks_update`): same.
- Bots list (`templates/bots/list.twig`): show a `disabled` badge; when the
  bot itself is enabled but its network is disabled, show
  `disabled (network)` via `bot.network.disabled`.
- Networks list (`templates/networks/list.twig`): `disabled` badge.
- Both mutations flow through `ConfigService::update()` → `ConfigChange`
  push → `BotManager::apply()`, so no new endpoints.

### 6. ConfigService

No new methods needed — `update()` already genericly persists + notifies.
Validation lives in the CLI/web layers (bool parsing), same as existing
settings.

## Error handling

- CLI bool parse failure → `InvalidArgumentException` (existing command
  error path).
- `apply()` drops/spawns are already exception-guarded by its try/catch.
- Startup with all bots disabled: bot runs with zero clients; control server
  still available to re-enable.

## Testing

Following `tests/Config/*` PHPUnit patterns (SQLite via `ConfigTestCase`):

- Entity: default `false` on both; `isDisabled()` true when either flag set.
- No separate migration test needed: `ConfigTestCase` builds the SQLite
  schema directly from entity metadata (`SchemaTool::createSchema`), so every
  entity/apply test exercises the new columns once they're mapped.
- `BotManagerApplyTest` additions:
  - `bot/update` with `disabled=true` drops the client;
  - `bot/update` re-enable spawns when previously disabled;
  - `network/update` with `disabled=true` drops all that network's bots;
  - `network/update` re-enable respawns only non-disabled bots;
  - `bot/create` with disabled bot does not spawn.
- CLI: `bot:set`/`network:set` accept `true/false/1/0`, reject garbage.

## Out of scope

- Art bots (`artbots.php`) — yaml config, separate system.
- Timed/scheduled re-enable.
- Any cascade/inherit tri-state semantics.
