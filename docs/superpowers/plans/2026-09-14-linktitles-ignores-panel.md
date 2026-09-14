# Linktitles Ignores Panel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Manage `linktitles_ignores` (URL regexes) and `linktitles_hostignores` (hostmask globs) from the web control panel's Linktitles page, with a tester, and fix bot-scoped enforcement.

**Architecture:** A shared static `IgnoreMatcher` owns all matching (bot enforcement + panel tester use the same code, so they can't drift). All mutations go through new `ConfigService` methods that validate, flush, and fire `ConfigChange` pushes; `BotManager::apply()` gets no-op cases because the bot already queries these tables per URL. Web handlers follow the existing ignores-section flow (CSRF → validate → ConfigService → redirect), plus two HTMX fragment endpoints for the tester.

**Tech Stack:** PHP 8.1, Doctrine ORM (entities already exist, no migrations), Twig + HTMX + Bootstrap panel, PHPUnit 10 (`tests/Config/` with `ConfigTestCase`), PHPStan level 9.

**Spec:** `docs/superpowers/specs/2026-09-14-linktitles-ignores-panel-design.md`

**PHPStan baseline (recorded 2026-09-14):** `vendor/bin/phpstan analyse scripts/linktitles/ library/config/ConfigService.php library/BotManager.php web/ --no-progress --memory-limit=1G` reports **30 pre-existing errors** (all in `scripts/linktitles/linktitles.php` and `scripts/linktitles/cli_cmds/`). "No new errors" in the steps below means: still exactly those, and none referencing `IgnoreMatcher`, `addLinktitlesIgnore`, `addLinktitlesHostignore`, `web_lt_match_rows`, `web_lt_ignore_scope_from_post`, or `web_lt_test_scope_from_post`.

---

### Task 1: `IgnoreMatcher` — shared scope/matching logic

**Files:**
- Create: `scripts/linktitles/IgnoreMatcher.php`
- Test: `tests/Config/LinktitlesIgnoreMatcherTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Config/LinktitlesIgnoreMatcherTest.php`:

```php
<?php
namespace Tests\Config;

use lolbot\config\ConfigService;
use scripts\linktitles\entities\hostignore;
use scripts\linktitles\entities\ignore;
use scripts\linktitles\entities\ignore_type;
use scripts\linktitles\IgnoreMatcher;

require_once __DIR__ . '/../../vendor/autoload.php';

class LinktitlesIgnoreMatcherTest extends ConfigTestCase
{
    private ConfigService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ConfigService($this->em);
    }

    public function test_global_url_row_matches_any_scope(): void
    {
        $ig = new ignore(ignore_type::global);
        $ig->regex = '@example\.com@i';
        $this->em->persist($ig);
        $this->em->flush();

        $net = $this->svc->createNetwork('N');
        $bot = $this->svc->createBot($net, 'b1');

        $this->assertSame([$ig], IgnoreMatcher::findUrlMatches($this->em, null, null, 'https://example.com/x'));
        $this->assertSame([$ig], IgnoreMatcher::findUrlMatches($this->em, $net, $bot, 'https://example.com/x'));
        $this->assertSame([], IgnoreMatcher::findUrlMatches($this->em, $net, $bot, 'https://other.com/x'));
    }

    public function test_network_url_row_matches_only_own_network(): void
    {
        $netA = $this->svc->createNetwork('A');
        $netB = $this->svc->createNetwork('B');
        $ig = new ignore(ignore_type::network);
        $ig->regex = '@example\.com@i';
        $ig->network = $netA;
        $this->em->persist($ig);
        $this->em->flush();

        $this->assertSame([$ig], IgnoreMatcher::findUrlMatches($this->em, $netA, null, 'https://example.com/x'));
        $this->assertSame([], IgnoreMatcher::findUrlMatches($this->em, $netB, null, 'https://example.com/x'));
        $this->assertSame([], IgnoreMatcher::findUrlMatches($this->em, null, null, 'https://example.com/x'));
    }

    public function test_bot_url_row_matches_only_own_bot(): void
    {
        $net = $this->svc->createNetwork('N');
        $b1 = $this->svc->createBot($net, 'b1');
        $b2 = $this->svc->createBot($net, 'b2');
        $ig = new ignore(ignore_type::bot);
        $ig->regex = '@example\.com@i';
        $ig->bot = $b1;
        $this->em->persist($ig);
        $this->em->flush();

        $this->assertSame([$ig], IgnoreMatcher::findUrlMatches($this->em, $net, $b1, 'https://example.com/x'));
        $this->assertSame([], IgnoreMatcher::findUrlMatches($this->em, $net, $b2, 'https://example.com/x'));
    }

    public function test_host_rows_glob_and_scope(): void
    {
        $netA = $this->svc->createNetwork('A');
        $netB = $this->svc->createNetwork('B');
        $hi = new hostignore(ignore_type::network);
        $hi->hostmask = '*!*@*.bad.example';
        $hi->network = $netA;
        $this->em->persist($hi);
        $this->em->flush();

        $this->assertSame([$hi], IgnoreMatcher::findHostMatches($this->em, $netA, null, 'nick!user@host.bad.example'));
        $this->assertSame([], IgnoreMatcher::findHostMatches($this->em, $netB, null, 'nick!user@host.bad.example'));
        $this->assertSame([], IgnoreMatcher::findHostMatches($this->em, $netA, null, 'nick!user@host.good.example'));
    }

    public function test_is_ignored_via_url_or_host(): void
    {
        $net = $this->svc->createNetwork('N');
        $ig = new ignore(ignore_type::global);
        $ig->regex = '@spam\.example@i';
        $this->em->persist($ig);
        $hi = new hostignore(ignore_type::global);
        $hi->hostmask = '*!*@*.spammer';
        $this->em->persist($hi);
        $this->em->flush();

        $this->assertTrue(IgnoreMatcher::isIgnored($this->em, $net, null, 'nick!user@ok.host', 'https://spam.example/x'));
        $this->assertTrue(IgnoreMatcher::isIgnored($this->em, $net, null, 'nick!user@irc.spammer', 'https://fine.example/x'));
        $this->assertFalse(IgnoreMatcher::isIgnored($this->em, $net, null, 'nick!user@ok.host', 'https://fine.example/x'));
    }

    public function test_invalid_stored_regex_is_no_match_and_flagged(): void
    {
        $ig = new ignore(ignore_type::global);
        $ig->regex = '@unclosed[(@i';
        $this->em->persist($ig);
        $this->em->flush();

        $this->assertSame([], IgnoreMatcher::findUrlMatches($this->em, null, null, 'https://example.com/x'));
        $this->assertFalse(IgnoreMatcher::patternIsValid($ig->regex));
        $this->assertTrue(IgnoreMatcher::patternIsValid('@ok@i'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Config/LinktitlesIgnoreMatcherTest.php`
Expected: FAIL — `Error: Class "scripts\linktitles\IgnoreMatcher" not found`

- [ ] **Step 3: Write the implementation**

Create `scripts/linktitles/IgnoreMatcher.php`:

```php
<?php
namespace scripts\linktitles;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityManager;
use lolbot\entities\Bot;
use lolbot\entities\Network;
use scripts\linktitles\entities\hostignore;
use scripts\linktitles\entities\ignore;
use scripts\linktitles\entities\ignore_type;

/**
 * Shared matching for linktitles URL-regex and hostmask ignores. Both the
 * bot's enforcement (linktitles::urlIsIgnored) and the panel's tester call
 * these so the two can never drift apart.
 *
 * Scope: a row applies when it is global, scoped to the given network, or
 * scoped to the given bot.
 * //TODO channel scoping is unimplemented (entity has no Channel FK yet)
 */
class IgnoreMatcher
{
    /**
     * All URL-regex rows whose pattern matches $url at this scope.
     * @return list<ignore>
     */
    public static function findUrlMatches(EntityManager $em, ?Network $network, ?Bot $bot, string $url): array
    {
        $out = [];
        /** @var ignore[] $ignores */
        $ignores = $em->getRepository(ignore::class)->matching(self::scopeCriteria($network, $bot));
        foreach ($ignores as $ignore) {
            if (@preg_match($ignore->regex, $url) === 1) {
                $out[] = $ignore;
            }
        }
        return $out;
    }

    /**
     * All hostmask rows whose glob matches $fullhost at this scope.
     * @return list<hostignore>
     */
    public static function findHostMatches(EntityManager $em, ?Network $network, ?Bot $bot, string $fullhost): array
    {
        $out = [];
        /** @var hostignore[] $hostignores */
        $hostignores = $em->getRepository(hostignore::class)->matching(self::scopeCriteria($network, $bot));
        foreach ($hostignores as $hostignore) {
            $hostmask_re = \knivey\tools\globToRegex($hostignore->hostmask) . 'i';
            if (@preg_match($hostmask_re, $fullhost) === 1) {
                $out[] = $hostignore;
            }
        }
        return $out;
    }

    public static function isIgnored(EntityManager $em, ?Network $network, ?Bot $bot, string $fullhost, string $url): bool
    {
        return self::findUrlMatches($em, $network, $bot, $url) !== []
            || self::findHostMatches($em, $network, $bot, $fullhost) !== [];
    }

    /** True when the stored pattern compiles (legacy bad rows count as no match). */
    public static function patternIsValid(string $regex): bool
    {
        return @preg_match($regex, '') !== false;
    }

    /**
     * Rows visible at this scope: global, this network's, this bot's.
     * Explicitly grouped per type so e.g. another network's network-scoped
     * rows can never leak in through OR precedence.
     */
    private static function scopeCriteria(?Network $network, ?Bot $bot): Criteria
    {
        $expr = Criteria::expr();
        $ors = [$expr->eq('type', ignore_type::global)];
        if ($network !== null) {
            $ors[] = $expr->andX(
                $expr->eq('type', ignore_type::network),
                $expr->eq('network', $network),
            );
        }
        if ($bot !== null) {
            $ors[] = $expr->andX(
                $expr->eq('type', ignore_type::bot),
                $expr->eq('bot', $bot),
            );
        }
        return Criteria::create()->where(Criteria::expr()->orX(...$ors));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Config/LinktitlesIgnoreMatcherTest.php`
Expected: PASS (6 tests)

- [ ] **Step 5: Commit**

```bash
git add scripts/linktitles/IgnoreMatcher.php tests/Config/LinktitlesIgnoreMatcherTest.php
git commit -m "feat(linktitles): shared IgnoreMatcher for url/host ignores"
```

---

### Task 2: `ConfigService` mutations for both ignore kinds

**Files:**
- Modify: `library/config/ConfigService.php` (new section after the Ignores section, before "Service config"; plus imports)
- Test: `tests/Config/ConfigServiceLinktitlesIgnoreTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Config/ConfigServiceLinktitlesIgnoreTest.php`:

```php
<?php
namespace Tests\Config;

use lolbot\config\ChangeNotifier;
use lolbot\config\ConfigChange;
use lolbot\config\ConfigService;
use lolbot\config\InvalidSettingException;
use lolbot\config\NotFoundException;
use scripts\linktitles\entities\ignore_type;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Captures ConfigChange notifications so tests can assert the seam fires.
 * (Distinct class name from CapturingNotifier to avoid cross-file redeclare.)
 */
class LtIgnoreCapturingNotifier implements ChangeNotifier
{
    /** @var list<ConfigChange> */
    public array $changes = [];
    public function notify(ConfigChange $change): void
    {
        $this->changes[] = $change;
    }
    public function reset(): void
    {
        $this->changes = [];
    }
}

class ConfigServiceLinktitlesIgnoreTest extends ConfigTestCase
{
    private ConfigService $svc;
    private LtIgnoreCapturingNotifier $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifier = new LtIgnoreCapturingNotifier();
        $this->svc = new ConfigService($this->em, $this->notifier);
    }

    public function test_add_global_wraps_delimiters_and_notifies(): void
    {
        $ig = $this->svc->addLinktitlesIgnore('example\.com/path', ignore_type::global);
        $this->assertSame('@example\.com/path@i', $ig->regex);
        $this->assertSame(ignore_type::global, $ig->type);
        $this->assertNull($ig->network);
        $this->assertNull($ig->bot);
        $this->assertCount(1, $this->notifier->changes);
        $this->assertSame('linktitles_ignore', $this->notifier->changes[0]->entityType);
        $this->assertSame('create', $this->notifier->changes[0]->action);
        $this->assertSame($ig->id, $this->notifier->changes[0]->id);
    }

    public function test_add_picks_delimiter_not_in_pattern(): void
    {
        $ig = $this->svc->addLinktitlesIgnore('https?://(www\.)?@example@\.com', ignore_type::global);
        $this->assertSame('#https?://(www\.)?@example@\.com#i', $ig->regex);
    }

    public function test_add_rejects_pattern_with_all_delimiters(): void
    {
        $this->expectException(InvalidSettingException::class);
        $this->svc->addLinktitlesIgnore('@#~%', ignore_type::global);
    }

    public function test_add_rejects_invalid_regex(): void
    {
        $this->expectException(InvalidSettingException::class);
        $this->svc->addLinktitlesIgnore('unclosed[', ignore_type::global);
    }

    public function test_add_rejects_empty_pattern(): void
    {
        $this->expectException(InvalidSettingException::class);
        $this->svc->addLinktitlesIgnore('  ', ignore_type::global);
    }

    public function test_add_network_scoped(): void
    {
        $net = $this->svc->createNetwork('N');
        $ig = $this->svc->addLinktitlesIgnore('x', ignore_type::network, $net);
        $this->assertSame($net, $ig->network);
        $this->assertNull($ig->bot);
    }

    public function test_add_network_scoped_requires_network(): void
    {
        $this->expectException(NotFoundException::class);
        $this->svc->addLinktitlesIgnore('x', ignore_type::network, null);
    }

    public function test_add_network_scoped_unknown_network(): void
    {
        $net = $this->svc->createNetwork('N');
        $this->em->detach($net);
        $this->expectException(NotFoundException::class);
        $this->svc->addLinktitlesIgnore('x', ignore_type::network, $net);
    }

    public function test_add_bot_scoped(): void
    {
        $net = $this->svc->createNetwork('N');
        $bot = $this->svc->createBot($net, 'b1');
        $ig = $this->svc->addLinktitlesIgnore('x', ignore_type::bot, null, $bot);
        $this->assertSame($bot, $ig->bot);
        $this->assertNull($ig->network);
    }

    public function test_add_bot_scoped_requires_bot(): void
    {
        $this->expectException(NotFoundException::class);
        $this->svc->addLinktitlesIgnore('x', ignore_type::bot, null, null);
    }

    public function test_add_global_rejects_scope_targets(): void
    {
        $net = $this->svc->createNetwork('N');
        $this->expectException(InvalidSettingException::class);
        $this->svc->addLinktitlesIgnore('x', ignore_type::global, $net);
    }

    public function test_add_channel_rejected(): void
    {
        $this->expectException(InvalidSettingException::class);
        $this->svc->addLinktitlesIgnore('x', ignore_type::channel);
    }

    public function test_url_list_get_delete_roundtrip(): void
    {
        $ig = $this->svc->addLinktitlesIgnore('x', ignore_type::global);
        $this->assertSame([$ig], $this->svc->listLinktitlesIgnores());
        $this->assertSame($ig, $this->svc->getLinktitlesIgnore($ig->id));

        $id = $ig->id;
        $this->notifier->reset();
        $this->svc->deleteLinktitlesIgnore($ig);

        $this->assertNull($this->svc->getLinktitlesIgnore($id));
        $this->assertSame([], $this->svc->listLinktitlesIgnores());
        $this->assertCount(1, $this->notifier->changes);
        $this->assertSame('delete', $this->notifier->changes[0]->action);
        $this->assertSame($id, $this->notifier->changes[0]->id);
    }

    public function test_hostignore_add_list_delete(): void
    {
        $net = $this->svc->createNetwork('N');
        $hi = $this->svc->addLinktitlesHostignore('*!*@*.bad', ignore_type::network, $net);
        $this->assertSame('*!*@*.bad', $hi->hostmask);
        $this->assertSame($net, $hi->network);
        $this->assertSame([$hi], $this->svc->listLinktitlesHostignores());
        $this->assertSame('linktitles_hostignore', $this->notifier->changes[0]->entityType);
        $this->assertSame('create', $this->notifier->changes[0]->action);

        $id = $hi->id;
        $this->notifier->reset();
        $this->svc->deleteLinktitlesHostignore($hi);

        $this->assertNull($this->svc->getLinktitlesHostignore($id));
        $this->assertSame([], $this->svc->listLinktitlesHostignores());
        $this->assertSame('delete', $this->notifier->changes[0]->action);
    }

    public function test_hostignore_rejects_empty(): void
    {
        $this->expectException(InvalidSettingException::class);
        $this->svc->addLinktitlesHostignore('  ', ignore_type::global);
    }

    public function test_hostignore_channel_rejected(): void
    {
        $this->expectException(InvalidSettingException::class);
        $this->svc->addLinktitlesHostignore('*!*@*', ignore_type::channel);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Config/ConfigServiceLinktitlesIgnoreTest.php`
Expected: FAIL — `Error: Call to undefined method lolbot\config\ConfigService::addLinktitlesIgnore()`

- [ ] **Step 3: Add the imports**

In `library/config/ConfigService.php`, insert alphabetically before the existing `use scripts\linktitles\entities\linktitles_setting;` (around line 12):

```php
use scripts\linktitles\entities\hostignore;
use scripts\linktitles\entities\ignore;
use scripts\linktitles\entities\ignore_type;
```

- [ ] **Step 4: Add the methods**

In `library/config/ConfigService.php`, insert a new section between the Ignores section (ends with `deleteIgnore`, ~line 240) and the `// ---------------- Service config (global singletons) ----------------` comment:

```php
    // ---------------- Linktitles ignores ----------------

    public function addLinktitlesIgnore(string $pattern, ignore_type $type, ?Network $network = null, ?Bot $bot = null): ignore
    {
        $regex = self::wrapLinktitlesRegex($pattern);
        $this->assertLinktitlesIgnoreScope($type, $network, $bot);
        $ignore = new ignore($type);
        $ignore->regex = $regex;
        if ($type === ignore_type::network) {
            $ignore->network = $network;
        }
        if ($type === ignore_type::bot) {
            $ignore->bot = $bot;
        }
        $this->em->persist($ignore);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('linktitles_ignore', $ignore->id, 'create'));
        return $ignore;
    }

    public function getLinktitlesIgnore(int $id): ?ignore
    {
        return $this->em->getRepository(ignore::class)->find($id);
    }

    /** @return list<ignore> */
    public function listLinktitlesIgnores(): array
    {
        return $this->em->getRepository(ignore::class)->findAll();
    }

    public function deleteLinktitlesIgnore(ignore $ignore): void
    {
        $id = $ignore->id;
        $this->em->remove($ignore);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('linktitles_ignore', $id, 'delete'));
    }

    public function addLinktitlesHostignore(string $hostmask, ignore_type $type, ?Network $network = null, ?Bot $bot = null): hostignore
    {
        $hostmask = trim($hostmask);
        if ($hostmask === '') {
            throw new InvalidSettingException("Hostmask required");
        }
        $this->assertLinktitlesIgnoreScope($type, $network, $bot);
        $hostignore = new hostignore($type);
        $hostignore->hostmask = $hostmask;
        if ($type === ignore_type::network) {
            $hostignore->network = $network;
        }
        if ($type === ignore_type::bot) {
            $hostignore->bot = $bot;
        }
        $this->em->persist($hostignore);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('linktitles_hostignore', $hostignore->id, 'create'));
        return $hostignore;
    }

    public function getLinktitlesHostignore(int $id): ?hostignore
    {
        return $this->em->getRepository(hostignore::class)->find($id);
    }

    /** @return list<hostignore> */
    public function listLinktitlesHostignores(): array
    {
        return $this->em->getRepository(hostignore::class)->findAll();
    }

    public function deleteLinktitlesHostignore(hostignore $hostignore): void
    {
        $id = $hostignore->id;
        $this->em->remove($hostignore);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('linktitles_hostignore', $id, 'delete'));
    }

    /**
     * Wrap a raw pattern in delimiters (@ # ~ % — the first one the pattern
     * does not contain) with the i flag, mirroring how CLI-created rows are
     * stored. Rejects empty patterns, patterns containing every candidate
     * delimiter, and patterns that do not compile.
     */
    private static function wrapLinktitlesRegex(string $pattern): string
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            throw new InvalidSettingException("Pattern required");
        }
        $delim = null;
        foreach (['@', '#', '~', '%'] as $d) {
            if (!str_contains($pattern, $d)) {
                $delim = $d;
                break;
            }
        }
        if ($delim === null) {
            throw new InvalidSettingException("Pattern contains all of @ # ~ % — remove one so a delimiter can be chosen");
        }
        $re = $delim . $pattern . $delim . 'i';
        if (@preg_match($re, '') === false) {
            throw new InvalidSettingException("Invalid regex: $pattern");
        }
        return $re;
    }

    /** Validates that the scope targets match the requested type. */
    private function assertLinktitlesIgnoreScope(ignore_type $type, ?Network $network, ?Bot $bot): void
    {
        switch ($type) {
            case ignore_type::global:
                if ($network !== null || $bot !== null) {
                    throw new InvalidSettingException("Global ignore must not have a network or bot");
                }
                return;
            case ignore_type::network:
                if ($network === null || !isset($network->id) || !$this->em->contains($network)) {
                    throw new NotFoundException("Network not found for network-scoped ignore");
                }
                return;
            case ignore_type::bot:
                if ($bot === null || !isset($bot->id) || !$this->em->contains($bot)) {
                    throw new NotFoundException("Bot not found for bot-scoped ignore");
                }
                return;
            case ignore_type::channel:
                throw new InvalidSettingException("Channel-scoped linktitles ignores are not supported yet");
        }
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Config/ConfigServiceLinktitlesIgnoreTest.php`
Expected: PASS (16 tests)

- [ ] **Step 6: Commit**

```bash
git add library/config/ConfigService.php tests/Config/ConfigServiceLinktitlesIgnoreTest.php
git commit -m "feat(config): ConfigService mutations for linktitles ignores"
```

---

### Task 3: Bot enforcement delegate + live-sync no-ops

**Files:**
- Modify: `scripts/linktitles/linktitles.php:13-21` (imports), `scripts/linktitles/linktitles.php:445-471` (`urlIsIgnored`)
- Modify: `library/BotManager.php:511-512` (apply switch)

- [ ] **Step 1: Replace `urlIsIgnored()` with the delegate**

In `scripts/linktitles/linktitles.php`, replace the whole function (lines 445-471, the block starting with the `//TODO can add cache for this` comment) with:

```php
//TODO can add cache for this
    function urlIsIgnored(string $chan, string $fullhost, string $url): bool
    {
        global $entityManager;
        return IgnoreMatcher::isIgnored($entityManager, $this->network, $this->bot, $fullhost, $url);
    }
```

Comment bookkeeping (per repo AGENTS.md): the `//TODO can add cache for this` comment is preserved. The two in-body comments that were inside the old body (`//todo bot would go here, and channel` and `//$criteria->orWhere(Criteria::expr()->eq());`) described the old inline implementation: the bot half is now implemented by `IgnoreMatcher::scopeCriteria()`, and the channel TODO was carried onto `IgnoreMatcher`'s class docblock in Task 1 — so removing them here is safe and was approved in the spec.

`IgnoreMatcher` needs no `use` import: `linktitles.php` is namespace `scripts\linktitles`, same as `IgnoreMatcher`.

- [ ] **Step 2: Remove the now-unused imports**

The old body was the only user of `Criteria`, `hostignore`, `ignore_type`, and `ignore` in this file (verified: grep finds no other usages). In `scripts/linktitles/linktitles.php` delete these four lines:

```php
use Doctrine\Common\Collections\Criteria;
```
```php
use scripts\linktitles\entities\hostignore;
use scripts\linktitles\entities\ignore_type;
use scripts\linktitles\entities\ignore;
```

- [ ] **Step 3: Add the no-op apply cases**

In `library/BotManager.php`, directly below the existing `'ignore'` case (line 511-512):

```php
                case 'ignore':
                    return; // ignore cache is 5s TTL; auto-applies.
                case 'linktitles_ignore':
                case 'linktitles_hostignore':
                    return; // checked per-URL against the DB; adds/deletes apply live.
```

(Keep the existing `case 'ignore'` lines exactly as they are; the two new cases are inserted after its `return` line.)

- [ ] **Step 4: Verify**

Run: `vendor/bin/phpunit tests/Config/LinktitlesIgnoreMatcherTest.php tests/Config/ConfigServiceLinktitlesIgnoreTest.php`
Expected: PASS (22 tests)

Run: `vendor/bin/phpstan analyse scripts/linktitles/linktitles.php scripts/linktitles/IgnoreMatcher.php library/BotManager.php --no-progress --memory-limit=1G`
Expected: no NEW errors vs the recorded baseline (in particular: none referencing `IgnoreMatcher`, and the total for `scripts/linktitles/linktitles.php` must not grow).

- [ ] **Step 5: Commit**

```bash
git add scripts/linktitles/linktitles.php library/BotManager.php
git commit -m "feat(linktitles): urlIsIgnored delegates to IgnoreMatcher (bot scope enforced)"
```

---

### Task 4: Web section helpers (TDD)

**Files:**
- Modify: `web/sections/linktitles.php` (imports + four new helpers at the end of the file)
- Test: `tests/Config/WebLinktitlesIgnoreHelpersTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Config/WebLinktitlesIgnoreHelpersTest.php`:

```php
<?php
namespace Tests\Config;

use lolbot\config\ConfigService;
use scripts\linktitles\entities\ignore;
use scripts\linktitles\entities\ignore_type;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Pure helpers for the linktitles ignores panel (function-surface tests:
 * the section file is require_once'd directly, WebAuthTest pattern).
 */
class WebLinktitlesIgnoreHelpersTest extends ConfigTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../web/sections/linktitles.php';
    }

    protected function tearDown(): void
    {
        unset($_POST['type'], $_POST['network'], $_POST['bot']);
        parent::tearDown();
    }

    public function test_match_rows_flattens_url_and_host_rows(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $ig = $svc->addLinktitlesIgnore('example\.com', ignore_type::global);
        $hi = $svc->addLinktitlesHostignore('*!*@*.bad', ignore_type::network, $net);

        $rows = web_lt_match_rows([$ig, $hi]);
        $this->assertSame([
            ['id' => $ig->id, 'pattern' => $ig->regex, 'scope' => 'global', 'invalid' => false],
            ['id' => $hi->id, 'pattern' => '*!*@*.bad', 'scope' => 'network: N', 'invalid' => false],
        ], $rows);
    }

    public function test_match_rows_flags_invalid_pattern(): void
    {
        $ig = new ignore(ignore_type::global);
        $ig->regex = '@unclosed[(@i';
        $this->em->persist($ig);
        $this->em->flush();

        $rows = web_lt_match_rows([$ig]);
        $this->assertTrue($rows[0]['invalid']);
    }

    public function test_scope_from_post_network(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $_POST['type'] = 'network';
        $_POST['network'] = (string)$net->id;
        [$type, $resolvedNet, $bot] = web_lt_ignore_scope_from_post(['svc' => $svc]);
        $this->assertSame(ignore_type::network, $type);
        $this->assertSame($net, $resolvedNet);
        $this->assertNull($bot);
    }

    public function test_scope_from_post_bot(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b1');
        $_POST['type'] = 'bot';
        $_POST['bot'] = (string)$bot->id;
        [$type, $resolvedNet, $resolvedBot] = web_lt_ignore_scope_from_post(['svc' => $svc]);
        $this->assertSame(ignore_type::bot, $type);
        $this->assertNull($resolvedNet);
        $this->assertSame($bot, $resolvedBot);
    }

    public function test_scope_from_post_missing_network_throws(): void
    {
        $_POST['type'] = 'network';
        $_POST['network'] = '999';
        $this->expectException(\InvalidArgumentException::class);
        web_lt_ignore_scope_from_post(['svc' => new ConfigService($this->em)]);
    }

    public function test_scope_from_post_bad_type_throws(): void
    {
        $_POST['type'] = 'bogus';
        $this->expectException(\ValueError::class);
        web_lt_ignore_scope_from_post(['svc' => new ConfigService($this->em)]);
    }

    public function test_test_scope_optional_and_bot_implies_network(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b1');

        [$n, $b] = web_lt_test_scope_from_post(['svc' => $svc]);
        $this->assertNull($n);
        $this->assertNull($b);

        $_POST['bot'] = (string)$bot->id;
        [$n, $b] = web_lt_test_scope_from_post(['svc' => $svc]);
        $this->assertSame($bot, $b);
        $this->assertSame($net, $n);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Config/WebLinktitlesIgnoreHelpersTest.php`
Expected: FAIL — `Error: Call to undefined function Tests\Config\web_lt_match_rows()`

- [ ] **Step 3: Add the imports and helpers**

In `web/sections/linktitles.php`, extend the import block at the top (after `use scripts\linktitles\entities\linktitles_setting;`):

```php
use scripts\linktitles\entities\hostignore;
use scripts\linktitles\entities\ignore;
use scripts\linktitles\entities\ignore_type;
use scripts\linktitles\IgnoreMatcher;
```

Append at the end of the file:

```php
/** Scope label for a linktitles ignore row: "global", "network: N", "bot: B". */
function web_lt_scope_label(ignore|hostignore $ig): string
{
    if ($ig->type === ignore_type::network) {
        return 'network: ' . ($ig->network?->name ?? '?');
    }
    if ($ig->type === ignore_type::bot) {
        return 'bot: ' . ($ig->bot?->name ?? '?');
    }
    return 'global';
}

/**
 * Flatten matcher results for the tester fragment: the pattern shown per
 * kind, a scope label, and an invalid-pattern flag (URL kind only, legacy
 * rows that no longer compile).
 *
 * @param list<ignore|hostignore> $matches
 * @return list<array{id:int,pattern:string,scope:string,invalid:bool}>
 */
function web_lt_match_rows(array $matches): array
{
    $rows = [];
    foreach ($matches as $ig) {
        $rows[] = [
            'id' => $ig->id,
            'pattern' => $ig instanceof ignore ? $ig->regex : $ig->hostmask,
            'scope' => web_lt_scope_label($ig),
            'invalid' => $ig instanceof ignore && !IgnoreMatcher::patternIsValid($ig->regex),
        ];
    }
    return $rows;
}

/**
 * Resolve the add-form scope from POST: 'type' plus the matching
 * network/bot select. Throws when the type is unknown or its target is
 * missing (surfaced as the page error alert by the callers).
 *
 * @param array{svc: \lolbot\config\ConfigService} $app
 * @return array{0: ignore_type, 1: ?\lolbot\entities\Network, 2: ?\lolbot\entities\Bot}
 */
function web_lt_ignore_scope_from_post(array $app): array
{
    $raw = is_string($_POST['type'] ?? null) ? $_POST['type'] : '';
    $type = ignore_type::fromString($raw);
    $net = null;
    $bot = null;
    if ($type === ignore_type::network) {
        $nid = is_numeric($_POST['network'] ?? null) ? (int)$_POST['network'] : 0;
        $net = $app['svc']->getNetwork($nid);
        if ($net === null) {
            throw new \InvalidArgumentException('Select a network for network-scoped ignores');
        }
    }
    if ($type === ignore_type::bot) {
        $bid = is_numeric($_POST['bot'] ?? null) ? (int)$_POST['bot'] : 0;
        $bot = $app['svc']->getBot($bid);
        if ($bot === null) {
            throw new \InvalidArgumentException('Select a bot for bot-scoped ignores');
        }
    }
    return [$type, $net, $bot];
}

/**
 * Optional tester scope: empty/0 selects mean null. A selected bot implies
 * its network, so the tester sees exactly what a bot on that network would.
 *
 * @param array{svc: \lolbot\config\ConfigService} $app
 * @return array{0: ?\lolbot\entities\Network, 1: ?\lolbot\entities\Bot}
 */
function web_lt_test_scope_from_post(array $app): array
{
    $net = null;
    $bot = null;
    if (is_numeric($_POST['network'] ?? null) && (int)$_POST['network'] > 0) {
        $net = $app['svc']->getNetwork((int)$_POST['network']);
    }
    if (is_numeric($_POST['bot'] ?? null) && (int)$_POST['bot'] > 0) {
        $bot = $app['svc']->getBot((int)$_POST['bot']);
    }
    if ($net === null && $bot !== null) {
        $net = $bot->network;
    }
    return [$net, $bot];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Config/WebLinktitlesIgnoreHelpersTest.php`
Expected: PASS (7 tests)

- [ ] **Step 5: Commit**

```bash
git add web/sections/linktitles.php tests/Config/WebLinktitlesIgnoreHelpersTest.php
git commit -m "feat(web): linktitles ignore helpers for panel forms and tester"
```

---

### Task 5: Web handlers + routes

**Files:**
- Modify: `web/sections/linktitles.php` (`web_linktitles()` render vars + six new handlers)
- Modify: `web/routes.php:73-77` (new routes after the existing linktitles block)

- [ ] **Step 1: Extend `web_linktitles()` render vars**

In `web/sections/linktitles.php`, replace the `web_render(...)` call in `web_linktitles()` (lines 196-202) with:

```php
    web_render('linktitles.twig', [
        'active' => 'linktitles',
        'section' => 'Linktitles',
        'globalFields' => web_lt_global_fields($globalRow),
        'networks' => $networks,
        'urlIgnores' => $svc->listLinktitlesIgnores(),
        'hostIgnores' => $svc->listLinktitlesHostignores(),
        'allNetworks' => $svc->listNetworks(),
        'allBots' => $svc->listBots(),
        'error' => $error,
    ]);
```

(`$svc` is already `$app['svc']` in that function — no other change needed.)

- [ ] **Step 2: Add the handlers**

Append at the end of `web/sections/linktitles.php`:

```php
// Linktitles ignores (URL regex + hostmask) — same flow as the global
// ignores section: CSRF → validate → ConfigService → redirect.
function web_linktitles_ignores_create(): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_linktitles($e->getMessage()); }
    try {
        [$type, $net, $bot] = web_lt_ignore_scope_from_post($app);
        $pattern = is_string($_POST['pattern'] ?? null) ? $_POST['pattern'] : '';
        $app['svc']->addLinktitlesIgnore($pattern, $type, $net, $bot);
    } catch (\Throwable $e) { web_linktitles($e->getMessage()); }
    web_redirect('/linktitles');
}

function web_linktitles_ignores_delete(int $id): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_linktitles($e->getMessage()); }
    $ig = $app['svc']->getLinktitlesIgnore($id);
    if ($ig !== null) { $app['svc']->deleteLinktitlesIgnore($ig); }
    web_redirect('/linktitles');
}

function web_linktitles_hostignores_create(): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_linktitles($e->getMessage()); }
    try {
        [$type, $net, $bot] = web_lt_ignore_scope_from_post($app);
        $hostmask = is_string($_POST['hostmask'] ?? null) ? $_POST['hostmask'] : '';
        $app['svc']->addLinktitlesHostignore($hostmask, $type, $net, $bot);
    } catch (\Throwable $e) { web_linktitles($e->getMessage()); }
    web_redirect('/linktitles');
}

function web_linktitles_hostignores_delete(int $id): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_linktitles($e->getMessage()); }
    $ig = $app['svc']->getLinktitlesHostignore($id);
    if ($ig !== null) { $app['svc']->deleteLinktitlesHostignore($ig); }
    web_redirect('/linktitles');
}

// Tester (HTMX fragments). Same matcher the bot enforces with, so results
// can never drift from actual enforcement.
function web_linktitles_ignores_test(): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_error_fragment($e->getMessage()); }
    $url = trim(is_string($_POST['url'] ?? null) ? $_POST['url'] : '');
    if ($url === '') { web_error_fragment('URL required'); }
    [$net, $bot] = web_lt_test_scope_from_post($app);
    $matches = IgnoreMatcher::findUrlMatches($app['em'], $net, $bot, $url);
    web_render_fragment('linktitles/_test_result.twig', ['rows' => web_lt_match_rows($matches)]);
}

function web_linktitles_hostignores_test(): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_error_fragment($e->getMessage()); }
    $fullhost = trim(is_string($_POST['hostmask'] ?? null) ? $_POST['hostmask'] : '');
    if ($fullhost === '') { web_error_fragment('Hostmask required'); }
    [$net, $bot] = web_lt_test_scope_from_post($app);
    $matches = IgnoreMatcher::findHostMatches($app['em'], $net, $bot, $fullhost);
    web_render_fragment('linktitles/_test_result.twig', ['rows' => web_lt_match_rows($matches)]);
}
```

- [ ] **Step 3: Add the routes**

In `web/routes.php`, after line 77 (`web_linktitles_save_channel` route), add:

```php
    if ($method === 'POST' && $path === '/linktitles/ignores') { web_linktitles_ignores_create(); }
    if ($method === 'POST' && preg_match('#^/linktitles/ignores/(\d+)/delete$#', $path, $m)) { web_linktitles_ignores_delete((int)$m[1]); }
    if ($method === 'POST' && $path === '/linktitles/ignores/test') { web_linktitles_ignores_test(); }
    if ($method === 'POST' && $path === '/linktitles/hostignores') { web_linktitles_hostignores_create(); }
    if ($method === 'POST' && preg_match('#^/linktitles/hostignores/(\d+)/delete$#', $path, $m)) { web_linktitles_hostignores_delete((int)$m[1]); }
    if ($method === 'POST' && $path === '/linktitles/hostignores/test') { web_linktitles_hostignores_test(); }
```

Route order note: `/linktitles/ignores/test` must be matched by its exact-path line; it cannot collide with the `(\d+)/delete` regex (`test` is not digits), so the order above is safe either way.

- [ ] **Step 4: Verify**

Run: `vendor/bin/phpunit tests/Config/`
Expected: PASS (no regressions; the new endpoints are covered by helper tests — handler/routing plumbing follows the untested-by-convention pattern of the existing sections)

Run: `vendor/bin/phpstan analyse web/ --no-progress --memory-limit=1G`
Expected: no NEW errors vs baseline (zero referencing the new functions)

- [ ] **Step 5: Commit**

```bash
git add web/sections/linktitles.php web/routes.php
git commit -m "feat(web): linktitles ignore add/delete/test routes"
```

---

### Task 6: Templates — cards, tester, fragment, toggle JS

**Files:**
- Modify: `web/templates/linktitles.twig` (three cards + script before the closing note)
- Create: `web/templates/linktitles/_test_result.twig`

- [ ] **Step 1: Create the tester fragment**

Create `web/templates/linktitles/_test_result.twig`:

```twig
{# HTMX fragment for the linktitles ignore tester (rows from web_lt_match_rows). #}
{% if rows is empty %}
  <div class="alert alert-secondary py-2 mb-0">no match</div>
{% else %}
  <ul class="list-group">
    {% for r in rows %}
    <li class="list-group-item d-flex justify-content-between align-items-center py-2">
      <code class="text-break">{{ r.pattern }}</code>
      <span class="text-nowrap ms-2">
        <span class="badge text-bg-secondary">{{ r.scope }}</span>
        <span class="badge text-bg-secondary">#{{ r.id }}</span>
        {% if r.invalid %}<span class="badge text-bg-warning text-dark">invalid pattern</span>{% endif %}
      </span>
    </li>
    {% endfor %}
  </ul>
{% endif %}
```

- [ ] **Step 2: Add the three cards to the Linktitles page**

In `web/templates/linktitles.twig`, insert the following between the `{% endfor %}` of the networks loop (line 37) and the closing `<p>` note (line 39). Keep every existing line (including the closing note) untouched.

```twig
<div class="card mb-3"><div class="card-body">
  <h5 class="card-title">URL ignores</h5>
  <div class="table-card mb-3">
    <table class="table table-sm align-middle">
      <thead><tr><th>id</th><th>regex</th><th>scope</th><th>created</th><th></th></tr></thead>
      <tbody>
        {% for ig in urlIgnores %}
        <tr><td>{{ ig.id }}</td><td><code>{{ ig.regex }}</code></td>
          <td>{% if ig.type.toString() == 'network' %}<span class="badge text-bg-secondary">net: {{ ig.network.name }}</span>{% elseif ig.type.toString() == 'bot' %}<span class="badge text-bg-secondary">bot: {{ ig.bot.name }}</span>{% else %}<span class="badge text-bg-secondary">global</span>{% endif %}</td>
          <td>{{ ig.created.format('Y-m-d H:i') }}</td>
          <td><form class="d-inline" method="post" action="/linktitles/ignores/{{ ig.id }}/delete"><input type="hidden" name="_csrf" value="{{ csrf() }}"><button class="btn btn-outline-secondary btn-sm" type="submit">−</button></form></td></tr>
        {% endfor %}
      </tbody>
    </table>
  </div>
  <form method="post" action="/linktitles/ignores">{{ m.csrf_field() }}
    {{ m.field('pattern', 'regex pattern', '', 'text', 'example\.com/path') }}
    <div class="mb-3"><label class="form-label">type</label>
      <select class="form-select" name="type" data-lt-scope-select>
        <option value="global">global</option>
        <option value="network">network</option>
        <option value="bot">bot</option>
      </select>
      <p class="form-text text-body-secondary">The @ delimiter and i flag are added automatically.</p></div>
    <div class="mb-3" data-lt-network-select hidden><label class="form-label">network</label>
      <select class="form-select" name="network">{% for n in allNetworks %}<option value="{{ n.id }}">{{ n.name }}</option>{% endfor %}</select></div>
    <div class="mb-3" data-lt-bot-select hidden><label class="form-label">bot</label>
      <select class="form-select" name="bot">{% for b in allBots %}<option value="{{ b.id }}">{{ b.name }} ({{ b.network.name }})</option>{% endfor %}</select></div>
    {{ m.submit('Add URL ignore') }}
  </form>
</div></div>

<div class="card mb-3"><div class="card-body">
  <h5 class="card-title">Hostmask ignores</h5>
  <div class="table-card mb-3">
    <table class="table table-sm align-middle">
      <thead><tr><th>id</th><th>hostmask</th><th>scope</th><th>created</th><th></th></tr></thead>
      <tbody>
        {% for ig in hostIgnores %}
        <tr><td>{{ ig.id }}</td><td><code>{{ ig.hostmask }}</code></td>
          <td>{% if ig.type.toString() == 'network' %}<span class="badge text-bg-secondary">net: {{ ig.network.name }}</span>{% elseif ig.type.toString() == 'bot' %}<span class="badge text-bg-secondary">bot: {{ ig.bot.name }}</span>{% else %}<span class="badge text-bg-secondary">global</span>{% endif %}</td>
          <td>{{ ig.created.format('Y-m-d H:i') }}</td>
          <td><form class="d-inline" method="post" action="/linktitles/hostignores/{{ ig.id }}/delete"><input type="hidden" name="_csrf" value="{{ csrf() }}"><button class="btn btn-outline-secondary btn-sm" type="submit">−</button></form></td></tr>
        {% endfor %}
      </tbody>
    </table>
  </div>
  <form method="post" action="/linktitles/hostignores">{{ m.csrf_field() }}
    {{ m.field('hostmask', 'hostmask', '', 'text', '*nick!user@host') }}
    <div class="mb-3"><label class="form-label">type</label>
      <select class="form-select" name="type" data-lt-scope-select>
        <option value="global">global</option>
        <option value="network">network</option>
        <option value="bot">bot</option>
      </select></div>
    <div class="mb-3" data-lt-network-select hidden><label class="form-label">network</label>
      <select class="form-select" name="network">{% for n in allNetworks %}<option value="{{ n.id }}">{{ n.name }}</option>{% endfor %}</select></div>
    <div class="mb-3" data-lt-bot-select hidden><label class="form-label">bot</label>
      <select class="form-select" name="bot">{% for b in allBots %}<option value="{{ b.id }}">{{ b.name }} ({{ b.network.name }})</option>{% endfor %}</select></div>
    {{ m.submit('Add hostmask ignore') }}
  </form>
</div></div>

<div class="card mb-3"><div class="card-body">
  <h5 class="card-title">Test</h5>
  <p class="text-body-secondary">Uses the same matcher the bot enforces with. Leave the selects empty to test global rows only; a selected bot implies its network.</p>
  <div class="row">
    <div class="col-md-6">
      <form method="post" action="/linktitles/ignores/test" hx-post="/linktitles/ignores/test" hx-target="#lt-url-test-result" hx-swap="innerHTML">{{ m.csrf_field() }}
        {{ m.field('url', 'test url', '', 'text', 'https://example.com/x') }}
        <div class="mb-3"><label class="form-label">network (opt)</label>
          <select class="form-select" name="network"><option value="0">(global only)</option>{% for n in allNetworks %}<option value="{{ n.id }}">{{ n.name }}</option>{% endfor %}</select></div>
        <div class="mb-3"><label class="form-label">bot (opt)</label>
          <select class="form-select" name="bot"><option value="0">(none)</option>{% for b in allBots %}<option value="{{ b.id }}">{{ b.name }}</option>{% endfor %}</select></div>
        {{ m.submit('Test URL') }}
      </form>
      <div id="lt-url-test-result" class="mt-3"></div>
    </div>
    <div class="col-md-6">
      <form method="post" action="/linktitles/hostignores/test" hx-post="/linktitles/hostignores/test" hx-target="#lt-host-test-result" hx-swap="innerHTML">{{ m.csrf_field() }}
        {{ m.field('hostmask', 'test full host', '', 'text', 'nick!user@host') }}
        <div class="mb-3"><label class="form-label">network (opt)</label>
          <select class="form-select" name="network"><option value="0">(global only)</option>{% for n in allNetworks %}<option value="{{ n.id }}">{{ n.name }}</option>{% endfor %}</select></div>
        <div class="mb-3"><label class="form-label">bot (opt)</label>
          <select class="form-select" name="bot"><option value="0">(none)</option>{% for b in allBots %}<option value="{{ b.id }}">{{ b.name }}</option>{% endfor %}</select></div>
        {{ m.submit('Test host') }}
      </form>
      <div id="lt-host-test-result" class="mt-3"></div>
    </div>
  </div>
</div></div>

<p class="text-body-secondary">Ignores apply live (checked per URL against the DB) — no restart needed.</p>

<script>
  document.querySelectorAll('[data-lt-scope-select]').forEach(function (sel) {
    var form = sel.closest('form');
    var net = form.querySelector('[data-lt-network-select]');
    var bot = form.querySelector('[data-lt-bot-select]');
    function sync() {
      net.hidden = sel.value !== 'network';
      bot.hidden = sel.value !== 'bot';
    }
    sel.addEventListener('change', sync);
    sync();
  });
</script>
```

Notes:
- Hidden selects still submit their values, but `web_lt_ignore_scope_from_post()` only reads the select matching the chosen type, so stale values are ignored server-side.
- The tester forms carry both `action` and `hx-post` (the bots page's progressive-enhancement pattern); without JS they render the bare fragment, with HTMX they swap inline.

- [ ] **Step 3: Verify**

Run: `vendor/bin/phpunit tests/Config/`
Expected: PASS (templates are exercised only by manual verification here)

Run: `vendor/bin/phpstan analyse web/ --no-progress --memory-limit=1G`
Expected: no NEW errors vs baseline

- [ ] **Step 4: Commit**

```bash
git add web/templates/linktitles.twig web/templates/linktitles/_test_result.twig
git commit -m "feat(web): linktitles ignores cards + tester on panel page"
```

---

### Task 7: Final verification

**Files:** none (verification only, plus any php-cs-fixer fallout)

- [ ] **Step 1: Full test suite**

Run: `composer test`
Expected: PASS, zero failures (the three new files add 29 tests)

- [ ] **Step 2: Scoped PHPStan**

Run: `vendor/bin/phpstan analyse scripts/linktitles/ library/config/ConfigService.php library/BotManager.php web/ --no-progress --memory-limit=1G`
Expected: exactly the 30 pre-existing baseline errors, none referencing new symbols.

- [ ] **Step 3: php-cs-fixer on touched files only**

Run:

```bash
vendor/bin/php-cs-fixer fix scripts/linktitles/IgnoreMatcher.php scripts/linktitles/linktitles.php library/config/ConfigService.php library/BotManager.php web/sections/linktitles.php web/routes.php tests/Config/LinktitlesIgnoreMatcherTest.php tests/Config/ConfigServiceLinktitlesIgnoreTest.php tests/Config/WebLinktitlesIgnoreHelpersTest.php
```

Expected: may fix spacing/imports in the new files. Re-run `composer test` if anything changed, then commit:

```bash
git add -u
git commit -m "style: php-cs-fixer on linktitles ignores files"
```

(Only if the fixer changed files; skip the commit otherwise. Never `git add -f`.)

- [ ] **Step 4: Manual verification (requires user's dev environment)**

1. Start the panel: `php -S 127.0.0.1:8088 -t web/ web/index.php`, log in.
2. On `/linktitles`: add a global URL ignore (e.g. pattern `example\.com`), confirm it appears in the table stored as `@example\.com@i`; delete it.
3. Add a network-scoped hostmask (e.g. `*!*@*.spammer` on one network); confirm the scope badge.
4. Tester: paste `https://example.com/x` with no scope → "no match" (nothing global); with the network selected → the row matches. Test a full host (`nick!x@irc.spammer`) similarly.
5. With the bot running: add an ignore for a URL you then post in a channel — no title is fetched, immediately; delete it — titles return on the next post (per-URL DB read, no restart).
6. Error paths: submit an empty pattern (error alert), an invalid regex like `unclosed[` (error alert), and POST a delete without CSRF (error alert re-render).

---

## Self-review notes (already applied)

- Spec coverage: shared matcher (Task 1), ConfigService both kinds + validation + notifier (Task 2), urlIsIgnored delegate + comment carry-over (Task 3), BotManager no-ops (Task 3), handlers/routes incl. tester (Tasks 4-5), templates + tester fragment + toggle JS (Task 6), tests for every new unit (Tasks 1, 2, 4), manual verification (Task 7).
- Out of scope (per spec): channel scope, row editing, ignore caching, CLI changes.
