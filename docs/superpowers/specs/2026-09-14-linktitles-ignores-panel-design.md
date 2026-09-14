# Linktitles ignores management in the control panel — design

Date: 2026-09-14
Status: approved (pending implementation)

## Problem

linktitles has two DB-backed ignore mechanisms — `linktitles_ignores`
(URL regexes, stored with delimiters) and `linktitles_hostignores`
(hostmask globs) — but they are only manageable through
`admin-cli.php linktitles:ignore:*` / `linktitles:hostignore:*`. The
original web control panel design listed "its own host/ignore lists" on
the Linktitles view; that part was never shipped. The panel's Linktitles
page manages only the settings cascade today.

Additionally, bot-scoped rows can be stored (CLI accepts
`-B botId`) but `urlIsIgnored()` never checks them — there is a
`//todo bot would go here` in `scripts/linktitles/linktitles.php:452`.
Channel scope is entirely unimplemented (no FK, no storage).

## Decisions (from brainstorming)

- **Both kinds** managed: URL regex ignores + hostmask ignores.
- **Scopes exposed**: global, network, bot. Channel stays rejected.
- **Fix bot enforcement**: `urlIsIgnored()` learns to check bot-scoped
  rows so the panel's bot option actually works.
- **UI placement**: on the existing `/linktitles` page, below the
  settings cards (matches the original panel design's intent).
- **Tester included**: a match-test tool on the page, mirroring the CLI's
  `ignore:test` / `hostignore:test`.
- **Approach**: full panel pattern — all mutations through
  `ConfigService` (validation + flush + `ChangeNotifier` push), exactly
  like `addIgnore`/`deleteIgnore` for global IRC ignores.

Rejected alternatives: direct entity writes in section handlers (breaks
the "all mutations go through ConfigService" convention, no central
validation); push-refreshed in-memory ignore cache in the bot
(over-engineering — enforcement already queries the DB per URL, so
adds/deletes apply live with no push handling needed).

## Shared matcher: `scripts/linktitles/IgnoreMatcher.php`

New static class (namespace `scripts\linktitles`, autoloaded beside the
script class) that owns ignore matching once, so the tester uses
identical semantics to enforcement:

- `findUrlMatches(EntityManager $em, ?Network $net, ?Bot $bot, string $url): list<ignore>`
  — returns **all** matching rows (tester-friendly).
- `findHostMatches(EntityManager $em, ?Network $net, ?Bot $bot, string $fullhost): list<hostignore>`
- `isIgnored(EntityManager $em, ?Network $net, ?Bot $bot, string $fullhost, string $url): bool`
  — bot fast path: url matches or host matches non-empty.

Scope Criteria is explicit (fixes the missing bot check and the
OR-precedence ambiguity of the current
`type=global OR network=X` form):

```
type = global
  OR (type = network AND network = $net)
  OR (type = bot AND bot = $bot)
```

Matching semantics unchanged from today:

- URL rows: `preg_match($row->regex, $url)` — regexes are stored with
  delimiters + flags. `preg_match()` failure (legacy invalid row) counts
  as no match, same as today's `if (preg_match(...))` behavior.
- Hostmask rows: `preg_match(\knivey\tools\globToRegex($row->hostmask) . 'i', $fullhost)`.

The bot's `urlIsIgnored(string $chan, string $fullhost, string $url)`
becomes a thin delegate passing `$this->network` + `$this->bot` to
`IgnoreMatcher::isIgnored()`. Its signature, the existing
`//TODO can add cache for this` comment, and the channel TODO are
preserved (comment carried onto the wrapper).

Live-apply properties (unchanged from today, documented here):
`matching()` executes fresh SQL per URL, so newly added rows appear and
deleted rows disappear immediately in the running bot; there is no
update operation in this feature (add/delete only, like the global
ignores page).

## ConfigService: new "Linktitles ignores" section

Mirrors the Ignores section (`library/config/ConfigService.php:188-240`):

- `addLinktitlesIgnore(string $pattern, ignore_type $type, ?Network $network = null, ?Bot $bot = null): ignore`
  - Scope validation: `global` → both null; `network` → network required
    and managed (else `NotFoundException`); `bot` → bot required and
    managed; `channel` → `InvalidSettingException` (not supported).
  - Pattern wrapping: choose the first delimiter of `@ # ~ %` not
    present in the trimmed pattern, append `i` flag (slightly safer than
    the CLI's blind `@...@i`, fully compatible with stored rows and the
    CLI's `ignore:test`). If the pattern contains all four candidate
    delimiters, reject with `InvalidSettingException`. Reject patterns
    that fail to compile (`preg_match($re, "") === false`) with
    `InvalidSettingException` before any flush. Empty pattern rejected.
- `getLinktitlesIgnore(int $id): ?ignore`
- `listLinktitlesIgnores(): list<ignore>`
- `deleteLinktitlesIgnore(ignore $ignore): void`
- Identical quartet for hostmasks:
  `addLinktitlesHostignore(string $hostmask, ignore_type $type, ?Network $network = null, ?Bot $bot = null): hostignore`
  (hostmask validated non-empty only — the CLI does no format
  validation; globs are converted at check time),
  `getLinktitlesHostignore`, `listLinktitlesHostignores`,
  `deleteLinktitlesHostignore`.

All mutations fire `ConfigChange('linktitles_ignore' / 'linktitles_hostignore', …)`
after flush, so CLI/web stay consistent with the existing live-sync
conventions.

## Live-sync

`BotManager::apply()` gains:

```php
case 'linktitles_ignore':
case 'linktitles_hostignore':
    return; // checked per-URL against the DB; adds/deletes apply live.
```

Same rationale as the existing `'ignore'` no-op case
(`library/BotManager.php:511`). No data bag needed.

## Panel UI

On `/linktitles` below the settings cards (handler additions in
`web/sections/linktitles.php`, template additions in
`web/templates/linktitles.twig`), following the Ignores page patterns:

- **URL ignores card**
  - Table: id, regex, scope badge (`global` / network name / bot name),
    created, delete button (POST form + CSRF, like
    `web/templates/ignores/list.twig`).
  - Add form: pattern text input, type select (global/network/bot),
    network select and bot select. Minimal inline JS toggles which
    select is visible based on the type dropdown (progressive
    enhancement; the server validates regardless).
- **Hostmask ignores card**: same shape with a hostmask input
  (`*nick!user@host` placeholder).
- **Tester card**
  - Two HTMX mini-forms (test URL / test hostmask) with optional
    network + bot selects, `hx-post` to the test endpoints, results
    rendered into a fragment. Omitting the selects tests against a null
    context (only global rows can match); picking network/bot widens the
    scope exactly as enforcement would for a bot on that network/bot.
  - Fragment (`web/templates/linktitles/_test_result.twig`) lists every
    matching row (id, pattern, scope) or "no match"; rows whose stored
    pattern fails `preg_match()` get an "invalid pattern" badge.
  - Authoritative: calls `IgnoreMatcher::findUrlMatches()` /
    `findHostMatches()` with the chosen scope.

Routes (added beside the existing linktitles routes in
`web/routes.php`):

- `POST /linktitles/ignores` — add URL regex ignore
- `POST /linktitles/ignores/{id}/delete` — delete
- `POST /linktitles/hostignores` — add hostmask ignore
- `POST /linktitles/hostignores/{id}/delete` — delete
- `POST /linktitles/ignores/test` — HTMX fragment: URL match test
- `POST /linktitles/hostignores/test` — HTMX fragment: hostmask match test

Handler flow mirrors `web/sections/ignores.php`: CSRF verify → validate
POST shape → ConfigService call → `web_redirect('/linktitles')`; any
failure re-renders the page with the message in the existing error
alert. Test endpoints follow the bots section's fragment conventions:
CSRF/scope failures via `web_error_fragment()`, success via
`web_render_fragment()` (no full-page fallback — these endpoints exist
for the HTMX forms).

## Error handling

- `InvalidSettingException` / `NotFoundException` from ConfigService
  surface through the page error alert (existing pattern).
- Invalid regex / empty pattern / missing scope target rejected before
  flush — no partial state.
- Tester never throws on bad stored patterns (badge instead).

## Testing

Following `tests/Config/` conventions:

- ConfigService: add/list/get/delete for both kinds; scope validation
  (network/bot required, channel rejected, empty pattern rejected,
  invalid regex rejected); delimiter selection; notifier fired with the
  new entity-type strings (spy notifier per existing tests).
- IgnoreMatcher: scope filtering (global row matches any context,
  network row only its network, bot row only its bot); hostmask glob
  matching; invalid stored regex counts as no match.
- Web routes: add + delete + test fragment for both kinds, CSRF
  enforcement, error re-render (per the existing web test harness).

Manual verification: add/delete each kind at each scope through the
panel with the bot running; confirm a matching URL stops getting titles
immediately and an unignored one still does.

## Verification

- `composer test`
- `vendor/bin/phpstan analyse web/ scripts/linktitles/ library/config/ library/BotManager.php --no-progress`
  (scoped to touched paths; repo has a pre-existing baseline elsewhere)

## Out of scope

- Channel-scoped ignores (still rejected; entity TODO remains).
- Editing existing rows (add/delete only, like the global ignores page).
- Any ignore caching in the bot (the existing cache TODO remains).
- Changes to the CLI commands beyond what falls out of the shared
  matcher (they keep working; they may optionally adopt ConfigService
  later).
- The legacy `ignores.txt` (already dead).
