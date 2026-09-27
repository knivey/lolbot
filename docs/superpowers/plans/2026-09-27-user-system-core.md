# User System Core (Step 3) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The core user system: per-network user accounts with services auto-registration, the four identity engines, the identity cache, PM auth commands, admin bootstrap, and the Nicks.php migration onto `Client::whox()`.

**Architecture:** `library/user/` holds the core (IdentityService orchestrator, IdentityCache, engines, Access+Acl moved from scripts/user/); `entities/` gains User + UserHostmask with a migration (incl. `Networks.auth_engines`); PM commands stay in `scripts/user/user.php`; per-network engine chains live in the Networks row (NULL = capability auto-detect); deny UX wired in BotManager.

**Tech Stack:** PHP 8.1+, Doctrine ORM + handwritten migration, cmdr v5 middleware/attributes (shipped), `Irc\Client` foundations (shipped: account-tag on events, extended-join, `whox()`), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-09-26-user-system-design.md` — auth engines, capability degradation, identity cache, real-network landscape, code placement, foundations order step 3.

## Global Constraints

- Owner decisions (binding): per-network users; services nets auto-register on first gated interaction; EFnet-class = explicit `.register <name> <pass>`; auth stores hostmask unless `paranoid` OR (admin AND network hasn't enabled `admin_hostmask_auth` — the relax flag for networks where host faking isn't a concern); paranoid defaults off; linking cross-network users is a FOLLOW-UP (schema must not preclude it); no user-create CLI — `user:flags` marks existing users; migrate Nicks.php now.
- Engine chain config: `Networks.auth_engines` JSON nullable column (NOT yaml, NOT settings registry); NULL = auto-detect order account-tag → vhost/whox → hostmask → manual.
- Core in `library/user/` (namespace `library\user` — composer maps `library\\` → library/ already); PM commands in `scripts/user/` (preserve user.php's 2022 notes header comment verbatim at the top of the file).
- AGENTS.md: never remove comments; phpstan level 9 zero NEW errors on touched paths (compare stash baselines); TDD for all pure logic (engines, cache, flag math, mask matching); Doctrine wiring verified against a /tmp scratch sqlite copy of the dev DB, never the dev DB itself; conventional commits authored `knivey <knivey@botops.net>`; never `git add -f`.
- Identity semantics (from spec): bindings `(network_id, nick_lowered) → user_id`; NICK carries; QUIT drops; GameSurge QUIT(Registered)+rejoin rebinds; account-tag refreshes for free; TTL fallback only where no fresher source (services nets are event-fed; WHOX lazy only on miss/host-hide).
- `#133` wrap: outputs bot-templated; no trim on art (N/A).

## Review Focus

1. **Auto-registration abuse** — a random user triggering a gated command on Libera creates rows forever (row-per-stranger). Expect: creation only when an engine actually resolved a services identity (never on failure), name length/charset sanity, and a note on rate (acceptable: IRC chat volume; row per account name is bounded by real accounts). → Task 3 tests.
2. **Password auth on services nets** — `.register`/`.auth` exist everywhere; on services nets they must still work (fallback) but must NOT overwrite a services identity binding silently. → Task 5 tests.
3. **Engine order pinning** — explicit `auth_engines` list must be honored exactly (no silent auto-detect additions) and unknown engine names in the list fail loud at resolve time (exception → deny), not silently skipped. → Task 3 tests.
4. **Hostmask match specificity** — `HostmaskEngine` masks must match ident@host (nick!ident@host forms), case-insensitively, without matching unrelated users (glob semantics via `knivey\tools\globToRegex`, the Nicks.php `h2n` precedent). → Task 4 tests.
5. **IdentityCache leak across reconnects** — welcome event must flush per-network bindings (new IRC session = new bindings); stale nick reuse must never inherit a previous user. → Task 3 tests.

---

### Task 1: Entities + migration + user:flags CLI

**Files:**
- Create: `entities/User.php`, `entities/UserHostmask.php`
- Create: `Migrations/Version20260927120000.php`
- Create: `cli_cmds/user_flags.php` + register in `admin-cli.php`
- Modify: `entities/Network.php` (add `public ?array $auth_engines = null;` JSON column — read the entity first; doctrine `#[ORM\Column(options: ['json' => true])]` type via `Types::JSON` matching ApiKey.scopes precedent)
- Test: `tests/User/UserFlagsCliTest.php` (pure flag-parse logic only)

