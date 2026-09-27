# User System — Coalesced Design

Status: **design approved in brainstorm on 2026-09-26; not yet built.**
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
- Admin ACL first; **channel ACL deferred**.

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

## Auth engines (pluggable chain, per network)

```
1. account-tag (ircv3)      authoritative per-message account, where CAP exists
2. services (GameSurge)     WHOX 354 account field + srvx AUTHSERVICE
3. hostmask                 stored per-user masks (baseline everywhere)
4. manual session           .auth via PM, held until invalidated
```

First engine to yield an answer wins; engines are pure resolvers
`(network, nick, host) → ?user_id`. `paranoid` (per-user flag) disables the
hostmask engine for that account, forcing manual auth each connect.

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
| WHOIS 330 (RPL_WHOISACCOUNT) | services ircds (atheme/anope family); probe by trying | account for one nick, on demand |
| standard WHO 352 | always available | ident@host only — feeds the hostmask engine |
| NAMES / JOIN / PM events | always available | incremental ident@host (Nicks-style tracking) |
| network-specific (GameSurge vhost regex, srvx) | per-network config | account from host / services query |

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

## Two-tier settings tracking (accepted messiness)

Users are never forced to auth. **Unauthed**: settings tied to the IRC nick
(current behavior, `.setlastfm` etc). **Authed**: same settings tied to the
user account via `user_id`. Proposed (not finally decided): when a nick is
associated with an account, reads prefer account-owned settings then
nick-owned; writes go to the account tier. The migration/merge flow for
existing nick-tier settings is an open question the settings registry spec
must answer.

## Central settings registry (decided: single registry table)

One table: `settings(script, key, network_id, chan_lowered, user_id,
nick_lowered, value JSON, created/updated)` with a tiered resolver
(the linktitles `SettingsResolver` pattern generalized). New settings need
zero migrations; one index serves all lookups.

Scripts register declaratively, cmdr-style:

```php
#[Setting("lastfm", "your last.fm username")]
#[SettingScope(Setting::USER)]      // also NICK / CHANNEL / NETWORK / GLOBAL
function validateLastfm(string $value): string|ValidationError { ... }
```

The framework generates the `.set <key>` / `.get <key>` surface, help
entries, storage, and identity-tiered resolution. Existing `.setlastfm`
style commands may remain or become wrappers over the generated surface.
Scripts with heavy relational data (portfolios, geo columns) keep their own
entities and only register command surface if desired.

The registry owns the two-tier resolution rule above — one resolution rule
in one place, not per script. Likely its own spec/plan; recorded here
because its identity model depends on this system.

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
3. **Core**: `users` + `user_hostmasks` entities + migration,
   `Access::before()`, hostmask + GameSurge engines, identity cache, PM
   commands (`register`/`auth`/`pass`/`paranoid`/flags).
4. **Settings registry**: single table + attributes + generated `.set`
   surface + two-tier resolution.
5. **Backlog unlock**: the admin/channel commands that have been waiting.

## Explicitly not doing (kept as ideas only)

- **Artbot guest-dir segregation** for unauthed art.
- Channel ACLs (deferred, not rejected).
- Laravel policies / containers / ability inheritance.

## Open questions

1. Nick→account settings merge/upgrade flow when a user registers (must be
   answered by the settings registry spec).
2. srvx command specifics (AUTHSERVICE query surface) — verify against the
   live network when building engine 2.
3. Identity cache TTL value and WHOX batching limits.
