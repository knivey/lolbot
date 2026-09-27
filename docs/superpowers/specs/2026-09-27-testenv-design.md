# Test Environment System — Design

Status: **design approved in brainstorm on 2026-09-27; not yet built.**
This document is the single source of truth for the test-env tooling.

## Why we want it

Every user-system task this week ended with a hand-rolled scratch harness
in /tmp (fake clients, fake events, copied DBs) approximating what a live
network provides for free. Live testing today means hand-editing config
and DB rows against real networks — tedious and risky. A first-class
test-env setup gives: quick deploy of a dev bot from a fresh DB/config
against the networks we actually run on, a driver client to interact
with it, and (later) the substrate for automated e2e checks. It would
have caught #134 (CAP-END stall) before it shipped, and it feeds #121
(getting started / docker) later — fresh-DB bootstrap is the same
machinery.

## Decisions (owner, 2026-09-27)

- **Real networks first (A).** Dev bot + driver connect to actual
  GameSurge / Libera / our own network. Local simulators (B) only if
  reconnect rate limits become a practical problem (GameSurge already
  grants us lifted restrictions; we also run our own network with no
  limits).
- **Our own network is the default fast loop.** It has IRCv3 caps but
  NO services — it exercises the EFnet-class path end-to-end (manual
  register/auth, hostmask engine, paranoid, .cflags) with zero etiquette
  or rate concerns, plus CAP negotiation.
- **Scheduling: built before user-system step 5** (settings registry) so
  `.set` work gets live channel testing for free.

## Profiles

Committed, small files in `testenv/profiles/` (format: YAML, matching
the repo's config style). One per network plus an `all`:

| profile | exercises |
|---|---|
| `ownnet` (default) | no-services EFnet-class flows + IRCv3 CAPs |
| `gamesurge` | WHOX, vhost pattern engine, services auth (lifted limits) |
| `libera` (+ optionally one of our Ergo nets) | account-tag auto-registration, real services |
| `all` | cross-network isolation checks |

Each profile describes:
- **network + server rows**: network name, pinned `auth_engines` where
  the test matters, `admin_hostmask_auth` as needed, and the server
  endpoint(s) (host/port/TLS) the bot connects to — connection info is
  seeded as the DB's server rows, same as production shape.
- **bot row**: dev nick (distinct from prod bots), trigger, test
  channels (test-only channels, never production ones).
- **driver identity**: nick (+ SASL account where the network has
  services). Test accounts are created by the owner as needed; on
  `gamesurge` the real account is used for the vhost path.
- **seed extras**: per-test-context users — extra users with flags,
  paranoid users, hostmask rows, channel_flags grants. Context dictates
  what's seeded.

**Default seed**: the driver identity's user row pre-created with the
`admin` flag (keyed to the account/nick the engines will resolve), so a
fresh DB doesn't need a manual `user:flags` bootstrap mid-test. A
profile (or CLI flag) may opt out to test the manual bootstrap path.

Secrets (SASL passwords) never live in committed profiles — they come
from a gitignored overlay file layered over the profile (e.g.
`testenv/secrets.yaml`, keyed by profile).

## Tool — `testenv.php`

`php testenv.php <profile> <command>`:

- `up` — wipe/create the profile's sqlite DB under a gitignored dir
  (e.g. `testenv/run/<profile>.sqlite`), run all migrations, apply the
  seed, generate the config the bot will read, then exec `php lolbot.php`
  against it (foreground; Ctrl-C tears down).
- `client` — run the driver client (separate terminal from `up`).
- `reset` — wipe the DB so the next `up` reseeds fresh.
- `down` — stop/cleanup (foreground model: informational + removes the
  generated config/DB per flags).

**Enabler:** `lolbot.php`/`bootstrap.php` accept a config path override
(argv or env, like `artbots.php` already takes argv). The prod
`config.yaml` and the dev sqlite DB are never read or written by the
tool.

## Driver client

A terminal REPL, Amp-based, reusing `library/Irc` message parsing where
practical. Connects as the profile's driver identity to the same
network/channel as the bot under test.

v1 behavior:
- displays channel + PM traffic involving the bot (readable, timestamped);
- plain typed text → channel message; `/msg <nick> <text>`, `/join`,
  `/part`, `/raw <line>` for protocol-level poking;
- `/help` lists commands.

v2 (explicitly later, not this build): scriptable macros and assertions
(automated e2e harness driving the same machinery).

## Guardrails

- Test channels only — profiles never list production channels.
- Dev nicks distinct from production bots.
- Secrets only in the gitignored overlay.
- Tool touches only its own `testenv/run/` artifacts.
- Rate-limit etiquette on public networks; reconnect storms are a
  non-issue on ownnet/gamesurge (owner-verified exemptions).

## Explicitly out of scope (v1)

- Local network simulators (option B) — revisit if limits bite.
- artbots.php support — the same profiles can drive it later.
- Scriptable/assertion client macros (v2).
- Docker/packaging (#121 stays separate; shares the fresh-DB machinery
  conceptually only).

## Foundations build order

1. Config-path override for lolbot.php/bootstrap.php.
2. Profile loading (committed YAML + secrets overlay) + seed
   application + fresh-DB lifecycle (up/reset).
3. `testenv.php` tool commands.
4. Driver client REPL.
5. Smoke: full loop on `ownnet` (register/auth/paranoid/.cflags/.set-less
   gating) — the acceptance test.
