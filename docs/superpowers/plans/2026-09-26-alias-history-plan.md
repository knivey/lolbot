# Alias History — Implementation Plan

Source: GitHub issue #129 "keep an alias history" (user-requested `.revertalias`).
Design approved in chat by the owner on 2026-09-26.

## Goal

Every alias mutation is recorded in an append-only event log so channel
members can view a timeline, revert to any prior version, and recover an
accidentally removed alias.

## Owner decisions (binding)

- Every `.alias` save appends a version row; `.unalias` appends a `removed`
  marker; `.revertalias` appends a `reverted` marker (no new version number).
- Version numbers count `save` events only, 1..N, stable across reverts.
- History survives `.unalias` forever (keyed by network/chan/name, no FK to
  the live row). Re-creating an alias continues the version sequence.
- `.revertalias <name> [version]` with `-n/--new` for newest; default target
  is the previous version. Restoring an alias that is currently removed
  re-creates it.
- `.aliashistory <name>` always uses the web paste when there is more than
  one history entry; single entry stays in-channel.
- `.showalias` gains a version line (current version, total) plus the date
  of the current version.
- Existing aliases are backfilled as version 1 by the migration
  (`INSERT INTO alias_history ... SELECT ... FROM alias_aliases`); created
  timestamps for backfilled rows are the migration run time (accepted lie —
  no creation dates exist today), fullhost is the stored last setter.
- No retention cap.

## Global Constraints

- Follow existing patterns: entity in `scripts/alias/entities/` (runtime
  Doctrine mapping — the dir is NOT in bootstrap $paths and does not need to
  be; migrations are handwritten, see `Migrations/Version20260918120000.php`
  for the table-creation pattern).
- Migration must work on both SQLite and PostgreSQL (both are supported
  drivers; sqlite gets `PRAGMA foreign_keys=ON` at boot).
- AGENTS.md: never remove existing comments; never `git add -f`; the
  `\2\2` anti-bot marker policy — restored alias VALUES flow through the
  existing `handleCmd` output path which already applies it; history/timeline
  lines are bot-templated (version/date prefixes) so no new marker site is
  needed, but alias values echoed inside paste content must not gain markers
  (paste is not IRC output).
- Pure logic (version numbering, timeline building, revert-target resolution)
  must live in static methods unit-tested with PHPUnit; no network or DB in
  unit tests. DB/migration correctness is verified against a throwaway
  SQLite database, never the dev DB.
- Commit style: conventional commits, author `knivey <knivey@botops.net>`
  (`git -c user.name=... -c user.email=...`), commit after each task.
- Work happens directly on `master` per the owner's established session
  preference (no worktree).

## Tasks

### Task 1 — alias_history entity + migration + backfill

Create `scripts/alias/entities/alias_history.php` (namespace
`scripts\alias\entities`, table `alias_history`): id PK, network_id FK ->
Networks.id, chan, chanLowered, name, nameLowered, value (TEXT, nullable —
null for marker events), act (BOOL, nullable), cmd (STRING, nullable),
fullhost, created (DATETIME_IMMUTABLE), event (STRING: `save`|`removed`|
`reverted`, plus `note` TEXT nullable for the `reverted` marker to record
which version was restored).

Create `Migrations/Version20260926120000.php` following the
`Version20260918120000.php` pattern: create `alias_history` (id PK
autoincrement, index on (network_id, chanLowered, nameLowered, id) for the
per-alias timeline query), then backfill:
`INSERT INTO alias_history (network_id, chan, chanLowered, name, nameLowered,
value, act, cmd, fullhost, created, event) SELECT network_id, chan,
chanLowered, name, nameLowered, value, act, cmd, fullhost, <now>, 'save'
FROM alias_aliases` (both SQLite and PostgreSQL compatible; note sqlite has
no BOOLEAN literal issue — act is 0/1).

Verification: copy the dev sqlite DB to /tmp/opencode, point a scratch
script's connection at the copy, run the migration programmatically (or via
`php doctrine-cli.php migrations:migrate` with an env-overridden DB — use a
Doctrine connection to the copy and
`DependencyFactory`/`MigrationExecutor` OR simplest: `$connection->executeStatement()`
the up() SQL), then confirm `alias_history` exists, contains one `save` row
per existing alias, and `dieIfPendingMigration` reports clean for the copy.
php -l all new files. Commit.

### Task 2 — pure history logic + tests

Add static methods to the `alias` script class in `scripts/alias/alias.php`:

- `buildTimeline(array $events): array` — takes an array of history rows
  (associative arrays as the entity will hydrate them, ordered by id ASC)
  and returns a timeline: each entry `['version' => int|null, 'event' =>
  string, 'fullhost', 'created', 'value', 'act', 'cmd', 'note']` where
  `save` rows get version numbers 1..N and marker rows (`removed`,
  `reverted`) get `null`; plus a summary `['currentVersion' => int|null
  (latest save), 'removed' => bool (a removed marker occurs after the last
  save), 'totalSaves' => int]`. Shape: return `array{entries: list<array>,
  currentVersion: int|null, removed: bool, totalSaves: int}`.
- `resolveRevertTarget(array $timeline, ?int $version, bool $newest): ?array`
  — given the buildTimeline result: explicit `$version` -> that save entry
  (null if it doesn't exist); `$newest` -> the latest save; default (no
  args) -> the save previous to currentVersion (null if there is no
  previous); when `removed` is true the semantics shift: default/-n mean
  the latest save (restoring), explicit version still works; returns null
  when there is nothing revertable.

TDD: write `tests/Alias/AliasHistoryTest.php` FIRST covering: numbering
across interleaved save/removed/reverted events, removed-after-last-save
flag, revert default-previous / explicit / newest / single-version (no
previous -> null), removed-alias default restore, bad version number ->
null, empty history. Implement to green. Full `tests/Alias/` + `php -l` +
scoped phpstan (`scripts/alias/`, `tests/Alias/`) clean vs baseline.
Commit.

### Task 3 — command wiring

In `scripts/alias/alias.php`:

- Repo for `alias_history` in `init()` alongside the alias repo.
- `alias()`: after flush, append a `save` history row (snapshot of what was
  saved, fullhost, now).
- `unalias()`: after flush, append a `removed` marker row.
- `showalias()`: also load the timeline; add
  `\2Version:\2 N of M \2Updated:\2 <date of latest save>` (or
  `\2Removed:\2 <date>` when removed).
- New `revertalias` command (`#[Cmd("revertalias")]`, Syntax
  `<name> [version]`, `#[Option("--new", "...")]` and `-n` short option if
  cmdr supports short options — check `knivey\cmdr\attributes\Option`
  signature; if only long options exist, ship `--new` and note it): loads
  timeline, resolves target via `resolveRevertTarget`, sets the live alias
  row from the target snapshot (update or re-create), appends `reverted`
  marker with note `restored version N`, replies with confirmation
  including the version number and value preview (first ~80 chars).
- New `aliashistory` command (`#[Cmd("aliashistory")]`, Syntax `<name>`):
  loads timeline; >1 entry always pastes (reuse the
  `ServiceLocator`/`createPaste` pattern from `aliases()`, markdown
  timeline: version/event/who/when/value blocks); 1 entry prints in
  channel; 0 entries -> "no history for that alias".

Verification: full phpunit suite, scoped phpstan vs baseline, and a
scratch-DB smoke script (throwaway sqlite copy + seeded rows) exercising
buildTimeline/resolveRevertTarget through real Doctrine hydration and
replaying: save, update, unalias, revertalias default, revertalias --new,
aliashistory formatting function output (pure parts). Commit.
