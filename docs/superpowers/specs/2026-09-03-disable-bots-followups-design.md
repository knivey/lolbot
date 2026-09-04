# Disable-Bots Follow-Ups Design

Date: 2026-09-03
Status: Approved
Parent feature: `docs/superpowers/specs/2026-09-03-disable-bots-design.md` (merged)

## Problem

The disable-bots feature shipped with five acknowledged minor follow-ups:
(1) the web create forms render the disabled checkbox but ignore it;
(2) the bool-parse expression is duplicated across 7 CLI sites;
(3) its error message ("must be true or false") is inaccurate —
`FILTER_VALIDATE_BOOLEAN` also accepts on/off/yes/no/y/n/"" ;
(4) `BotManager::syncBot()`'s un-held path doesn't refresh the bot's network
(kept asymmetric with the held path); (5) cosmetics: QUIT reason is "removed"
even for disables, brace-less `if` in `Network::__toString()`, the
`showsets()` `?? null` workaround exists only because `Bot::$sasl_user` /
`$sasl_pass` lack defaults, and 12 advisory PHPUnit "mock without
expectations" notices linger in the test suite.

## Decisions (user-approved)

- Test cleanup converts ALL expectation-free `createMock()` calls repo-wide to
  `createStub()` (target: 0 notices). The pre-existing phpstan baseline
  (`spawn()` body Server|null, script constructor params) is NOT touched.
- Bool parsing stays permissive (`FILTER_VALIDATE_BOOLEAN` set) with an honest
  message: `"$what must be a boolean value (true/false/1/0/on/off/yes/no)"`.
- Work happens in an isolated worktree, merged back when green.

## Components

### 1. Create paths honor the checkbox

- `ConfigService::createBot(Network $network, string $name, bool $disabled = false)`
  sets `$bot->disabled` before the single create flush → one `create` change
  event → live apply's existing `!isDisabled()` guard skips spawning (no
  spawn-then-drop flicker).
- `ConfigService::createNetwork(string $name, bool $disabled = false)` — same
  pattern.
- `web_bots_create` / `web_networks_create` pass
  `isset($_POST['disabled'])`.
- All existing callers (CLI `bot:add`/`network:add`, tests) use the default —
  backward compatible, no other changes.
- CLI add commands stay unchanged (an operator wanting a disabled bot uses
  `bot:set` after; out of scope).

### 2 + 3. Shared bool parser

- New `library/config/SettingBool.php`:
  `lolbot\config\SettingBool::parse(string $value, string $what = 'Value'): bool`
  — `FILTER_VALIDATE_BOOLEAN` + `FILTER_NULL_ON_FAILURE`, null →
  `InvalidArgumentException("$what must be a boolean value (true/false/1/0/on/off/yes/no)")`.
  Placed in `lolbot\config\` (existing PSR-4: `library/config`) so both
  `lolbot\cli_cmds\*` and `scripts\linktitles\cli_cmds\*` can use it.
- Replaces all duplications:
  - `cli_cmds/bot_set.php` (disabled)
  - `cli_cmds/network_set.php` (disabled)
  - `cli_cmds/server_set.php` (ssl/throttle branch)
  - `scripts/linktitles/cli_cmds/linktitles_set.php` (enabled,
    ai_vision_disabled — both lines)
  - `cli_cmds/service_set.php` — delete private `parseBool()`, delegate its
    call sites to `SettingBool::parse`.
- Message text changes everywhere it was "must be true or false" — tests
  asserting the old message update to the new one.

### 4. syncBot symmetry

In `BotManager::syncBot()` un-held path, add
`$this->em->refresh($fresh->network);` before `$this->em->refresh($fresh);`
(mirroring held-path order: network first, then bot).

### 5. Cosmetics

- `BotManager::drop(int $botId, string $reason = "removed")` — QUIT line
  becomes `quit :$reason`. Disable-triggered drops (syncBot, network/update
  case) pass `"disabled"`; the bot-delete path keeps the default. One test
  asserts `sendNow('quit :disabled')` on disable.
- `Network::__toString()` — braces on the `if`.
- `Bot::$sasl_user` / `$sasl_pass` get `= null` defaults; `bot_set::showsets()`
  reverts its `?? null` workaround to a plain `$bot->$setting` read.
- Expectation-free `createMock()` → `createStub()` in:
  `tests/Config/BotManagerApplyTest.php`,
  `tests/Config/BotManagerStatusTest.php`,
  `tests/Linktitles/FormatImageResponseTest.php`,
  `tests/Remindme/DeliverTest.php`.
  Rule: any mock instance with no `expects()` configured on it becomes a stub;
  mocks that receive `expects()` stay `createMock()` (stubs don't expose the
  MockObject expectation API). `RecordingBotManager`'s MockBuilder `getMock()`
  construction stays (it produces `MockObject`s some tests configure).

## Error handling

Unchanged shapes: CLI garbage input → `InvalidArgumentException` (new
message); create paths accept no invalid input (web checkbox is bool);
`SettingBool::parse` throws only on unparseable strings.

## Testing

- New `tests/Config/SettingBoolTest.php`: accepts true/false/1/0/on/off/yes/no
  (and empty string → false), rejects garbage with exact message, `$what`
  prefixing works.
- `ConfigServiceCoreTest` additions: `createBot(..., disabled: true)` and
  `createNetwork(..., true)` persist the flag.
- `BotManagerApplyTest`: disable-drop test gains a `sendNow('quit :disabled')`
  expectation; existing suite green.
- Suite target: 0 failures, **0 PHPUnit notices**, no new phpstan errors on
  touched paths (pre-existing baseline untouched).

## Out of scope

- phpstan pre-existing baseline (spawn() Server|null, script constructor
  params).
- `bot:add`/`network:add` disabled options.
- The web create forms' visual layout (checkbox already renders).