**Interfaces (exact):**
- `entities\User` (table `users`): `int $id`, `int $network_id` FK→Networks.id, `string $name`, `string $nameLowered`, `?string $passHash`, `string $flags` JSON (default `'[]'`), `bool $paranoid = false`, `\DateTimeImmutable $created`; unique index `(network_id, nameLowered)` named `users_network_name_uniq`. Namespace `lolbot\entities` (in $paths already).
- `entities\UserHostmask` (table `user_hostmasks`): `int $id`, `int $user_id` FK→users.id CASCADE, `string $mask`, `string $addedBy`, `\DateTimeImmutable $created`; unique `(user_id, mask)` named `user_hostmasks_user_mask_uniq`.
- Migration: create both tables + `Networks.auth_engines` JSON nullable column AND `Networks.admin_hostmask_auth` BOOLEAN NOT NULL DEFAULT false (owner note 2026-09-27: the admins-never-store-hostmasks rule is relaxed on networks known not to abuse faked hosts) (ALTER, sqlite+pg safe like prior migrations — read `Version20260918120000.php` and one ALTER precedent in Migrations/ for the comparator pattern). down() reverses.
- CLI `user:flags <network> <name> <+flag|-flag...>`: resolves Network by name (case-insensitive) + User by `(network_id, nameLowered)`; applies flag add/remove (JSON array, keep order, dedupe); `--list` variant prints flags. Symfony Console command following `cli_cmds/server_set.php` style; registered in `admin-cli.php`.
- Pure helper for tests: `lolbot\entities\User::applyFlag(array $flags, string $op, string $flag): array` (static; `'+'` adds, `'-'` removes).

Steps: TDD `applyFlag` (add, remove, dedupe, unknown op throws `InvalidArgumentException`); write entities + migration + CLI; scratch-sqlite verification (copy dev db, run migration SQL programmatically, assert tables/columns/indexes + a flag update round-trip through the real entity manager on the copy — pattern: Task 1 of the alias-history plan); php -l; full suite; phpstan scoped (`entities/ cli_cmds/user_flags.php tests/User/`) vs baseline. Commit: `feat(user): users and hostmask entities, auth_engines column, user:flags cli`.

### Task 2: core move — Access + Acl into library/user/

