# User System — Coalesced Design

Status: **design approved in brainstorm on 2026-09-26; core (steps 1–3) built
2026-09-26/27. Channel access + settings registry sections approved
2026-09-27; not yet built.**
This document is the single source of truth for the user system. Sources:
issue #34, the header comment and stubs in `scripts/user/user.php` (2022),
`scripts/user/Access.php`, and the 2026-09-26 design session with the owner.

## Why we want it

Bot-admin and channel-admin oriented commands have been held off
specifically because there is no user system. Once accounts + ACLs exist we
can plan many commands we have been lacking (issue #34's real payoff).

## Core concepts

- **User account**: the central identity. Other subsystems key off a
  `user_id` instead of nicks.
- **Scripts own their own settings** — accounts are NOT centralized settings
  blobs. Each script keeps its own set-commands/tables keyed by `user_id`,
  or (later) registers into the central settings registry below.
- **Flags are ACLs**: a user carries flags; each flag maps to an ACL check
  via `Access::define()` / `Access::allowed()` (`scripts/user/Access.php`).
- Admin ACL shipped first; **channel ACL designed 2026-09-27** (section
  below) — bot admins hold every channel power, finer-grained ACL falls out
  of the flag-group registry.

## ACL surface (decided 2026-09-26)

**Middleware is the mechanism, attributes are the sugar.** cmdr (separate
repo, `knivey/Cmdr`, currently pinned `^4.0`) gains a wrapping middleware
pipeline, PSR-15 style:

```php
$router->addMiddleware(fn(Request $req, callable $next) => /* global */ ...);
$router->addMiddleware($middleware, 'restart');   // per-command, programmatic

#[Cmd("restart")]
#[Acl("botadmin")]   // sugar: auto-attaches the acl middleware to this command
```

Execution order: global middlewares → per-command/attribute middlewares →
command body. The built-in acl middleware resolves the requesting user,
runs the superadmin `before()` hook, then `Access::allowed()`, then either
`$next($req)` or a deny notice (PM notice, "auth or ask an admin" style).

Borrowed from Laravel gates (the musing, resolved): named ability closures
(`Access::define`) and the `before()` interception hook for superadmin
bypass — one global hook guarantees the owner can never be locked out.
NOT borrowed: policies, containers, ability inheritance.

- Users resolved **lazily** via a `user($args)` helper for commands that
  need the identity itself (settings, tells); no cmdr parameter injection.
- Commands without `#[Acl]` are unchanged (fail-closed only for gated ones).
- `Access::allowed()` remains for ad-hoc checks inside command bodies.
- Dev loop: local `knivey/Cmdr` clone wired into lolbot via a composer
  `path` repository with symlink until a new major release is tagged.

## Flag registry & channel-scoped access (decided 2026-09-27)

**Flags are defined in code, optionally granting other flags (groups).**
`library/user/Flags.php` holds the registry: name → `grants` list.
`admin` is defined as granting `*` (everything). Future groups (e.g.
`manager` → `quotes.manage`, `set`, `kick`) are just definitions.
Definitions live in code — admins *grant* flags, they don't invent them
(Anope-oper-precedent). Network-only powers get names no channel check
ever asks for (e.g. `BotManager`) — the naming discipline replaces scope
bookkeeping.

**Resolution for "does user pass flag X in channel C"** (fall-through +
groups, uniform):

1. Flag set = user's network flags ∪ their grants in C (same name at
   network level satisfies the channel check automatically — network
   `quotes` → channel `quotes` everywhere, network `admin` → everything).
2. Expand groups transitively within the set (`manager` adds its grants,
   `admin` adds all; cycle-guarded).
3. Pass iff X ∈ expanded set.

A group flag held at network level confers its sub-flags in every channel
("manager-grade power, network-wide" — intended). A small network grant
cannot balloon into channel admin unless *defined* to.

