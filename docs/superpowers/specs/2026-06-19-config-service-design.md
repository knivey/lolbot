# Config Service Layer & Config-in-DB (Sub-project 1)

## Problem

Today the bot's configuration is split awkwardly across `config.yaml` and the database, and
the only way to mutate it at runtime is `admin-cli.php` writing the DB directly — after which
the running bots are unaware of the change until restarted.

Concretely:

- **Per-bot config still lives in `config.yaml`** under a `bots:` block indexed by DB id
  (`linktitles`, `codesand`, `youtube_thumb`, `pump_host`, `listen`, etc.). Editing YAML requires a
  restart. (GitHub issue #110: "move per bot settings out of config.yaml into database".)
- **`admin-cli.php` is a crude direct-DB tool** that uses database IDs and gives no path to a
  friendlier UI. (GitHub issue #112: "much nicer than the current admin-cli which is a crude tool
  requiring u to use database IDs".)
- **There is no backend the CLI (or a future web UI) is a frontend for.** Issue #111 asks that
  `admin-cli.php` become "just a frontend for a backend service that makes the changes" and that the
  backend "shouldn't be dependent on the bots running as we should be able to configure changes
  before they are started".
- **AI config is mis-placed.** AI vision settings are global `ai_vision_*` keys consumed only by
  `linktitles`. Issue #120 ("AI from linktitles consider creating a services architecture") wants AI
  to become a shared *service* other scripts can reuse, "probably other things in the bot could use
  this design too".

Real-time notification of running bots (issue #109) is intentionally **out of scope** for this
sub-project and is tracked separately as Sub-project 2.

## Solution

Introduce a **shared `ConfigService` library** (no new long-running process) that owns all
configuration mutations and validation, with the database as the single source of truth.
`admin-cli.php` becomes a client of this library; the future web UI (#112) will be too. Move the
per-bot and shared-capability settings that are ready out of `config.yaml` into the database using a
deliberate **three-tier settings taxonomy**, and establish a **services framework** so that AI
(config-driven by #120) and paste land in their own global service configs from day one — avoiding a
messy migration later.

This is **Sub-project 1 of three** that together address issues #109, #110, #111, #112, and #120:

| # | Sub-project | Issues | Depends on |
|---|---|---|---|
| **1** | **Config service layer + config-in-DB (this spec)** | #110, #111, #120 (data model) | — |
| 2 | Live config sync — push changes to running bots; consolidate the notifier to one global listen with per-bot-id routes; bot-side hot-apply (join/part, reload, spawn/drop). | #109 | Sub-project 1 |
| 3 | Web control panel — browser UI over the same library. | #112 | Sub-project 1 |

### Why a library, not a daemon

Nothing in #109/#110/#111/#112 requires a central long-running process:

- Mutations are done by a `ConfigService` library wrapping the `EntityManager`. `admin-cli` and the
  web entry both call it.
- Bots read config via the library's resolver against the DB — exactly how they already read
  networks/channels today (`lolbot.php:375`).
- Live push (#109) is *originated by the mutating client* right after the DB write, sent to each
  bot's notifier endpoint on localhost. No event hub needed. (Implemented in Sub-project 2.)
- The web UI (#112) is an ordinary PHP web entry over the same library — standard hosting, not a
  custom daemon to supervise.

So there is **no new service to run**. The DB is the source of truth; the library is the access
layer; CLI/web are entry points.

```
admin-cli.php ──┐                                            ┌── bots read on startup via
web entry (#112)┤──► ConfigService (library) ──► EM ──► DB ◄─┤   SettingsResolver (library)
                │   (mutations + validation)                │   (replaces $config['bots'][$id])
                │        │                                   └── ServiceLocator for AI/paste
                │        └─► ChangeNotifier::notify(change)  [no-op now; HTTP push in Sub-project 2]
```

## Design

### Settings taxonomy

Three ownership tiers. Where a setting lives is decided per-setting:

| Tier | Stored where | Scope | Examples |
|---|---|---|---|
| **Core** attribute | real column on the entity | bot / network / channel | (none migrated in this sub-project; e.g. `pump_host`/`pump_key` will land here when codesand migrates) |
| **Service** config | typed entity per service type, behind a `ServiceLocator` | **global** (single row) | AI service (`ai_vision_key`, `base_url`, …); paste service (`paste_host`, `paste_key`) |
| **Script** config | per-script typed table (`linktitles_setting` precedent) | network + optional channel override | linktitles: `enabled`, `url_log_chan`, `ai_vision_model`, `ai_vision_prompt`, reasoning overrides |

Services are global today (every existing API key lives at the top of `config.yaml`). If per-network
scoping is ever needed it is an additive migration (nullable `network_id`), not a messy one.

#### Resolution rule (service defaults vs. consumer overrides)

A *service* holds **global defaults**; a *consumer* (script) may override selected knobs in its own
script settings. The resolver merges **consumer override → service default → code constant**.

For the AI service / linktitles specifically:

| Knob | Layer(s) | Resolution |
|---|---|---|
| `apiKey`, `baseUrl`, `maxDim`, `jpgQuality`, `timeout` | AI service only | service value; absent key → feature disabled |
| `reasoningEffort`, `reasoning` | AI service default **+** linktitles override | linktitles override → service default → null |
| `model`, `prompt` | linktitles only | linktitles setting → code constant (`gpt-4o`, `linktitles::defaultVisionPrompt`) |
| `enabled`, `urlLogChan`, `aiVisionDisabled` | linktitles only | linktitles setting → default |

A script *depends on* a service and reads the service's shared config (key/endpoint) through the
`ServiceLocator` for its scope, while keeping its own behavioral knobs (model choice, prompt,
reasoning override) in its script table.

### File layout

| File / directory | Change |
|---|---|
| `library/config/ConfigService.php` | **New.** Domain layer over the `EntityManager`. All config mutations + validation. Namespace `lolbot\config`. |
| `library/config/SettingsResolver.php` | **New.** Builds a per-bot config object from DB (core cols + service configs via locator + script settings), merged with `config.yaml` legacy fallback for un-migrated scripts. Implements the override→service-default→constant rule. |
| `library/config/ServiceLocator.php` | **New.** Resolves global service configs by type (`'ai'` → `AiServiceConfig` or null, `'paste'` → `PasteServiceConfig` or null). Small registry mapping type → entity class + accessor. |
| `library/config/ChangeNotifier.php` | **New.** Interface with `notify(ConfigChange $c): void`. Default `NoopChangeNotifier` impl. `ConfigService` calls it after every flush. Sub-project 2 provides the HTTP-push implementation. |
| `library/config/ConfigChange.php` | **New.** Small DTO describing what changed (entity type, id, action) for the notifier. |
| `entities/AiServiceConfig.php` | **New.** Global AI service config entity. |
| `entities/PasteServiceConfig.php` | **New.** Global paste service config entity. |
| `scripts/linktitles/entities/linktitles_setting.php` | **Edited.** Add columns: `enabled` (bool), `urlLogChan` (str nullable), `aiVisionModel` (str nullable), `aiVisionPrompt` (text nullable), `aiVisionReasoningEffort` (str nullable), `aiVisionReasoning` (json nullable). Nullable means "not set" → inherit the next resolution layer (AI service default for the reasoning columns; code constant for model/prompt; default otherwise). Keep `ai_vision_disabled`. |
| `cli_cmds/*.php` | **Edited.** Each command (bot:add, bot:set, bot:addchannel, bot:delchannel, network:*, server:*, ignore:*, showdb) calls `ConfigService` instead of using `$entityManager` directly. |
| `cli_cmds/config_import.php` | **New.** `config:import` — one-time, idempotent import of `config.yaml` values into the new tables (`--force` to clobber). |
| `cli_cmds/service_get.php`, `cli_cmds/service_set.php`, `cli_cmds/service_list.php` | **New.** `service:get`, `service:set`, `service:list` for AI/paste service config. |
| `scripts/linktitles/cli_cmds/linktitles_set.php` | **Edited.** `settings` list gains `enabled`, `url_log_chan`, `ai_vision_model`, `ai_vision_prompt`, `ai_vision_reasoning_effort`, `ai_vision_reasoning` (with `inherit`/reset to fall back to the next resolution layer — AI service default for reasoning, code constant for model/prompt). |
| `admin-cli.php` | **Edited.** Register the new commands. Doctrine migration commands unchanged (still direct EM). |
| `lolbot.php` | **Edited.** `main()`/`startBot()` build per-bot config via `SettingsResolver` instead of `$config['bots'][$id]`; `:295` (linktitles enable) reads `linktitles_setting.enabled`. Notifier setup (`:379-382`) is **not** touched here (deferred to Sub-project 2). |
| `artbots.php` | **Not changed (this sub-project).** The art bot uses a separate config model — `artbotsconfig.yaml`'s `networks:[]` array consumed via `NetworkContext` (per-network `route`/`trigger`/`channels`/`onconnect`), not `$config['bots'][$id]`. It does bootstrap Doctrine, so its `artbot_scripts/help.php` paste read migrates with the others (see next row). Migrating the art bot's own config model is a separate future concern. |
| `scripts/linktitles/linktitles.php` | **Edited.** AI read from `ServiceLocator->getServiceConfig('ai')` (`:235-286`); model/prompt from linktitles settings (`:273-274`); reasoning as linktitles-override ?? AI-service-default (`:277-286`). |
| `scripts/help/help.php`, `artbot_scripts/help.php`, `scripts/alias/alias.php` | **Edited.** Read paste host/key from `ServiceLocator->getServiceConfig('paste')` (`help.php:73,78`, `alias.php:126,129`). |
| `library/paste.php` | **Unchanged.** `createPaste($content, $title, $host, $key)` signature stays; callers fetch host/key from the service. |
| `bootstrap.php` | **Edited.** Register the new entity directories in `$paths` if needed (AI/paste entities live under `entities/`, already registered). Add `lolbot\config\` → `library/config` to `composer.json` PSR-4. |
| `composer.json` | **Edited.** Add PSR-4 `"lolbot\\config\\": "library/config"`. |
| `Migrations/Version*.php` | **New migration.** Creates `AiServiceConfig`/`PasteServiceConfig` tables; expands `linktitles_settings` columns. |
| `config.yaml` / `config.example.yaml` | **Edited.** Remove migrated keys (`ai_vision_*`, `paste_host`, `paste_key`, and the migrated `bots.<id>` keys `linktitles`, `url_log_chan`). Document the transitional state. |

The `entities/` PSR-4 root (`lolbot\entities\`) already covers the new service entities; no autoload
entry is needed for them. Only `lolbot\config\` → `library/config` is new.

### Data model

No **Core** columns are added in this sub-project (`listen` consolidation is deferred to
Sub-project 2; `pump_*` defers with codesand). DB additions are:

- **`AiServiceConfig`** (global, single row): `apiKey` (str nullable), `baseUrl` (str nullable),
  `maxDim` (int), `jpgQuality` (int), `timeout` (int), `reasoningEffort` (str nullable),
  `reasoning` (json nullable). Defaults live as code constants (e.g. `maxDim` 1024, `jpgQuality` 85,
  `timeout` 10 — matching today's `??` fallbacks); absent `apiKey` → AI vision disabled (matches
  today's `!isset($config['ai_vision_key'])` guard at `linktitles.php:235`).
- **`PasteServiceConfig`** (global, single row): `host` (str nullable), `key` (str nullable).
  Absent host/key → paste disabled (matches today's `!isset` guard at `help.php:73`).
- **`linktitles_settings`** expansion (existing table, network + optional channel scope): add
  `enabled` (bool, default false), `urlLogChan` (str nullable), `aiVisionModel` (str nullable),
  `aiVisionPrompt` (text nullable), `aiVisionReasoningEffort` (str nullable), `aiVisionReasoning`
  (json nullable). The nullable columns mean "not set" → inherit the next resolution layer (AI
  service default for reasoning; code constant for model/prompt; default otherwise). Existing
  `ai_vision_disabled` stays.

> **Granularity note (deliberate).** `linktitles_setting` is resolved channel-override →
> network-fallback with **no bot dimension** (`linktitles.php:213-226`). Today `linktitles: true`
> and `url_log_chan` are per-bot in `config.yaml`; moving them into this table makes them
> per-network/channel, aligning them with how the rest of linktitles' settings (`ai_vision_disabled`)
> already scope. For the common one-bot-per-network deployment this is equivalent. A multi-bot-per-
> network deployment that later wants per-bot linktitles enable/logchan would need a bot-scoped
> addition (additive, no messy migration) — deferred (YAGNI).

**Stays in `config.yaml` (transitional)** until the codesand/youtube follow-up tasks land, because
their consumers still read `$config['bots'][$id][...]` directly:

- `codesand`, `codesand_maxlines`, `codesandMinAccess`
- `pump_host`, `pump_key` (read by `codesand` at `codesand.php:344-355`, and the art bot)
- `youtube_thumb`, `youtube_thumbwidth`, `youtube_pump_host`, `youtube_pump_key`
  (`youtube.php:262,271,286,289,300`)
- `listen` (per-bot, untouched; consolidated to a single global listen + per-bot-id routes in
  Sub-project 2)

The `SettingsResolver` merges these legacy `$config['bots'][$id]` values as a fallback so un-migrated
scripts keep working unchanged during the transition. As each script's settings move to its own
table, the resolver stops falling back for it; once all are migrated the `bots:` section is removed.

### ConfigService

Constructed with a Doctrine `EntityManager`. Owns all mutations and validation; throws typed domain
exceptions (`EntityNotFound`, `InvalidSetting`, `DuplicateName`) which `admin-cli` maps to friendly
Symfony Console errors (as it does today).

Methods (mirroring the current CLI surface plus settings):

- Networks: `createNetwork`, `renameNetwork`, `deleteNetwork`, list.
- Bots: `createBot`, `updateBot`, `deleteBot`, `addChannel`, `removeChannel`.
- Servers: `addServer`, `updateServer`, `deleteServer`.
- Ignores: `addIgnore`, `addIgnoreNetwork`, `deleteIgnore`, list/test.
- Script settings: `getScriptSetting(script, network, channel?)`, `setScriptSetting(...)`,
  `resetScriptSetting(...)` — following the `linktitles_setting` network+channel scope pattern.
- Service config (global): `getServiceConfig(type)`, `setServiceConfig(type, key, value)`.

After every successful flush, `ConfigService` invokes `ChangeNotifier::notify(ConfigChange)`. The
default implementation is a no-op; Sub-project 2 supplies the push.

### admin-cli refactor

- Every command that currently uses `$entityManager` directly is rewritten to call `ConfigService`.
- `bot:set` does **not** gain `listen` (deferred to Sub-project 2).
- **New commands:** `config:import`, `service:get <type> [key]`, `service:set <type> <key> <value>`,
  `service:list`.
- `linktitles:set` gains the new keys (`enabled`, `url_log_chan`, `ai_vision_model`,
  `ai_vision_prompt`, `ai_vision_reasoning_effort`, `ai_vision_reasoning`), with `inherit`/reset for
  the override keys.
- Doctrine migration commands are unchanged (direct EM); `bootstrap.php`'s `dieIfPendingMigration()`
  continues to guard the bots.

### Bot reads & consumer updates

- `lolbot.php` builds a per-bot config via `SettingsResolver` instead of reading
  `$config['bots'][$id]`. `lolbot.php:295` reads linktitles enable from `linktitles_setting.enabled`.
  (The art bot uses a separate `networks:[]` config model and is not touched here.)
- `scripts/linktitles/linktitles.php` reads AI from the AI service via `ServiceLocator`, its own
  model/prompt/logchan/enabled from `linktitles_setting`, and reasoning as override ?? service
  default.
- `scripts/help/help.php`, `artbot_scripts/help.php`, `scripts/alias/alias.php` read paste from the
  paste service. `library/paste.php` is unchanged.
- Un-migrated consumers (`codesand`, `youtube`) keep their `$config['bots'][$id][...]` reads,
  satisfied by the resolver's legacy fallback.

### Migration & import

One Doctrine migration creates the two service tables and expands `linktitles_settings`.
`admin-cli.php config:import` backfills rows from the existing `config.yaml` (only where a value is
non-default), idempotently; `--force` clobbers existing rows. This preserves current behavior on
first run. Per-bot `config.yaml` values that move into the network/channel-scoped
`linktitles_settings` (`linktitles`, `url_log_chan`) are imported as **network-scoped rows**
(`channel = null`) for the network of each bot that had them set — correct for one-bot-per-network
deployments (see granularity note above).

### Sub-project 2 seam

`ChangeNotifier` is defined here as a no-op interface so that Sub-project 2 can plug in push without
touching `ConfigService`. Sub-project 2 will also consolidate the notifier to a **single global
`config.yaml` listen** with per-bot-id routes, implement the HTTP push (originating from the mutating
client, sent to each bot's notifier port — no registration/heartbeat), and implement the bot-side
hot-apply (join/part channels, reload settings, spawn/drop connections).

### Error handling

- `ConfigService` throws typed domain exceptions; the CLI surfaces them as user-friendly errors.
- `SettingsResolver`: missing service config → feature disabled (matches today's "no key → disabled");
  missing script setting → code default.
- `config:import`: warns on conflicts and never clobbers without `--force`.

### Testing

PHPUnit (SQLite, per repo convention — crypto's tests are the template):

- `ConfigService` mutations + validation (create/update/delete across networks/bots/channels/
  servers/ignores; duplicate-name and not-found errors).
- `SettingsResolver` (DB values, legacy `config.yaml` fallback merge, service default/disabled,
  override→service-default→constant precedence for AI reasoning).
- `ServiceLocator` get/set for `ai` and `paste`.
- `config:import` idempotency (run twice → no duplicates; `--force` overwrites).

## Sequencing (for the implementation plan)

1. Entities + Doctrine migration + `config:import` (schema + backfill).
2. `ConfigService` + `SettingsResolver` + `ServiceLocator` + `ChangeNotifier` seam + tests.
3. AI/paste service entities + migrate their consumers (linktitles AI; help×2 + alias paste).
4. `admin-cli` onto `ConfigService` + new `service:*` / `config:import` commands; expand
   `linktitles:set`.
5. Bot reads via `SettingsResolver`; `linktitles` reads AI from the service.
6. `config.yaml` / `config.example.yaml` cleanup + docs + autoload registration.

## Out of scope (later sub-projects)

- **#109 live sync** (Sub-project 2): push to running bots, notifier consolidation to one global
  listen with per-bot-id routes, bot-side hot-apply.
- **#112 web control panel** (Sub-project 3): browser UI over `ConfigService`.
- Migrating the remaining global API-key services (bing/weather/wolfram/lastfm/twitter/github/…)
  into service configs — mechanical, reuses this framework.
- Migrating `codesand`/`youtube` script settings into their own typed tables — mechanical, reuses the
  `linktitles_setting` pattern.
- The art bot's own config model (`artbotsconfig.yaml` `networks:[]` / `NetworkContext`) — separate
  concern from the channel bot's `bots:` config addressed here.
- Named service instances (e.g. two AI providers per scope) — deferred (YAGNI).
