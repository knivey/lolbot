# AI Image Description Endpoint (`POST /aidesc`) — Design

Date: 2026-09-18

## Problem

linktitles already adds AI descriptions to image URLs posted in IRC channels
(`getAiDescription()` in `scripts/linktitles/linktitles.php`). An image-upload
site that notifies our channels of new uploads (via the existing notifier
endpoint) wants the same AI descriptions for its own site. We expose a new
HTTP endpoint on the bot: callers submit image data directly and receive a
description back.

## Decisions (from brainstorming)

- Endpoint is **hosted by the channel bot** on the existing control REST
  server (same server as notifier routes) — no new process or port.
- Image data submitted as **raw binary request body**; response is
  **plain text**.
- **16 MB** body size limit.
- Endpoint uses **global-scope settings only**: `ai_service_config` (apiKey,
  baseUrl, maxDim, jpgQuality, timeout) + global `linktitles:set` values
  (model, prompt, reasoning). No per-request overrides.
- Keys live in the database in a **general `api_keys` table** with a
  scopes mechanism (one key may hold multiple scopes); the `aidesc` scope
  gates this endpoint. The notifier will migrate onto these keys later.
- Keys are administered via **CLI and web panel** like other entities.
- Avoid stale Doctrine identity-map data in the long-running bot process
  (past bug class; see `library/config/ServiceLocator.php:31-38` and
  `library/config/SettingsResolver.php:47-54` for the established
  refresh-on-read pattern).

## Endpoint contract

`POST /aidesc`, registered on the channel bot's control server router via
the same extension point as notifier (`lolbot.php:234-237`).

Request:
- `key` header — looked up in `api_keys`; the key must carry the `aidesc`
  scope.
- Body = raw image bytes (max 16 MB). Content-Type is not trusted for
  validation; Imagick sniffs the actual bytes.

Processing (identical pipeline to in-channel descriptions, global settings):
Imagick `pingImageBlob` decompression-bomb guard (25M pixels, existing
constant) → resize to `maxDim` / re-encode JPEG (existing ai service config
values) → OpenAI-compatible chat completion via `knivey/amphp-openai` →
strip control chars.

Response: `200 text/plain`, body = the description. **Not** truncated to 200
display width — that limit is IRC-specific; callers trim as needed.

Errors (all `text/plain`):

| Status | Condition |
|---|---|
| 403 | missing/invalid key, or key lacks `aidesc` scope |
| 413 | body over 16 MB |
| 400 | undecodable image or bomb-guard tripped |
| 503 | AI service not configured (no api key in `ai_service_config`) |
| 502 | upstream AI error |
| 504 | AI timeout |

Caching: cached in-process by `sha256(body)` in the describer's static
cache (see Pipeline refactor) so identical resubmits don't re-bill. No
per-key rate limiting in v1 (notifier has none either).

## Keys: entity, storage, admin

**Entity** `entities/ApiKey.php`, table `api_keys` (house style per
`entities/Ignore.php` — public props, `__toString`, non-readonly id):

- `id` int PK autoincrement
- `key` string(64), unique index — admin chooses the value (as with
  notifier yaml keys today)
- `label` string(64) nullable — e.g. "image upload site"
- `scopes` JSON array of strings — e.g. `["aidesc"]`, later
  `["aidesc","notifier"]`; helper `hasScope(string $scope): bool`
