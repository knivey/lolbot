# User System — Coalesced Design

Status: **design notes, not yet approved for build.** This document merges
everywhere the user-system design has lived so there is one source of truth.
Sources: issue #34, the header comment and stubs in `scripts/user/user.php`
(2022), `scripts/user/Access.php`, and owner notes recorded 2026-09-26.

## Why we want it

Bot-admin and channel-admin oriented commands have been held off
specifically because there is no user system. Once accounts + ACLs exist we
can plan many commands we have been lacking (issue #34's real payoff).

## Core concepts

- **User account**: the central identity. Other subsystems key off a
  `user_id` instead of nicks.
- **Scripts own their own settings** — accounts are NOT centralized settings
  blobs. Each script keeps its own set-commands/tables keyed by `user_id`,
  exactly like scripts do today keyed by nick.
- **Flags are ACLs**: a user carries flags; each flag maps to an ACL check.
  `Access::define()` / `Access::allowed()` (in `scripts/user/Access.php`)
  is the existing mechanism: scripts register named ACL callables and
  commands gate on them.
- Admin ACL first; **channel ACL deferred** ("keeping it simple for now").

## Auth engines (pluggable, per network)

1. **Hostmask matching** — the baseline engine. Users logged in by matching
   a stored hostmask; needs a hostmask generator (`*!*ident@fullhost`).
   A per-user setting (`paranoid`, stubbed) decides whether the hostmask is
   remembered or the user must auth on every connect.
2. **Manual PM auth** — `register` / `pass` / `auth` (all stubbed as
   PrivCmds). The bot may also auth users itself on connect.
3. **Services-based, GameSurge reality** (owner notes): GameSurge HAS
   services but NO ircv3 caps. Users can be tracked through:
   - matching a `.*.gamesurge` hostmask (services vhost after auth),
   - **WHOX** queries (`account` field),
   - **srvx** commands (AUTHSERVICE etc.).
4. **ircv3 account-tag** — the eventual clean engine on networks that
   support it; requires `Irc\Client` work (see Foundations). Optional
   per-network engine. Old notes: "on networks with services try to use irc
   caps to auth maybe? will require changing irc lib".

Old open musing kept for reference: "look at laravel gates for better idea
on doing things with modular scripts" (inspiration for the ACL surface, not
a commitment).

## Two-tier settings tracking (accepted messiness)

Users must NOT be forced to auth to use settings. Therefore:

- **Unauthed**: settings stay tied to the IRC nick (current behavior,
  e.g. `.setlastfm`, `.setlocation`).
- **Authed**: the same kinds of settings are tied to the user account,
  tracked by `user_id` and not nick.

Owner: "kinda a mess to implement a two tiered tracking system but it will
probably be worth it." Proposed resolution (NOT yet decided): when a nick is
associated with an account, lookups prefer account-owned settings; otherwise
nick-owned. Migration/merge of existing nick settings into accounts is an
open question.

## Central settings registration

A central way for scripts to register settings, so commands don't
re-implement the wheel and sets are consistent everywhere; new scripts
easily register their sets into the system.

- Rough shape: like **cmdr** for commands (declare attributes / register,
  the framework does parsing + help + storage) and like the **linktitles
  settings table** pattern in the DB today (per network/channel scoping,
  tiered resolution via `SettingsResolver`).
- Existing settings commands (`.setlastfm`, `.setlocation`) can remain as
  they are, or become thin wrappers over the registry.
- The registry owns storage + resolution; scripts keep owning semantics
  (defaults, validation hooks).

This is likely its own spec/plan when we get there; it is recorded here
because its identity model (`user_id` vs nick) depends on this system.

## Command surface (stubbed in 2022, still the plan)

PM-only (PrivCmd): `register`, `auth`, `pass`, `paranoid`,
`setflags`/`addflags`/`delflags` (flags = ACLs). Once the system lands,
plan the admin-command backlog that has been blocked on it.

## Foundations needed first (Irc\Client and friends)

Current `Irc\Client` state: CAP negotiation exists (`CAP LS`, requests
`multi-prefix` and `sasl`) but nothing else. Needed:

- **WHOX** support (send `WHO #chan %ahn...`, parse the 354 numeric) —
  feeds the hostmask/account engines.
- **ircv3 `account-tag` + `extended-join`** CAPs — gives per-message
  account identity on capable networks; wire into ChatEvent (typed events
  exist, extend them).
- **srvx command interface** (GameSurge): auth checks via services bots —
  needs a small per-network service-command abstraction.
- Hostmask matcher/generator utility (shared with Ignore matcher concepts).

## Explicitly not doing (kept as ideas only)

- **Artbot guest-dir segregation** for unauthed art: "we probably wont do
  but can keep it as an idea — we dont get much new art these days and the
  current system works fine."
- Channel ACLs (deferred, not rejected).
- cmdr middlewares for auth checks: "possibly later" — Access-based checks
  in command bodies are fine to start.

## Open questions

1. Password storage scheme for `pass`/`register` (argon2id via
   `password_hash` presumably — confirm).
2. How WHOX/srvx results are cached and invalidated (WHO on join? on
   command use? TTL?).
3. Nick→account setting merge/upgrade flow when a user registers.
4. Whether `User` objects ride on ChatEvent (eager per-message lookup) or
   resolve lazily at command time.
5. Scope of the settings registry spec (separate doc) and its DB shape.
