# Backlog Loose Ends Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the five fully-specified follow-up issues from the user-system/settings/testenv era that need no design session: #137, #141, #139, #144, #142.

**Architecture:** Seven bounded tasks on master (owner consent — established session preference). Each task = one GitHub issue (or one issue-half for the two polish batches), TDD, own commit, own review gate. Tasks are independent; the only ripple is Task 3's constructor change touching test/e2e harnesses.

**Tech Stack:** PHP 8.1+, Amp v3 async, Doctrine ORM 3 + migrations, PHPUnit 10, cmdr v5 (attribute `#[Acl]`, middleware deny-string convention).

**Spec:** The GitHub issues themselves (fetched via `gh issue view N --repo knivey/lolbot`): #137, #141, #139, #144, #142. Each task below quotes the binding requirements verbatim from its issue.

## Global Constraints

- Commit as `git -c user.name="knivey" -c user.email="knivey@botops.net" commit`. Work on master directly; push after every task; close the task's issue with `gh issue close N --repo knivey/lolbot --comment "Implemented in <short-sha>: ..."` summarizing verification.
- TDD strictly: failing test first, watch it fail, minimal code, watch it pass. Every task runs the FULL suite before commit (`vendor/bin/phpunit`). Suite baseline entering this plan: **1386 tests, 6162 assertions, 1 pre-existing deprecation** (cmdr `get_defined_functions` via BotManagerApplyTest). Zero new failures/warnings allowed.
- phpstan: `php -d memory_limit=1G vendor/bin/phpstan analyse <touched paths> --no-progress`. The repo has a large pre-existing baseline; the bar is ZERO NEW errors. Verify by comparing against a `git stash` run when in doubt.
- NEVER remove existing comments; carry them into edited code (AGENTS.md). Never trim art output strings; never remove/alter `\2\2` anti-bot markers (not touched by this plan, but binding).
- Scratch test DBs only via `library\testenv\EnvStore` (`EnvStore::wipe()`, `EnvStore::dbPath()`) — never the dev sqlite.
- Test namespace convention: `tests/<Dir>/<File>` with `namespace Tests\<Dir>;` (see tests/Alias/AliasHistoryTest.php).
- Regression e2e harnesses live in /tmp/opencode: `setcmd_e2e.php`, `netonly_e2e.php`, `target_e2e.php`, `minmode_e2e.php`. Tasks that change `UserSystem`'s constructor (Task 3) must update every harness that constructs it and re-run all four; they must stay green.
- cmdr rest-arg syntax is `[value]...` not `[value...]` — if a test adds command syntax, probe it.

## Review Focus

1. **Task 3 ripple:** any `new UserSystem(` or `->create(` call site missed → runtime fatal. The scan: `grep -rn "new UserSystem(\|UserSystemFactory" --include="*.php" scripts/ library/ tests/ lolbot.php` plus the /tmp harnesses.
2. **Task 4 regression:** channels with no channel-tier row must still resolve network→global→default — titles keep working where they work today. Test: channelless row + network tier on.
3. **Task 1 scope:** only string returns (middleware denies) get the notice; normal command returns must not change alias behavior.
4. **Task 2 semantics:** display-only unification — `Flags::expand`/`passes` behavior must be untouched except the empty-grant filter; `Flags::define` filter must not break the built-in DEFAULTS.
5. **Task 6 liveness:** /quit must ALWAYS terminate (bound the drain with a timeout) — a hanging client blocks scripted runs.
6. **Task 7 compatibility:** Seeder fail-fast must not reject valid fixtures (null value, pure string lists) — all existing profiles in testenv/profiles must still load.

---

### Task 1: #137 — alias passes ACL deny strings to the user

**Files:**
- Modify: `scripts/alias/alias.php:399` (handleCmd)
- Test: `tests/Alias/AliasDenyPassthroughTest.php` (create; follow the construction pattern the file's class permits — read `scripts/alias/alias.php`'s constructor first) OR, if the ctor cannot be satisfied with stubs, a scratch e2e `/tmp/opencode/aliasdeny_e2e.php` following `/tmp/opencode/target_e2e.php`'s harness (real Cmdr, fake client recording `notice()`); state which in the report.

**Interfaces:**
- Consumes: cmdr's deny-string convention — `$router->call()` returns a string when middleware short-circuits (e.g. `#[Acl]` "auth required"); BotManager's chat/pm handlers already do `if (is_string($ret)) { $bot->notice($args->nick, $ret); }` (library/BotManager.php:300-305, 338-341).
- Produces: nothing downstream; standalone fix.