**Channel-scoped attribute form:** `#[Acl("flag", channel: true)]` —
resolves the channel from the ChatEvent; via PM it denies ("channel
only"). Plain `#[Acl("flag")]` stays network-scope. Channel commands act
only in-channel (owner rule: no PM commands acting on channels).

**Storage:** `channel_flags(user_id FK, channel_id FK, flags JSON,
added_by)` unique `(user_id, channel_id)` — mirrors `users.flags`
(one row per user+channel, arbitrary flag strings = the fine-grained
room).

**Grant surface:** in-channel `.cflags <user> [+flag|-flag|flag ...]`
(same op syntax as `user:flags` CLI). Rules: a granter may only
grant/revoke flags they themselves pass in that channel (after group
expansion — no self-escalation); bot admins unrestricted in any channel;
target must resolve as a known user on the network ("user unknown, have
them talk/auth first" — no ghost rows). Bootstrap: initial channel grants
by a bot admin.

**Grant-time validation:** `.cflags` and `user:flags` share one path that
validates every op against the flag registry (unknown flag → refused with
the valid names). **Flag removal:** retired definitions make existing
grants inert (nothing checks the name — harmless); cleanup is a
case-by-case migration/sweep over the JSON arrays when a flag is retired.

## Auth engines (pluggable chain, per network)

```
1. account-tag (ircv3)      authoritative per-message account, where CAP exists
2. services WHOX            WHOX 354 account field (via Client::whox())
3. hostmask                 stored per-user masks (baseline everywhere)
4. manual session           .auth via PM (password login), held until invalidated
```

First engine to yield an answer wins; engines are pure resolvers
`(network, nick, host) → ?user_id`. `paranoid` (per-user flag) disables the
hostmask engine for that account, forcing manual auth each connect.
Admins additionally never auto-store hostmasks on networks where faked
hosts are a concern — relaxed via `Networks.admin_hostmask_auth` on
networks the operator trusts (owner note 2026-09-27).

### Real network landscape (owner note 2026-09-27)

The bots currently run on GameSurge, Libera, and a handful of Ergo
servers; more may come. **WHOX or account-tag — one or the other — covers
every services network**: Libera and Ergo have account-tag (no WHOX),
GameSurge has WHOX (no ircv3 tags). Networks WITHOUT services (EFnet — no
WHOX, no services, nothing) are where the bot's own PM password login
(`register`/`auth`/`pass`) is the primary engine, fed by hostmask tracking
— Nicks.php already proves host/user tracking works fine on EFnet-class
networks via WHO 352 / NAMES / JOIN / PM events. Consequently:

- **WHOIS 330 (RPL_WHOISACCOUNT)** and **srvx queries** are NOT needed for
  the current landscape — demoted to deferred ideas (revisit only if a
  future network has neither WHOX nor account-tag but does have services).
- Engine presets per network are trivial: GameSurge = WHOX (+ its vhost
  regex as a free fast-path), Libera/Ergo = account-tag, services-less =
  manual PM auth + hostmask.


### GameSurge reality (owner-verified mechanics)

- GameSurge has services but **no ircv3 caps**. ircu ircd has no CHGHOST:
  on auth the server cycles the user with **QUIT + immediate JOIN** so
  clients see the new host (users *can* disable this to keep their real
  host — so the cycle cannot be relied on; it is a bonus signal).
- **Host rules** (strict network policy): hosts matching
  `(?P<name>[^.]+)\.[^.]+\.gamesurge` are **authoritative** — the account
  is the first label, the middle label is `user` by default or a
  user-chosen vanity word (dot-free, hyphens for words):
  `opp.user.gamesurge` → `opp`, `zenith.boat.gamesurge` → `zenith`.
  Hosts NOT ending `.gamesurge` mean unauthed or host-hidden (see below).
- Account renames are undetectable by protocol (extremely rare). Future:
  admin merge/rename tool; autodetection heuristic = the
  `QUIT (Registered)` + immediate rejoin cycle.
- The lazy command-time fallback stays required regardless: users who hide
  their host after auth never show a `.gamesurge` host, so a missing
  binding must still trigger targeted WHOX.

### Capability degradation (owner note 2026-09-27, from Nicks.php experience)

Some networks don't support WHOX at all — but they typically have SOME
combination that makes tracking possible. Engines self-disable when their
capability is absent (probe via the existing `$bot->hasCap()` /
`$bot->hasOption('WHOX')` ISUPPORT check — the Nicks.php pattern) and the
chain degrades per network:

| mechanism | detection | yields |
|---|---|---|
| account-tag | `hasCap('account-tag')` | per-message account, free |
| WHOX `a` field | `hasOption('WHOX')` (ISUPPORT) | account per WHO query (via `Client::whox()`) |
| WHOIS 330 (RPL_WHOISACCOUNT) | services ircds (atheme/anope family); probe by trying | account for one nick, on demand — DEFERRED, not needed for current networks |
| standard WHO 352 | always available | ident@host only — feeds the hostmask engine |
| NAMES / JOIN / PM events | always available | incremental ident@host (Nicks-style tracking) |
| network-specific (GameSurge vhost regex) | per-network config | account from host, free fast-path (authoritative per the `[^.]+\.[^.]+\.gamesurge` rule) |

Engine selection is therefore: configured network engines first (they encode
operator knowledge), then capability-probed generic engines, then manual
session, then hostmask. Standard WHO 352 stays the host backfill wherever
WHOX is absent (current Nicks behavior, preserved through the migration
onto `Client::whox()`).

### Identity cache (decided: events + lazy fallback)



Bindings live per `(network_id, nick_lowered) → user_id` with provenance
and refresh timestamp.

- **NICK change** → carry the binding to the new nick, NO revalidation.
- **QUIT + immediate JOIN, same nick** (GameSurge Registered cycle) →
  rebind: re-extract host / targeted WHOX.
- **QUIT (real)** → drop the binding.
- **account-tag** (capable networks) → authoritative free refresh.
- **WHO(X) on channel join** → bulk refresh of everyone present.
- **Command-time fallback** → targeted WHOX when a binding is missing or
  stale (TTL); required for users hiding their host after auth.

## Nick-keyed vs account settings (resolved 2026-09-27)

Users are never forced to auth — existing nick-keyed commands
(`.setlocation`, `.setlastfm`, …) keep working **exactly as they are,
permanently**. No migration, no forced account creation. Later, a command
may opt into an account override layer: resolution becomes
`account setting (if the speaker resolves and has one) → nick-keyed store
(unchanged behavior) → default`. The account tier is purely additive;
the nick path stays the fallback forever.

## Central settings registry (decided 2026-09-27)

Two generic tables (not one mega-table — scopes have different keys):

- `channel_settings(network_id, channel_id NULL, key, value JSON)` — the
  network tier is a row with `channel_id` NULL (find-before-save guards
  the SQL NULL-uniqueness caveat, same as linktitles today).
- `user_settings(user_id, key, value JSON)` — account tier.

**Resolution:** channel settings = `channel → network → code default`;
account settings = `user → default`. The registry owns the rule in one
place; nick-keyed stores are NOT part of the registry (section above).

**Definitions** are declarative, cmdr-style, on the owning command:

```php
#[Setting(name: "lastfm", type: Setting::STRING, default: "",
          scope: Setting::ACCOUNT,
          description: "your last.fm username")]
```

plus a programmatic registration path for **storage adapters**: a script
with specialized storage registers definitions backed by its own tables.
Types validated at set-time (`bool|int|string|enum`); definitions can be
marked non-IRC (`irc: false`) to expose CLI/web only (raw JSON blobs).
The framework generates the `.set` surface, help entries, storage (or
adapter calls) and tiered resolution. Existing `.setlastfm`-style
commands may remain or become wrappers.

**`.set` surface (context-split scope):** in-channel `.set <key>
<value...>` = channel setting, gated by the flag the definition declares
(channel-scoped Acl — channel admins by default, finer flags per
definition); via PM = the speaker's own account setting (any known user).
`.set` bare lists settings available in this context with current values
and resolution source (`#chan` / `network` / `default` / `you`),
pastebinned past the usual threshold. `.unset <key>` reverts to inherit;
`.set <key>` shows value + source.

**linktitles (decided: adapter):** registers its existing settings
(`enabled`, `ai_vision_*`, `url_log_chan`, …) as definitions backed by an
adapter over its typed `linktitles_settings` table through its existing
service layer — no data migration, its logic keeps querying its table,
while `.set` discovery and channel-admin gating come free. The adapter is
the template for other scripts with specialized storage (weather geo,
etc.).

**Future (explicitly deferred, needs its own design):** the web panel
becomes usable by all users — bot-issued recognition URLs (query-param
auth) for account/channel pages, and list views (aliases, settings)
rendered by the site instead of pastebins. Tracked as issue #138.

## Command surface (stubbed 2022, still the plan)

PM-only (PrivCmd): `register`, `auth`, `pass`, `paranoid`,
`setflags`/`addflags`/`delflags` (flags = ACLs). Passwords: argon2id via
`password_hash` (noted, confirm at build). WHOX field set `%uhna`
(user/host/nick/account; confirm at build).

**Code placement (owner note 2026-09-26):** as these pieces become core
components everything depends on, they live in lolbot's `library/` (core
 autoloaded namespace alongside `library/config` etc.); user-facing
commands for them remain in `scripts/`. Current `scripts/user/` files move
into `library/` as part of the engines task when the user system core
takes shape.

## Foundations build order (decided 2026-09-26)

1. **cmdr** (own repo, `knivey/Cmdr`): middleware pipeline + `#[Acl]`
   attribute. **DONE 2026-09-26** — branch `5.x` (pipeline, case-insensitive
   per-command middleware, lazily-resolved aliased attribute middleware,
   `MiddlewareAttribute` interface) + lolbot's fail-closed `#[Acl]`
   attribute, `Access::before()`, userResolver plumbing, `Acl::register()`
   in BotManager. Untagged; lolbot tracks `5.x-dev` via composer path repo
   until the v5 release. Engines-task TODO: wire deny output — BotManager's
   `call()`/`callPriv()` return values are currently discarded at both
   handlers, so deny strings must be surfaced there (bot-templated text, no
   `\2\2` marking needed). During development lolbot's composer.json points
   at the local clone via a `path` repository (symlinked) instead of
   packagist; release as a **new major version** (past cmdr feature sets
   bumped majors) and bump the constraint here when ready.
2. **`Irc\Client`**: **DONE 2026-09-26** — IRCv3 message tags in
   `Message::parse` (single-pass unescape; UNKNOWN path carries tags),
   `account-tag` + `extended-join` CAP REQs, `UserEvent::$account`
   populated on chat/pm/notice/nick/part/quit/kick, extended
   `JoinEvent` (`$account`, `$realname`), and `Client::whox()` returning
   token-correlated `Amp\Future`s (354/315, per-call unique ≤3-digit numeric tokens per the WHOX spec, replies mapped in canonical field order,
   timeout + disconnect resolution; foreign-label 354s still emit their
   numeric so `Nicks.php`'s legacy WHOX keeps working — note: Nicks has
   its own WHOX with label 777; the engines task should migrate it onto
   `Client::whox()`). Rebinding detection lives in the engines layer, not
   the client (owner decision).
3. **Core**: **DONE 2026-09-27** — per-network `users`/`user_hostmasks`
   entities (+ `Networks.auth_engines` JSON and `admin_hostmask_auth` bool),
   `library/user/` core (IdentityService, IdentityCache, EngineConfig,
   engines: account-tag / vhost-pattern (GameSurge regex) / whox (lazy) /
   hostmask / manual), PM commands (register/auth/pass/paranoid/setflags
   family), `user:flags` CLI, deny UX, web UI engine config with hot
   rebuild, and the Nicks.php migration onto `Client::whox()`. Known
   follow-ups (issues): cross-network user linking (user + admin flows),
   web UI user admin, alias-call deny passthrough, WHOIS-330/srvx deferred
   engines.
4. **Channel access**: **DONE 2026-09-27** — code-defined flag registry
   with groups + `*` (`library/user/Flags.php`, `admin` ⇒ `*`, runtime
   `Flags::define()` for scripts), `#[Acl(flag, channel: true)]` with
   union resolution (network flags ∪ channel grants → group expansion;
   superadmin before-hook bypasses channel checks too),
   `channel_flags` entity/migration (per-bot channel scoping, owner
   call 2026-09-27) + repos wired into the bundle, `.cflags` in-channel
   grant command (can't-exceed-your-own-power, all-or-nothing ops,
   empty-row cleanup), and registry validation on `user:flags` +
   PM `setflags`. Settings gating (step 5) depends on this.
5. **Settings registry**: `channel_settings` + `user_settings` entities +
   migration, `#[Setting]` attribute + programmatic registration with
   storage adapters, tiered resolver, context-split `.set`/`.unset`
   surface (pastebin threshold), linktitles adapter registration.
6. **Backlog unlock**: the admin/channel commands that have been waiting
   (e.g. #128's per-channel +v restriction uses both channel access and
   channel settings).

## Explicitly not doing (kept as ideas only)

- **Artbot guest-dir segregation** for unauthed art.
- Laravel policies / containers / ability inheritance.
- **Web panel for all users** (bot-issued recognition URLs, site-rendered
  lists replacing pastebins) — deferred until after the registry; needs
  its own design session (owner note 2026-09-27).

## Open questions

1. ~~Nick→account settings merge/upgrade flow~~ — resolved 2026-09-27:
   no migration, no forced accounts; nick-keyed commands stay as-is
   forever, with an optional account-override layer for opted-in commands.
2. Identity cache TTL value and WHOX batching limits.
3. ~~srvx command specifics~~ — resolved 2026-09-27: srvx not needed
   (WHOX covers GameSurge); demoted to a deferred idea alongside WHOIS 330.
