# Era Follow-Ups Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the two remaining no-design follow-ups from the user-system/settings/testenv era (#146, #140) and retire the superseded umbrella (#34), leaving only the design-session trio (#135/#136/#138) and deferred #145 open.

**Architecture:** Two bounded tasks on master (owner consent). Review-before-commit protocol: work stays local until its review passes; push + issue-close only after clean review.

**Tech Stack:** PHP 8.1+, Doctrine ORM, PHPUnit 10, testenv tooling.

**Spec:** Issues #146 and #140 (fetch with `gh issue view N --repo knivey/lolbot`).

## Global Constraints

- Review-before-commit: implementer commits LOCALLY (identity `git -c user.name="knivey" -c user.email="knivey@botops.net"`) but does NOT push and does NOT close issues; the controller pushes + closes after clean review.
- TDD where behavioral (Task 2's gate, Task 1's reset/down artifact fix); the remaining Task 1 items are docs/config/cosmetics — pin with the cheapest honest check and state what was verified per item.
- Full suite baseline entering this plan: **1429 tests / 6306 assertions / 1 pre-existing deprecation**; zero new failures.
- phpstan `php -d memory_limit=1G vendor/bin/phpstan analyse <touched> --no-progress`: zero NEW errors.
- NEVER remove existing comments; carry/extend them.
- Scratch DBs via EnvStore only.

## Review Focus

1. Task 1's EnvStore signature change (`$create` param): every dbPath caller must still get the secure touch-create by default; only reset/down pass create:false.
2. Task 2 must not weaken the existing gate: users WITHOUT channel grants and WITHOUT a before-hook still fail exactly as before (regression test).
3. The AGENTS.md addition is one line, no restructuring.

---

### Task 1: #146 — polish batch

**Files:**
- Modify: `library/testenv/EnvStore.php` (dbPath `$create` param + docblock side-effect note)
- Modify: `testenv.php` (reset/down unlink the run db with a pure path)
- Modify: `testenv/client.php` (/help /quit wording)
- Modify: `scripts/user/user.php:482` ($csv → $flagsList)
- Modify: `cli_cmds/user_flags.php:117` (formatFlags → Flags::formatList)
- Modify: `.gitignore` (testenv/profiles/client_drain_test.yaml)
- Modify: `AGENTS.md` (one Gotchas line: output-string changes re-run the harness pinning them)
- Test: `tests/TestEnv/EnvStoreTest.php` (or the EnvStore-covering test file — find with grep) for the $create=false path; behavioral test for reset/down leaving no sqlite (extend tests/TestEnv/CliTest.php if it covers reset/down; otherwise assert via the EnvStore unit that dbPath(p, create:false) does not create the file)

RULING (controller): #146's M1 (final_seam_probe refresh/retire) is NO ACTION — the probe lives in /tmp scratch, not the repo; regenerate when needed. The issue-close comment records this.

Item details:
- **EnvStore::dbPath(string $profile, bool $create = true)**: when $create and file missing → touch + chmod 0600 (current behavior, unchanged for all existing callers). When $create=false → pure path, no filesystem side effect. Docblock: document BOTH the historical "creates the run directory on demand" and the db-file creation + 0600 rationale (run DBs may hold sasl_pass).
- **testenv.php reset + down**: after `EnvStore::wipe($profile)`, `@unlink(EnvStore::dbPath($profile, create: false))` so "run db removed" is true again (down also should not leave a 0-byte artifact; wipe already removes the dir contents — verify order: wipe deletes the dir; the unlink guards the artifact created by config_path's dbPath touch during unlink_config — read the flow and place the unlink so the end state is no sqlite file).
- **client.php /help**: `/quit` line → "close and exit (drains inbound first)".
- **user.php:482**: `$csv` holds a `', '`-joined list now — rename to `$flagsList` (name only, no behavior).
- **user_flags.php:117**: `implode(", ", ...)` → `Flags::formatList(...)` (+ import if needed).
- **.gitignore**: add `testenv/profiles/client_drain_test.yaml` (matches the ClientDrainTest temp profile; SIGKILL-only leak today).
- **AGENTS.md** Gotchas, one line: `- When a change alters user-visible output strings, re-run any harness that pins those strings (e.g. the /tmp/opencode e2es) before declaring done.`

- [ ] Steps: behavioral tests first (EnvStore create:false + reset/down artifact) → verify RED → implement all items → suite + scoped phpstan (`library/testenv/EnvStore.php testenv.php testenv/client.php scripts/user/user.php cli_cmds/user_flags.php AGENTS.md` — AGENTS.md excluded from phpstan obviously) → local commit `fix(testenv): polish batch — dbPath create flag, help wording, leftovers (#146)`

### Task 2: #140 — .cflags granter gate honors the superadmin before-hook

**Files:**
- Modify: `scripts/user/user.php` (cflags granter gate — the site computing the granter's power before `Flags::passes($granterUnion, $flag)` around :500-510; grep `granterUnion`)
- Test: `tests/User/` — the file covering cflags gating (grep for cflags in tests/User; likely extends the e2e-style harness used by ChannelAccessTest)

Requirement (issue #140): "Acl::middleware's channel path calls `Access::beforeAllows()` so a superadmin before-hook bypasses channel-scoped checks, but .cflags' granter-union gate does not — if an `Access::before()` hook is ever registered in production, the owner would be denied .cflags changes they can pass everywhere else. When wiring a superadmin hook, add `Access::beforeAllows($granterUser)` to the cflags gate."

Decision (owner approved by scheduling this): wire it NOW so the gate is correct the day a hook appears. The gate becomes: granter passes if `Access::beforeAllows($granterUser)` OR the existing channel-union check — check beforeAllows FIRST (mirroring Acl::middleware's order) so a superadmin never trips the can't-change error. Also mirror wherever the SAME union gate runs for the setflags PM family if the identical pattern exists (grep `granterUnion` / `passesInChannel` in user.php; only change gates that check the granter, never the target).

- [ ] Steps: failing test (before-hook registered returning true, granter with NO channel grant, `.cflags target +flag` succeeds; teardown MUST restore Access global state — follow existing teardown patterns) → verify RED → implement → suite + scoped phpstan (`scripts/user/user.php tests/User/`) → local commit `fix(user): cflags granter gate honors the superadmin before-hook (#140)`

### Controller closure (no implementer)

After Task 2's review passes: close #34 as superseded — comment pointing at docs/superpowers/specs/2026-09-26-user-system-design.md (steps 1-5 shipped), with the remaining wishes tracked in #135/#136/#138/#145 and today's follow-ups closed via #146/#140.