Requirement (issue #137): "scripts/alias/alias.php handleCmd() discards the return of $this->router->call() — when an aliased command is #[Acl]-gated the middleware deny string never reaches the user (silent no-op). BotManager's chat/pm handlers were fixed in step 3; alias needs the same is_string($ret) -> notice treatment."

Current code (alias.php:395-402):

```php
                $this->router->call($alias->cmd, $value, $args, $bot);
            } catch (\Exception $e) {
                $bot->notice($args->nick, $e->getMessage());
            }
```

- [ ] **Step 1: Write the failing test**

Register a gated command on a real `knivey\cmdr\Cmdr` (mirror /tmp/opencode/target_e2e.php: `require scripts/user/user.php`, `$router->loadFuncs()`, `Acl::register($router)`, `UserSystemFactory::wireAccess()`, and use an existing `#[Acl]`-gated command such as `setflags` with an UNAUTHED speaker so the acl middleware returns "auth required"). Seed an alias row (name `poke`, chan `#gate`, cmd `setflags`, value `<args>`) through whatever repo handleCmd wants (fake in-memory repo returning an alias-shaped entity is fine if the entity allows; otherwise hydrate a real `scripts\alias` alias entity on the scratch EM). Assert:

```php
// gated alias -> deny string reaches the nick via notice()
$out = $client->notices;            // fake client records notice($nick, $msg)
assert(in_array('auth required', $out, true));
// non-gated alias (e.g. aliasing `echo`-like plain command) -> NO notice, no crash
```

- [ ] **Step 2: Run test to verify it fails**

Expected: FAIL — "auth required" absent (call return discarded; today nothing is sent).

- [ ] **Step 3: Write minimal implementation**

```php
                // middleware deny strings (e.g. acl "auth required") flow
                // out of call(); any string return is a short-circuit
                // message for the requesting nick — same convention as
                // BotManager's chat/pm handlers
                $ret = $this->router->call($alias->cmd, $value, $args, $bot);
                if (is_string($ret)) {
                    $bot->notice($args->nick, $ret);
                }
```

- [ ] **Step 4: Run test to verify it passes** (plus full suite)

- [ ] **Step 5: Commit + close #137**

`fix(alias): pass middleware deny strings through to the nick (#137)`

---

### Task 2: #141 — unify flag-list separators + empty-grant filter

**Files:**
- Modify: `library/user/Flags.php:43-47` (define), new `formatList()` helper
- Modify: `scripts/user/user.php:374-375, 387, 482, 504-505, 552`
- Modify: `cli_cmds/user_flags.php:101`
- Test: `tests/User/FlagsTest.php` (exists — extend; create if missing)

**Interfaces:**
- Produces: `Flags::formatList(array $flags): string` — `implode(', ', $flags)`; the one place list separators live.

Requirement (issue #141): "(1) unknown/valid flag lists use ', ' on user:flags + PM setflags but ',' in .cflags (and '; ' between error kinds) — unify in one place. (2) `Flags::define('x', [''])` lets an empty-string grant surface in expand() output (input side filters it, grants side doesn't) — one-line hardening, unreachable from user input."

RULING (recorded in ledger): flag LISTS unify on ', ' via `Flags::formatList` at every site. The `'; '` at user.php:514 joins distinct error MESSAGES (not a flag list) — it stays. Sites:

| Site | Today | Becomes |
|---|---|---|
| user.php:374-375 | `implode(', ', ...)` ×2 | `Flags::formatList(...)` |
| user.php:387 | `implode(',', $user->flags)` | `Flags::formatList($user->flags)` |
| user.php:482 | `implode(',', Access::flagArray($row))` | `Flags::formatList(...)` |
| user.php:504-505 | `implode(',', ...)` ×2 | `Flags::formatList(...)` ×2 |
| user.php:552 | `implode(',', $row->flags)` | `Flags::formatList($row->flags)` |
| user_flags.php:101 | `implode(', ', ...)` ×2 | `Flags::formatList(...)` ×2 |

- [ ] **Step 1: Write failing tests** (in tests/User/FlagsTest.php)

```php
public function test_define_filters_empty_string_grants(): void
{
    Flags::reset();
    Flags::define('grp', ['ok', '']);
    $this->assertSame(['ok'], Flags::definitions()['grp']);
    $this->assertSame(['ok'], Flags::expand(['grp'])); // '' never surfaces
}

public function test_format_list_uses_comma_space(): void
{
    $this->assertSame('a, b, c', Flags::formatList(['a', 'b', 'c']));
    $this->assertSame('', Flags::formatList([]));
}
```

- [ ] **Step 2: Verify RED** (formatList missing → Error; define('') filter absent → FAIL)

- [ ] **Step 3: Implement**

In `Flags::define` (Flags.php:43-47) change the filter line to also drop `''`:

```php
        self::$definitions[$name] = array_values(
            array_filter($grants, fn ($g) => is_string($g) && $g !== ''),
        );
```

Add beside the other public statics:

```php
    /**
     * One place flag lists are formatted for display — every user-facing
     * flag list (user:flags, PM setflags, .cflags) renders through this.
     *
     * @param array<int, string> $flags
     */
    public static function formatList(array $flags): string
    {
        return implode(', ', $flags);
    }
```

Then swap the seven sites per the table (keep surrounding strings verbatim otherwise — e.g. user.php:374 `'unknown flag(s): ' . Flags::formatList(array_values(array_unique($unknown))) . ' (valid: ' . Flags::formatList(array_keys(Flags::definitions())) . ')'`).

- [ ] **Step 4: Verify GREEN** — new tests + `vendor/bin/phpunit` full suite + `php /tmp/opencode/target_e2e.php` (its expected strings use ', ' for PM path — must still pass; if any /tmp harness expected the OLD ', ' .cflags output, update the harness expectation, not the code)

- [ ] **Step 5: Commit + close #141**

`fix(user): one separator for flag lists + empty-grant hardening (#141)`

---

### Task 3: #139 — channelByName scoped to the sender's bot

**Files:**
- Modify: `library/user/UserSystem.php` (ctor + channelByName DQL :35-53)
- Modify: `library/user/UserSystemFactory.php:47` (`create()` signature) and its caller(s) (`grep -rn "UserSystemFactory\|->create(" library/BotManager.php scripts/ --include="*.php"`)
- Modify: `tests/User/AclMiddlewareTest.php:82`, `tests/User/ChannelAccessTest.php:54`, `tests/User/ChannelAccessTest.php:172` (ctor sites — add a `new Bot()` wired to the network)
- Test: extend the UserSystem construction test home found in tests/User (or `tests/User/ChannelByNameTest.php` new file, scratch-EM pattern from tests/Settings/MinAccessTest.php setUpBeforeClass)
- Update + re-run: /tmp/opencode harnesses that construct UserSystem (`grep -l "new UserSystem(" /tmp/opencode/*.php`)

**Interfaces:**
- Produces: `UserSystem::__construct(Network $network, Bot $bot, IdentityService $svc, UserRepos $repos, IdentityCache $cache, EntityManager $em)` — new second param `public readonly \lolbot\entities\Bot $bot`; `UserSystemFactory::create(Network $network, Bot $bot, Client $client): UserSystem`.

Requirement (issue #139): "the lookup should be scoped to the sender's bot: plumb the Bot entity into the UserSystem bundle and add `AND b = :bot` to the DQL (or catch NonUniqueResultException -> null + log)."

DQL change (UserSystem.php:40-44):

```php
        $q = $this->em->createQuery(
            'SELECT c FROM lolbot\entities\Channel c JOIN c.bot b'
            . ' WHERE b.network = :net AND b = :bot AND LOWER(c.name) = :name',
        );
        $q->setParameter('net', $this->network);
        $q->setParameter('bot', $this->bot);
        $q->setParameter('name', mb_strtolower($chan));
```

Update the channelByName docblock (carry the per-bot scoping text; add that the lookup is scoped to THIS bundle's bot). Update the class docblock's bundle description if it enumerates the ctor contents.

- [ ] **Step 1: Write failing test**

Scratch EM (MinAccessTest recipe): Network `N`, TWO Bots `b1`,`b2` each with a Channel named `#dupe`; bundle built for `b1`. 

```php
$bundle1 = new UserSystem($net, $b1, $svc, $repos, $cache, $em);
$this->assertSame($b1chan, $bundle1->channelByName('#dupe'));   // sender's row
$this->assertSame($b1chan->id, ...); // b1's channel, not b2's
$this->assertNull($bundle1->channelByName('#missing'));
```

Under the old code the first assertion throws NonUniqueResultException — that IS the failure demonstration (catch it to assert it throws if clearer).

- [ ] **Step 2: Verify RED** (NonUniqueResultException / TypeError on ctor arity)

- [ ] **Step 3: Implement** ctor param, factory signature + pass-through (BotManager spawn has `$dbBot`), DQL, and update the three test ctor sites + any other call sites the grep finds + /tmp harnesses (`new UserSystem($net, $botE, ...)` — the harnesses already create Bot entities).

- [ ] **Step 4: Verify GREEN** — full suite + ALL FOUR /tmp e2es green

- [ ] **Step 5: Commit + close #139**

`fix(user): scope channelByName to the sender's bot (#139)`

---

### Task 4: #144 item 1 — linktitles gate consults the channel tier

**Files:**
- Modify: `library/BotManager.php:69` (state doc), `:83-85` (spawn seed), `:253` (gate), `:500-509` (reload)
- Test: `tests/Config/BotManagerApplyTest.php` (extend — it drives the real spawn on a scratch EM) or a focused new test following its harness

**Interfaces:**
- Consumes: `SettingsResolver->linktitlesEnabled(Network, ?Channel): bool` (cascades channel→network→global→LinktitlesDefaults, identity-map-refreshed).
- Produces: per-bot state `$st->linktitlesEnabled` becomes `array<int|string, bool>` — lazy per-channel cache keyed by channel id (0 for no-row channels), cleared by `reloadLinktitlesEnabled()`.

Requirement (issue #144 item 1): "BotManager linktitles master switch is network-tier-only + cached at spawn (refreshed only on linktitles_setting ConfigChange) — a channel-tier `enabled` never gates the runtime (pre-existing from the web panel too). Make the gate consult per-channel resolution."

Current gate (BotManager.php:253):

```php
                if ($st->linktitlesEnabled) {
                    async(fn () => $linktitles->linktitles($bot, $args->nick, $args->chan, $args->identhost, $args->text));
                }
```

New shape (the chat handler has `$dbBot` and the resolver can be cheaply held per-call or in state):

```php
                // channel-tier aware gate (#144): resolve channel row →
                // network → global lazily per channel and cache on the
                // per-bot state; ConfigChange clears the cache. The old
                // net-tier-only flag let a channel tier never gate.
                $chanRow = null;
    foreach ($dbBot->getChannels() as $c) {
        if (mb_strtolower($c->name) === mb_strtolower($args->chan)) { $chanRow = $c; break; }
    }
    $key = $chanRow?->id ?? 0;
    if (!array_key_exists($key, $st->linktitlesEnabled)) {
        $st->linktitlesEnabled[$key] = (new \lolbot\config\SettingsResolver($entityManager))
            ->linktitlesEnabled($dbBot->network, $chanRow);
    }
                if ($st->linktitlesEnabled[$key]) {
                    async(fn () => $linktitles->linktitles($bot, $args->nick, $args->chan, $args->identhost, $args->text));
                }
```

- Spawn (`:83-85`): seed `$st->linktitlesEnabled = [];` (keep a comment pointing at the lazy cache). `:69` docblock updated to the array shape.
- `reloadLinktitlesEnabled()` (`:500-509`): body becomes `$this->state[$botId]->linktitlesEnabled = [];` (clear-on-change), keep signature + guard; adjust its docblock/comment.

- [ ] **Step 1: Write failing test** (BotManagerApplyTest harness): network-tier enabled + channel-tier row DISABLED for channel A, none for channel B → URL message in A does NOT dispatch linktitles, in B it does. Assert via… follow how BotManagerApplyTest observes dispatch (a spy on the linktitles instance or its event dispatcher). Also: network disabled + channel enabled → A dispatches (override works upward too).

- [ ] **Step 2: Verify RED** (channel-tier row ignored under current code)

- [ ] **Step 3: Implement** per above (fix indentation to match surrounding code)

- [ ] **Step 4: Verify GREEN** — full suite (BotManagerApplyTest especially), phpstan zero-new on BotManager.php

- [ ] **Step 5: Commit** (issue #144 stays OPEN — Task 5 completes it)

`fix(linktitles): channel-tier enabled gates the runtime (#144)`

---

### Task 5: #144 items 2,4,5 — KEYS dedup, adapter throw, cosmetics

**Files:**
- Modify: `scripts/linktitles/entities/linktitles_setting.php` (new public const)
- Modify: `library/config/ConfigService.php:576-580` (private const → reference), `scripts/linktitles/settings_adapter.php:37-42` (KEYS → reference), `scripts/linktitles/cli_cmds/linktitles_set.php:28` (hardcoded list → reference)
- Modify: `scripts/linktitles/settings_adapter.php` `resolve()` (unknown network → throw)
- Modify: `scripts/settings/settings.php` (unify "(adapter)" formatting to `(source: ...)` — grep it first; list path is around :241/:328)
- Modify: `scripts/weather/weather.php:259` (drop the unused `$nick = u($args->nick)->lower();` line) + si-units note in the location-override docblock (:260-264)
- Test: `tests/Settings/LinktitlesAdapterTest.php` (extend — throw case + const references), plus grep-verifying the three references

**Interfaces:**
- Produces: `linktitles_setting::WRITABLE_KEYS` — `public const WRITABLE_KEYS = ['enabled', 'url_log_chan', 'ai_vision_disabled', 'ai_vision_model', 'ai_vision_prompt', 'ai_vision_reasoning_effort', 'ai_vision_reasoning'];` (single source; ConfigService + adapter + CLI all reference it).

RULING (ledger): issue #144 item 3 (settingsUserSystem() dedup) is DEFERRED — the issue itself conditions it on "if user.php ever moves it to an autoloadable location"; that refactor isn't in scope. Item 4's adapter throw applies to `LinktitlesSettingStorage::resolve()`: unknown `$networkId` (no Network row) currently degrades to the global row — throw `\RuntimeException("unknown network id {$networkId}")` instead. Note: resolve() is shared by get/set/clear; keep the valid paths (null networkId = global tier) untouched — read resolve() first and only change the not-found branch.

- [ ] **Step 1: Write failing tests**

In LinktitlesAdapterTest: (a) `get()` with a networkId that has no Network row → expects `\RuntimeException`; (b) `assertSame(linktitles_setting::WRITABLE_KEYS, ...)` reachable by asserting a valid key from the const passes and an unknown key throws UnknownSettingException (keeps the const honest indirectly); (c) after the const lands, `grep -rn "LINKTITLES_KEYS\|private const KEYS" scripts/ library/` must show no surviving duplicate lists — verify manually, note in report.

- [ ] **Step 2: Verify RED**

- [ ] **Step 3: Implement** — const on the entity; three sites reference it (`in_array($key, linktitles_setting::WRITABLE_KEYS, true)` etc.); resolve() not-found branch throws; settings list/show formatting unified to `(source: X)`; weather line removal + docblock note ("units follow the API's si/uk/us setting; the location override does not change units").

- [ ] **Step 4: Verify GREEN** — full suite; LinktitlesAdapterTest green; phpstan zero-new on touched files

- [ ] **Step 5: Commit + close #144** (comment lists item 3 deferral)

`fix(settings): one writable-keys const, adapter fails loud, cosmetics (#144)`

---

### Task 6: #142 item 2 — /quit drains inbound before exit

**Files:**
- Modify: `testenv/client.php:332-335` (quit case), plus the stdin coroutine `:355-364` and the main read loop (read them; the fix spans quit + EOF paths)
- Test: `tests/TestEnv/CliTest.php` (extend if it already pipes stdin; else a scripted run asserted from PHP)

**Interfaces:**
- Consumes: Amp event loop already driving `$socket` reads.
- Produces: none.

Requirement (issue #142 item 2): "`testenv/client.php` /quit drain race: fully-buffered stdin + EOF can exit before inbound lines process — REQUIRED FIX before v2 scripted macros (deferred-exit drain); harmless for interactive humans."

Current quit case:

```php
                case 'quit':
                    $send('QUIT :testenv client closing');
                    $socket->close();
                    exit(0);
```

New behavior: send QUIT; set a `$quitting` flag so later stdin lines are ignored; DO NOT close the socket; let the read loop keep processing inbound until the server closes (read returns null/EOF) or a **3-second safety timeout** fires; then `exit(0)`. EOF on stdin (client.php:362-363) routes through the same drain. Print a line like `*** draining inbound before exit...` when the drain starts so humans see why exit is delayed.

- [ ] **Step 1: Write the failing test** — spawn the testenv server + client (follow tests/TestEnv/CliTest.php's existing pattern; if none pipes stdin, add `proc_open` driving `php testenv/client.php ...` with `fwrite` of a couple of commands then `/quit\n` immediately followed by more inbound-generating traffic — assert the process's captured stdout contains the trailing bot reply that currently gets dropped; use a `\Amp\async` free, plain proc_open + stream_select approach with an overall timeout so the test can never hang). Watch it FAIL (trailing reply missing).

- [ ] **Step 2: Verify RED**

- [ ] **Step 3: Implement the drain** (read client.php's loop structure first; keep the change minimal and commented — this file is the acceptance tooling)

- [ ] **Step 4: Verify GREEN** — new test green; full suite; ALSO manually confirm normal quit path still exits promptly when server already closed (covered by the 3s bound)

- [ ] **Step 5: Commit** (issue #142 stays OPEN — Task 7 completes it)

`fix(testenv): /quit drains inbound before exit (#142)`

---

### Task 7: #142 items 1,3,4,5,6 — testenv polish batch

**Files:**
- Modify: `testenv/secrets.example.yaml` (commented `sasl_account:` lines)
- Modify: `library/testenv/Seeder.php:209-221` (`optStringList` fail-fast)
- Modify: `library/testenv/EnvStore.php:32` (checked mkdir, 0700; dbPath touches+chmods 0600 on creation)
- Test: `tests/TestEnv/SeederTest.php` (malformed fixture throws; hostmasks branch covered)
- Modify: `testenv/client.php` ([PM] detection accepts `+`/`!` channel prefixes; `/join` comma-lists + case-dup bookkeeping — read the join/PM code first)

Requirement quotes (issue #142): item 1 "add commented sasl_account lines to the example (works via the generic driver overlay merge)"; item 3 "optStringList silently drops malformed scalars (typo'd auth_engines/flags/channels entry → silently empty); fail-fast validation would surface fixture typos"; item 4 "mkdir() unchecked (opaque later failures) and 0777 umask-dependent perms on run DBs that may hold sasl_pass values"; item 5 "Automated test for the UserHostmask seed branch (fixture_test gains a hostmasks entry + one assertion)"; item 6 "[PM] detection misses +/! channel types; /join comma-list + case-dup bookkeeping".

Details:

- **optStringList** (Seeder.php:209-221): non-array non-null → `throw new \RuntimeException("Seeder: '{$key}' must be a list of strings");`; array containing any non-string → same throw naming the key. Null stays null; all-string lists unchanged. Check every current caller's expectations (auth_engines :109, channels :137, and any flags/hostmasks lists) — the existing testenv/profiles/*.yaml must all still load (run `php testenv.php <profile> --help`-style smoke per CliTest pattern or the RealProfilesTest).
- **EnvStore**: `mkdir($dir, 0700, true)` wrapped: `if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new \RuntimeException("cannot create {$dir}");`; in `dbPath()`, after computing the path, `if (!file_exists($path)) { touch($path); chmod($path, 0600); }`.
- **secrets.example.yaml**: add a `libera:` section with commented `# sasl_account: your-account` / `# sasl_pass: your-pass` under `driver:` noting the generic overlay merge.
- **client.php cosmetics**: where PM/channel detection checks a `#` prefix, accept `#+!&` (IRC chantypes); `/join a,b` splits on commas; the joined-channel bookkeeping dedupes case-insensitively.

- [ ] **Step 1: Write failing tests** — SeederTest: fixture with `auth_engines: account-tag` (scalar, not list) → RuntimeException naming 'auth_engines'; hostmasks seed entry → UserHostmask row asserted. Verify RED.
- [ ] **Step 2: Implement all items** (fail-fast, EnvStore perms/checks, example yaml, client cosmetics).
- [ ] **Step 3: Verify GREEN** — new tests, full suite, `php /tmp/opencode/minmode_e2e.php` still green (EnvStore perms touched), profiles smoke.
- [ ] **Step 4: Commit + close #142** (comment notes item 2 fixed in prior task)

`fix(testenv): fail-fast seeder, guarded envstore perms, sasl example, client cosmetics (#142)`

---

## Execution notes

- Task order 1→7 as listed (issue value order; Task 3's ripple lands before Task 4 which touches adjacent code).
- After Task 7: final whole-branch review (most capable model) over the full range, then `finishing-a-development-branch` (master pushes happened per-task with owner consent).