**Files:**
- Move: `scripts/user/Access.php` → `library/user/Access.php`, `scripts/user/Acl.php` → `library/user/Acl.php` (namespace `library\user`; update `BotManager`'s references and `tests/User/AclMiddlewareTest.php` imports — behavior unchanged)
- Create: `library/user/Access.php` additions: `public static function userHasFlag(object $user, string $flag): bool` (reads `$user->flags` JSON array defensively: non-string-prop / bad JSON → false)
- Test: extend `tests/User/AclMiddlewareTest.php` (or new `tests/User/AccessFlagsTest.php`): userHasFlag with valid/invalid JSON, missing property, stdClass+real User entity.

Steps: TDD userHasFlag; move files (git mv), fix references (grep `scripts\\user\\Access\|scripts\\user\\Acl`); full suite; phpstan scoped. Commit: `refactor(user): move Access and Acl into library/user with flag checks`.

### Task 3: IdentityCache + Engine interface + AccountTagEngine + IdentityService (auto-register)

**Files:**
- Create: `library/user/IdentityCache.php`, `library/user/Engine.php` (interface), `library/user/engines/AccountTagEngine.php`, `library/user/IdentityService.php`, `library/user/EngineConfig.php` (resolves chain: Network->auth_engines ?? auto-detect order `['account-tag','vhost','whox','hostmask','manual']` filtered later by capability flags passed in)
- Test: `tests/User/IdentityTest.php` (pure parts: cache rules, chain resolution, engine with fake repository)

**Interfaces (exact):**
- `IdentityCache`: `get(int $netId, string $nickLowered): ?array{user_id: int, provenance: string, refreshed_at: int}`; `set(...)`; `drop(int $netId, string $nickLowered): void`; `carryNick(int $netId, string $oldLowered, string $newLowered): void`; `flushNetwork(int $netId): void`; pure in-memory, no Doctrine.
- `Engine` interface: `public function resolve(\library\user\ResolveContext $ctx): ?int` (returns user_id or null). `ResolveContext` readonly DTO: `int $networkId`, `string $nick`, `string $nickLowered`, `?string $identHost`, `?string $account` (from account-tag/extended-join), `object $client` (Irc\Client for lazy WHOX — typed loosely), `bool $allowCreate` (auto-registration gate).
- `EngineConfig::chain(?array $authEngines, array $caps): list<string>` — pinned list honored exactly (unknown name → `UnexpectedValueException`); NULL → auto order `['account-tag','vhost','whox','hostmask','manual']` filtered by `$caps` (`account-tag` needs hasCap, `vhost` needs a configured pattern (network-specific: pass patterns map), `whox` needs WHOX isupport; hostmask/manual always).
- `AccountTagEngine::resolve`: `$ctx->account` non-null → find-or-create user `(network_id, nameLowered=account)` when `$ctx->allowCreate`, else find-only. Repository passed as a callable/duck-typed `UserRepo` interface (`findForNetwork(int $netId, string $nameLowered): ?object`, `createFromAccount(int $netId, string $account): object`) so tests use fakes and Doctrine implements it in Task 6.
- `IdentityService::resolve(ResolveContext $ctx): ?array{user_id, provenance}` — cache → chain (first hit wins, sets cache) — pure orchestration over injected deps (cache, engines list from EngineConfig, clock callable for testability).
- Nickname lifecycle hooks (pure methods on IdentityCache called from BotManager wiring in Task 6): `onNick`, `onQuit`, `onWelcome` (=flushNetwork).

Steps: TDD all pure parts first (cache rules incl. carry/drop/flush/leak case: drop then get → null; chain pinning incl. unknown-name throw + auto-detect filtering; AccountTagEngine find/create/find-no-create; IdentityService order + cache write + provenance). Full suite; phpstan scoped. Commit: `feat(user): identity cache, engine chain and account-tag engine with auto-registration`.

### Task 4: VhostPatternEngine + WhoxEngine + HostmaskEngine

**Files:**
- Create: `library/user/engines/VhostPatternEngine.php`, `library/user/engines/WhoxEngine.php`, `library/user/engines/HostmaskEngine.php`
- Test: `tests/User/EnginesTest.php`

**Interfaces:**
- `VhostPatternEngine(array<string networkName, string regex> $patterns)` — host portion of `$ctx->identHost` matched against the network's pattern (default config ships the GameSurge pattern from the spec: `/^(?P<name>[^.]+)\.[^.]+\.gamesurge$/i` — where do patterns live? in the Engines DI in Task 6, a static default map `['gamesurge' => ...]` keyed by lowered network name, overridable — keep the map in `EngineConfig::defaultPatterns()`); capture `name` → find-or-create (same UserRepo) when allowCreate.
- `WhoxEngine(object $client, UserRepo $repo, callable $clock)` — resolve: if `$ctx->client->hasOption('WHOX')`, `whox($ctx->nick, 'a')->await()` (bounded — the Future's own timeout protects), account null/`0` → null; else null; then find-or-create like AccountTagEngine. NOTE: await inside engine = coroutine; IdentityService stays synchronous by having WhoxEngine implement a marker interface `LazyEngine` whose resolve may be deferred — simplest: WhoxEngine::resolve returns the awaited result directly (IdentityService is always called inside async contexts in the bot; tests call it inside `Amp\async`).
- `HostmaskEngine(UserHostmaskRepo $maskRepo)` — masks for... efficient shape: `findMatchingMasks(int $netId, string $identHost): array` (repo-side LIKE prefilter optional; engine does final `globToRegex` match per mask, first match wins → user_id). Case-insensitive; mask may cover nick! or just ident@host (match against full `nick!ident@host` string, Nicks `h2n` precedent).
- Repos as interfaces in `library/user/` (`UserRepo`, `UserHostmaskRepo`); Doctrine implementations come in Task 6.

Steps: TDD (vhost: match/capture/no-match/wrong-network/paranoid-irrelevant; whox: disabled-capability → null, account resolve + `0`→null + create/no-create; hostmask: exact/glob/case-insens/no-match/multi-user specificity — one user's mask must not match another's host). Full suite; phpstan. Commit: `feat(user): vhost, whox and hostmask identity engines`.

### Task 5: ManualEngine + PM commands + deny UX

**Files:**
- Create: `library/user/engines/ManualEngine.php` (password verify via `password_verify`, argon2id hash via `password_hash` on register/pass-set; on success returns user_id — hostmask storing is command-layer, not engine)
- Modify: `scripts/user/user.php` — implement the PrivCmds behind the preserved 2022 notes header: `register <name> <pass>` (services-less primary; on services nets allowed but refuses if the nick currently resolves to a DIFFERENT user via services — tell them to use their services identity), `auth <name> <pass>` (verifies; on success: store current hostmask via UserHostmaskRepo unless paranoid or admin — set binding in IdentityCache with provenance 'manual'), `pass <old> <new>` (change), `paranoid [on|off]` (toggle, gated on being authed), `setflags <name> <+flag|-flag>...` + `addflags`/`delflags` aliases (gated `#[Acl("admin")]`)
- Modify: `library/BotManager.php` — deny UX: the pm/chat handlers already wrap `$router->call()` returns; on a string return from a call that had an acl deny (middleware returns string), notice/pm it to the user (read current call sites; the deny strings from Task 3 of cmdr-v5 plan flow out of call())
- Test: `tests/User/ManualAuthTest.php` (pure: ManualEngine verify/hash round-trip incl. wrong-password null; the store gate as pure function `shouldStoreHostmask(bool $paranoid, bool $isAdmin, bool $networkAdminHostmaskAuth): bool` — paranoid never stores; admins store only when the network flag is set; everyone else always)

Steps: TDD pure parts; implement commands (each: resolve network via `$this->network`, PM-only already via PrivCmd — check BotManager's pm path calls `callPriv`; confirm PrivCmd registration path loads scripts/user/user.php — it is NOT currently require_once'd: add the require to lolbot.php next to the other script requires); BotManager deny wiring + a test if harness-able else scratch verification; full suite; phpstan scoped vs baseline. Commit: `feat(user): manual auth engine, pm commands and deny ux`.

### Task 6: Doctrine repos + BotManager wiring + scratch-DB end-to-end

**Files:**
- Create: `library/user/DoctrineUserRepo.php` (implements UserRepo; findForNetwork/createFromAccount with `random_bytes`-free deterministic name casing: name = account as-seen, nameLowered via mb_strtolower), `library/user/DoctrineUserHostmaskRepo.php`
- Modify: `library/BotManager.php` — construct IdentityService per bot (engines from EngineConfig::chain($network->auth_engines, caps) + patterns map + repos + client), `Access::userResolver(fn(array $extraArgs) => resolve from ChatEvent in extraArgs via IdentityService incl. allowCreate=true for chat commands)`, lifecycle hooks (welcome→flushNetwork, nick→carryNick, quit→drop; chat/pm events already update account-tag provenance implicitly via resolve), Nicks-independent
- Modify: `web/sections/networks.php` — `auth_engines` edit field (comma-separated engine list, empty = auto) AND the `admin_hostmask_auth` checkbox, both following the throttle checkbox pattern + `library/config/ConfigService.php` setters + ConfigChange hot-apply (read the existing network-edit apply path)
- Verify (scratch-DB, no unit): copy dev sqlite → /tmp/opencode; script boots a real EntityManager on the copy + fake Irc\Client; simulate: Libera-style account-tag arrival auto-registers; GameSurge-style vhost host resolves + creates; WHOX-miss host-hider resolves via fake whox future; hostmask match after manual auth (with paranoid=false non-admin storing); admin no-store; deny string reaches a fake replier; welcome flush. Assert DB rows + bindings.
- Full suite + scoped phpstan everywhere touched. Commit: `feat(user): doctrine repos, per-bot identity wiring and webui engine config`.

### Task 7: Nicks.php migration onto Client::whox()

**Files:**
- Modify: `library/Nicks.php` — replace the label-777 WHOX path: on our join, `$this->bot->whox($chan, 'tcuhnf')->map(...)`? READ FIRST: Client::whox returns Future resolving list<entry>; Nicks' whox() consumed channel/ident/host/nick/flags — the new path consumes the Future result (entries keyed by letter). Keep the 352 fallback branch exactly as-is (non-WHOX networks). Remove the 777 send + the 354 subscription + `whox()` method; keep comments that still apply (the multi-prefix note at line 65).
- Test: existing tests/Irc suite green; add `tests/User/NicksWhoxMigrationTest.php` if harnessable (fake client whox returning entries → ppl populated incl. host + modes from 'f' flags), else scratch script.

Steps: read Nicks.php + Client::whox signatures; implement; full suite (BotManagerApplyTest exercises Nicks paths — watch it); phpstan. Commit: `refactor(nicks): use Client::whox for join-time who backfill`.

### Task 8: wrap — spec update, deploy notes

- Spec: mark step 3 complete (engines shipped, linking + webui-flags follow-up issues filed: `gh issue create` for cross-network linking (user command + admin action), web UI user admin, and the deferred WHOIS-330/srvx ideas — controller does this).
- Full suite + push only after final whole-branch review (controller).