- `created` DateTimeImmutable (set in constructor)
- `__toString()` shows id, key, label, scopes, created. The key is
  admin-chosen and must be handed to the caller, so it is shown (unlike
  `AiServiceConfig`'s generated secret which is redacted).

Migration: `Migrations/Version<timestamp>.php` createTable pattern (portable
across SQLite and Postgres, `all_or_nothing`). Bot refuses to start with
pending migrations (`dieIfPendingMigration()`), so the migration ships in
the same change.

**Fresh-reads guarantee (stale Doctrine data):** the endpoint handler runs
in the long-lived bot process whose EntityManager serves repository finds
from the identity map. Key adds/deletes/edits arrive from other processes
(admin-cli, web panel). `ApiKeyRepository::findByKey()` therefore uses a
DQL query with `Query::HINT_REFRESH` so every request re-hydrates from the
DB and a deleted row simply returns null — documented with a comment in the
ServiceLocator style. Deletion is a primary flow for keys, so unlike the
singleton service configs the lookup must survive rows vanishing. The AI
rows the endpoint also reads are already refreshed per read by
`ServiceLocator` and `SettingsResolver`. Web panel and CLI boot fresh
EntityManagers per request and need no extra handling.

**ConfigService** methods (validate → persist → flush → notify, like
ignores):
- `addApiKey(string $key, ?string $label, array $scopes)` — duplicate key
  → `DuplicateNameException`
- `listApiKeys()`, `getApiKey(int $id)`, `deleteApiKey()`
- Notifications use `ConfigChange('api_key', $id, 'create'|'delete')`.

`BotManager::apply()` gets a no-op `case 'api_key':` with a comment — the
endpoint checks keys per request against the DB, so adds/deletes apply live
without restart (same rationale as `ignore`).

**CLI** (registered in `admin-cli.php`):
- `apikey:add <key> [--label] [--scope aidesc --scope ...]` — `--scope`
  repeatable, values validated against the known-scopes list (a const on
  the `ApiKey` entity: `['aidesc']` for now); mutating commands construct
  `ConfigService` with `build_change_notifier()` and finish with
  `showdb::showdb()` per house style
- `apikey:list` (no notifier; prints `(string)$entity` per row)
- `apikey:del <id>`

**Web panel:** new section mirroring ignores:
- `web/sections/apikeys.php` with `web_apikeys_list(?string $error)`,
  `web_apikeys_create()`, `web_apikeys_delete(int $id)` (CSRF check →
  ConfigService call → `web_redirect('/apikeys')`; errors re-render the
  list with the alert banner)
- Routes in `web/routes.php` beside the ignores block; template
  `web/templates/apikeys/list.twig` using the `field`/`submit`/
  `csrf_field` macros + per-row delete forms; nav entry in `base.twig`
- Add form: key, label, scopes (checkboxes of known scopes)

## Pipeline refactor

Extract the core of `getAiDescription()`
(`scripts/linktitles/linktitles.php:228-349`) into a standalone class
`scripts/linktitles/ImageDescriber.php` — the linktitles script class
extends `script_base`, whose constructor requires per-bot objects
(network, client, logger, ...), while the endpoint is bot-independent.
The describer is constructed with the global `EntityManager` and carries
out: bomb-guard, resize/JPEG/base64, AI client call, control-char strip.

- `describe(string $bytes, LinktitlesResolved $settings): DescribeResult`
  returns either the raw description or a typed failure reason (undecodable
  / bomb-guard / no-service-config / upstream error / timeout) so each
  caller maps errors its own way: IRC path → profile tags
  (`ai_error=...`, `ai_skipped=image_too_large`), endpoint → the 4xx/5xx
  table above.
- The static description cache moves into `ImageDescriber` (it is already
  static and shared across bot instances today): URL keys for the IRC
  path, `sha256:`-prefixed keys for the endpoint, one namespace so
  identical images/bodies hit the same entry.
- IRC path (`formatImageResponse()`) keeps its URL-keyed cache lookup,
  200-char `mb_strimwidth` truncation, and profile tags; it calls the
  describer. `tests/Linktitles/FormatImageResponseTest.php` and
  `tests/ImageGuardTest.php` must keep passing.
- Endpoint handler resolves global-scope settings
  (`SettingsResolver->resolveLinktitles(null, null)`) and calls the same
  describer.
- imgur's `fetchAndFormatMedia()` goes through `formatImageResponse()` and
  is unchanged.
- Prompt for the endpoint is the global `ai_vision_prompt` — the site gets
  the same style of description the bot posts in-channel.

**Route registration:** procedural `scripts/aidesc/aidesc.php` defining
`aidesc_register(Router $router)`, mirroring
`scripts/notifier/notifier.php`'s `notifier_register()`, called from
`lolbot.php` next to the existing `function_exists()` hook. The handler
uses the global `EntityManager` for key lookup + settings and
`ImageDescriber` for the description.

## Testing

PHPUnit 10, SQLite in-memory via `tests/Config/ConfigTestCase.php`:

- Migration + entity round-trip: add/find/delete key, scopes JSON
  persistence, `hasScope()`
- `ConfigService::addApiKey` duplicate rejection; list/delete; notifier
  dispatch (`ConfigChange` emitted, CapturingNotifier pattern from
  `tests/Config/ConfigServiceNotifierTest.php`)
- `apikey:add` / `apikey:list` / `apikey:del` via `CommandTester`
  (pattern from `tests/Config/NetworkSetCommandTest.php`); invalid scope
  rejection
- `ApiKeyRepository::findByKey()` freshness: row updated and deleted by a
  second EntityManager is reflected without clearing the first
- Web section create/delete incl. CSRF failure, following the patterns in
  `tests/Config/WebAuthTest.php` / `WebLinktitlesIgnoreHelpersTest.php`
- `ImageDescriber` failure-reason mapping for both callers; existing
  `FormatImageResponseTest` / `ImageGuardTest` keep passing
- Full HTTP handler with Imagick + live AI client: manual smoke test
  (`curl -H "key: ..." --data-binary @pic.png
  http://127.0.0.1:1339/aidesc`) covering each error status

## Non-goals

- Migrating notifier onto `api_keys` (schema supports it; separate change)
- Per-key rate limiting or usage accounting
- Per-request prompt/model overrides
- Persistent description storage (in-process cache only, matching
  linktitles today)
