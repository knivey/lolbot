# User Timezones Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add per-user timezone storage so date-based remindme expressions resolve in the user's local timezone, with a `settimezone`/`settz` command.

**Architecture:** New Doctrine entity `UserTimezone` keyed by `(nick, network)`. Parser functions get an optional `$tzName` parameter. The `in()` command looks up the user's timezone before parsing; if date-based parsing is needed and no tz is set, it prompts the user.

**Tech Stack:** PHP 8.1+, Doctrine ORM, PHP `DateTimeZone` for validation and calculation

---

### Task 1: Create UserTimezone Entity

**Files:**
- Create: `scripts/remindme/entities/UserTimezone.php`

- [ ] **Step 1: Create the entity file**

```php
<?php

namespace scripts\remindme\entities;

use Doctrine\ORM\Mapping as ORM;
use lolbot\entities\Network;

#[ORM\Entity]
#[ORM\Table("user_timezones")]
class UserTimezone
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(updatable: false)]
    public int $id;

    #[ORM\Column]
    public string $nick;

    #[ORM\Column]
    public string $timezone;

    #[ORM\ManyToOne(targetEntity: Network::class)]
    #[ORM\JoinColumn(name: 'network_id', referencedColumnName: 'id')]
    public Network $network;
}
```

- [ ] **Step 2: Commit**

```bash
git add scripts/remindme/entities/UserTimezone.php
git commit -m "feat: add UserTimezone entity for per-user timezone storage"
```

---

### Task 2: Register Entity Path and Create Migration

**Files:**
- Modify: `bootstrap.php:23-28`
- Create: `Migrations/Version20260605120000.php`

- [ ] **Step 1: Add remindme entities path to bootstrap**

In `bootstrap.php`, add `__DIR__ . "/scripts/remindme/entities"` to the `$paths` array:

```php
$paths = [
    __DIR__ . "/entities",
    __DIR__ . "/scripts/linktitles/entities",
    __DIR__ . "/scripts/weather/entities",
    __DIR__ . "/scripts/lastfm/entities",
    __DIR__ . "/scripts/remindme/entities",
];
```

- [ ] **Step 2: Create the migration**

Create `Migrations/Version20260605120000.php`:

```php
<?php

declare(strict_types=1);

namespace lolbot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260605120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user_timezones table for per-user timezone storage';
    }

    public function up(Schema $schema): void
    {
        $t = $schema->createTable("user_timezones");
        $t->addColumn("id", Types::INTEGER)->setNotnull(true)->setAutoincrement(true);
        $t->setPrimaryKey(["id"]);
        $t->addColumn("nick", Types::STRING)->setNotnull(true);
        $t->addColumn("timezone", Types::STRING)->setNotnull(true);
        $t->addColumn("network_id", Types::INTEGER)->setNotnull(true);
        $t->addUniqueConstraint(["nick", "network_id"]);
        $t->addForeignKeyConstraint("Networks", ["network_id"], ["id"], ["onDelete" => "CASCADE"]);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable("user_timezones");
    }
}
```

- [ ] **Step 3: Verify the bot still boots (no pending migrations for existing tables)**

Run: `php admin-cli.php migrations:migrate`
Expected: Migration runs, creates `user_timezones` table. (If the bot refuses to start due to pending migrations, that confirms the migration is detected.)

- [ ] **Step 4: Commit**

```bash
git add bootstrap.php Migrations/Version20260605120000.php
git commit -m "feat: register remindme entities path and create user_timezones migration"
```

---

### Task 3: Add Timezone Parameter to Parser Functions

**Files:**
- Modify: `library/Duration.inc:174-193` (`parseDuration`)
- Modify: `library/Duration.inc:278-337` (`parseDurationDate`)
- Modify: `library/Duration.inc:340-475` (`parseDurationDateMatch`)
- Modify: `library/Duration.inc:477-485` (`parseDurationDateNthMonday`)

- [ ] **Step 1: Update `parseDuration` signature**

Change `library/Duration.inc:174`:

```php
function parseDuration(string $input, ?string $tzName = null): ?ParseResult
```

Pass `$tzName` through to `parseDurationDate` on line 187:

```php
    $result = parseDurationDate($input, $tzName);
```

- [ ] **Step 2: Update `parseDurationDate` signature and pass through**

Change `library/Duration.inc:278`:

```php
function parseDurationDate(string $input, ?string $tzName = null): ?ParseResult
```

Pass `$tzName` through to `parseDurationDateMatch` on line 330:

```php
            $result = parseDurationDateMatch($m, $dayNames, $monthNames, $ordinals, $tzName);
```

- [ ] **Step 3: Update `parseDurationDateMatch` to use DateTime when timezone is provided**

Change the signature at line 340:

```php
function parseDurationDateMatch(
    array $m,
    array $dayNames,
    array $monthNames,
    array $ordinals,
    ?string $tzName = null,
): ?ParseResult {
```

Replace the `$now`/`$baseTimestamp` initialization and all time calculations. The key change: when `$tzName` is set, use `DateTime` objects in that timezone; otherwise keep the existing `mktime`/`strtotime` behavior.

Replace lines 346-475 entirely:

```php
    $now = time();

    if ($tzName !== null) {
        $tz = new \DateTimeZone($tzName);
        $nowDt = new \DateTime('now', $tz);
        $baseDt = new \DateTime('today', $tz);
    } else {
        $baseTimestamp = strtotime('today');
    }

    if (strtolower($m[1]) === 'today') {
        $timeOffset = 2;
    } elseif (strtolower($m[1]) === 'tomorrow') {
        if ($tzName !== null) {
            $baseDt->modify('+1 day');
        } else {
            $baseTimestamp += 86400;
        }
        $timeOffset = 2;
    } elseif (isset($dayNames[strtolower($m[1])])) {
        $target = $dayNames[strtolower($m[1])];
        if ($tzName !== null) {
            $today = (int) $nowDt->format('N');
        } else {
            $today = (int) date('N');
        }
        $diff = $target - $today;
        if ($diff <= 0) {
            $diff += 7;
        }
        if ($tzName !== null) {
            $baseDt->modify("+{$diff} days");
        } else {
            $baseTimestamp += $diff * 86400;
        }
        $timeOffset = 2;
    } elseif (in_array(strtolower($m[1]), ['week', 'month', 'year', 'yr', 'yrs', 'mo', 'wk', 'wks'])) {
        $unit = strtolower($m[1]);
        if ($tzName !== null) {
            switch ($unit) {
                case 'week':
                case 'wk':
                case 'wks':
                    $today = (int) $nowDt->format('N');
                    $daysUntilMon = (8 - $today) % 7;
                    if ($daysUntilMon === 0) {
                        $daysUntilMon = 7;
                    }
                    $baseDt->modify("+{$daysUntilMon} days");
                    break;
                case 'month':
                case 'mo':
                    $baseDt->modify('+1 month');
                    break;
                case 'year':
                case 'yr':
                case 'yrs':
                    $baseDt->modify('+1 year');
                    break;
                default:
                    return null;
            }
        } else {
            switch ($unit) {
                case 'week':
                case 'wk':
                case 'wks':
                    $today = (int) date('N');
                    $daysUntilMon = (8 - $today) % 7;
                    if ($daysUntilMon === 0) {
                        $daysUntilMon = 7;
                    }
                    $baseTimestamp += $daysUntilMon * 86400;
                    break;
                case 'month':
                case 'mo':
                    $baseTimestamp = strtotime('+1 month', $baseTimestamp);
                    break;
                case 'year':
                case 'yr':
                case 'yrs':
                    $baseTimestamp = strtotime('+1 year', $baseTimestamp);
                    break;
                default:
                    return null;
            }
        }
        $timeOffset = 2;
    } elseif (isset($ordinals[strtolower($m[1])])) {
        $n = $ordinals[strtolower($m[1])];
        $month = $monthNames[strtolower($m[2])];
        if ($tzName !== null) {
            $year = (int) $nowDt->format('Y');
        } else {
            $year = (int) date('Y');
        }
        $ts = parseDurationDateNthMonday($n, $month, $year);
        if ((int) date('n', $ts) !== $month || $ts <= $now + 15) {
            $ts = parseDurationDateNthMonday($n, $month, $year + 1);
            if ((int) date('n', $ts) !== $month) {
                return null;
            }
        }
        if ($tzName !== null) {
            $baseDt = new \DateTime('@' . $ts, $tz);
            $baseDt->setTimezone($tz);
            $baseDt->setTime(0, 0, 0);
        } else {
            $baseTimestamp = $ts;
        }
        $timeOffset = 3;
    } elseif (isset($monthNames[strtolower($m[1])])) {
        $month = $monthNames[strtolower($m[1])];
        $day = (int) $m[2];
        if ($tzName !== null) {
            $year = (int) $nowDt->format('Y');
            $baseDt = new \DateTime("{$year}-{$month}-{$day}", $tz);
            if ($baseDt->getTimestamp() <= $now + 15) {
                $baseDt = new \DateTime(($year + 1) . "-{$month}-{$day}", $tz);
            }
        } else {
            $year = (int) date('Y');
            $ts = mktime(0, 0, 0, $month, $day, $year);
            if ($ts === false || $ts <= $now + 15) {
                $ts = mktime(0, 0, 0, $month, $day, $year + 1);
            }
            if ($ts === false) {
                return null;
            }
            $baseTimestamp = $ts;
        }
        $timeOffset = 3;
    } elseif (!empty($m[1]) && preg_match('/^\d{1,2}/', $m[1]) && isset($m[5]) && isset($dayNames[strtolower($m[5])])) {
        $target = $dayNames[strtolower($m[5])];
        if ($tzName !== null) {
            $today = (int) $nowDt->format('N');
            $diff = $target - $today;
            if ($diff <= 0) {
                $diff += 7;
            }
            $targetDt = (clone $baseDt)->modify("+{$diff} days");
        } else {
            $today = (int) date('N');
            $diff = $target - $today;
            if ($diff <= 0) {
                $diff += 7;
            }
            $targetTs = $baseTimestamp + $diff * 86400;
        }
        $hours = (int) $m[1];
        $minutes = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
        $seconds = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 0;
        $ampm = strtolower($m[4]);
        if ($ampm === 'pm' && $hours < 12) {
            $hours += 12;
        }
        if ($ampm === 'am' && $hours === 12) {
            $hours = 0;
        }
        if ($tzName !== null) {
            $targetDt->setTime($hours, $minutes, $seconds);
            $targetTime = $targetDt->getTimestamp();
        } else {
            $targetTime = $targetTs + $hours * 3600 + $minutes * 60 + $seconds;
        }
        $remainder = isset($m[6]) ? trim($m[6]) : '';
        if ($targetTime <= $now + 15) {
            return null;
        }
        return new ParseResult(
            seconds: $targetTime - $now,
            remainder: $remainder,
            targetTime: $targetTime,
        );
    } else {
        return null;
    }

    $hours = 0;
    $minutes = 0;
    $seconds = 0;
    if (!empty($m[$timeOffset])) {
        $hours = (int) $m[$timeOffset];
        $minutes = isset($m[$timeOffset + 1]) && $m[$timeOffset + 1] !== '' ? (int) $m[$timeOffset + 1] : 0;
        $seconds = isset($m[$timeOffset + 2]) && $m[$timeOffset + 2] !== '' ? (int) $m[$timeOffset + 2] : 0;
        $ampm = strtolower($m[$timeOffset + 3]);
        if ($ampm === 'pm' && $hours < 12) {
            $hours += 12;
        }
        if ($ampm === 'am' && $hours === 12) {
            $hours = 0;
        }
    }

    if ($tzName !== null) {
        $baseDt->setTime($hours, $minutes, $seconds);
        $targetTime = $baseDt->getTimestamp();
    } else {
        $targetTime = $baseTimestamp + $hours * 3600 + $minutes * 60 + $seconds;
    }
    $remainder = isset($m[$timeOffset + 4]) ? trim($m[$timeOffset + 4]) : '';

    if ($targetTime <= $now + 15) {
        return null;
    }

    return new ParseResult(
        seconds: $targetTime - $now,
        remainder: $remainder,
        targetTime: $targetTime,
    );
```

- [ ] **Step 4: Run existing tests to verify nothing is broken**

Run: `vendor/bin/phpunit tests/Duration/ParseDurationTest.php`
Expected: All 68 tests pass (no timezone passed = existing behavior preserved)

- [ ] **Step 5: Commit**

```bash
git add library/Duration.inc
git commit -m "feat: add optional timezone parameter to parseDuration and parseDurationDate"
```

---

### Task 4: Add Timezone-Aware Parser Tests

**Files:**
- Modify: `tests/Duration/ParseDurationTest.php`

- [ ] **Step 1: Add timezone-aware tests**

Append these tests to the `ParseDurationTest` class in `tests/Duration/ParseDurationTest.php`:

```php
    // --- Timezone-aware parsing ---

    public function test_tomorrow_with_timezone(): void
    {
        $result = \parseDuration('tomorrow 3pm eat ice cream', 'America/New_York');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertGreaterThan(time() + 15, $result->targetTime);
        $this->assertSame('eat ice cream', $result->remainder);
    }

    public function test_tomorrow_timezone_differs_from_utc(): void
    {
        $resultUtc = \parseDuration('tomorrow 3pm test', 'UTC');
        $resultNy = \parseDuration('tomorrow 3pm test', 'America/New_York');
        $this->assertNotNull($resultUtc);
        $this->assertNotNull($resultNy);
        $this->assertNotNull($resultUtc->targetTime);
        $this->assertNotNull($resultNy->targetTime);
        $this->assertNotEquals($resultUtc->targetTime, $resultNy->targetTime);
    }

    public function test_next_tuesday_with_timezone(): void
    {
        $result = \parseDuration('next tuesday 11am meeting', 'Europe/London');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertGreaterThan(time() + 15, $result->targetTime);
        $this->assertSame('meeting', $result->remainder);
    }

    public function test_bare_dayname_with_timezone(): void
    {
        $result = \parseDuration('sunday 11am ssl really', 'America/Chicago');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertGreaterThan(time() + 15, $result->targetTime);
        $this->assertSame('ssl really', $result->remainder);
    }

    public function test_named_month_with_timezone(): void
    {
        $result = \parseDuration('aug 15 3pm pay rent', 'Asia/Tokyo');
        $this->assertNotNull($result);
        $this->assertNotNull($result->targetTime);
        $this->assertGreaterThan(time() + 15, $result->targetTime);
        $this->assertSame('pay rent', $result->remainder);
    }

    public function test_duration_not_affected_by_timezone(): void
    {
        $resultUtc = \parseDuration('1h30m do stuff', 'UTC');
        $resultNy = \parseDuration('1h30m do stuff', 'America/New_York');
        $this->assertNotNull($resultUtc);
        $this->assertNotNull($resultNy);
        $this->assertSame($resultUtc->seconds, $resultNy->seconds);
        $this->assertNull($resultUtc->targetTime);
        $this->assertNull($resultNy->targetTime);
    }

    public function test_invalid_timezone_throws(): void
    {
        $this->expectException(\Exception::class);
        \parseDuration('tomorrow 3pm test', 'Invalid/Timezone');
    }
```

- [ ] **Step 2: Run all tests**

Run: `vendor/bin/phpunit tests/Duration/ParseDurationTest.php`
Expected: All tests pass (68 existing + 7 new = 75)

- [ ] **Step 3: Commit**

```bash
git add tests/Duration/ParseDurationTest.php
git commit -m "test: add timezone-aware parsing tests"
```

---

### Task 5: Add settimezone/settz Command and Integrate Timezone into in() Command

**Files:**
- Modify: `scripts/remindme/remindme.php`

- [ ] **Step 1: Add imports**

Add to the `use` block at the top of `remindme.php`:

```php
use function Symfony\Component\String\u;
use scripts\remindme\entities\UserTimezone;
```

- [ ] **Step 2: Add helper method to look up user timezone**

Add this private method to the `remindme` class:

```php
    private function getUserTimezone(string $nick): ?UserTimezone
    {
        global $entityManager;
        $nickLower = u($nick)->lower();
        return $entityManager->getRepository(UserTimezone::class)
            ->findOneBy(["nick" => $nickLower, "network" => $this->network]);
    }
```

- [ ] **Step 3: Add settimezone/settz command**

Add this method to the `remindme` class:

```php
    #[Cmd("settimezone", "settz")]
    #[Syntax("[timezone]")]
    #[Desc("Set your timezone (e.g. America/New_York, Europe/London). With no args, shows your current setting.")]
    public function settimezone(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
    {
        global $entityManager;
        $tzInput = trim($cmdArgs['timezone'] ?? '');

        if ($tzInput === '') {
            $userTz = $this->getUserTimezone($args->nick);
            if ($userTz) {
                $bot->pm($args->chan, "Your timezone is set to {$userTz->timezone}");
            } else {
                $bot->pm($args->chan, "You don't have a timezone set. Use .settz <timezone> (e.g. .settz America/New_York, Europe/London, Asia/Tokyo, UTC)");
            }
            return;
        }

        try {
            new \DateTimeZone($tzInput);
        } catch (\Exception $e) {
            $bot->pm($args->chan, "Invalid timezone. Examples: America/New_York, Europe/London, Asia/Tokyo, UTC");
            return;
        }

        $nickLower = u($args->nick)->lower();
        $userTz = $entityManager->getRepository(UserTimezone::class)
            ->findOneBy(["nick" => $nickLower, "network" => $this->network]);
        if (!$userTz) {
            $userTz = new UserTimezone();
        }
        $userTz->nick = $nickLower;
        $userTz->timezone = $tzInput;
        $userTz->network = $this->network;
        $entityManager->persist($userTz);
        $entityManager->flush();

        $bot->pm($args->chan, "Timezone set to $tzInput");
    }
```

- [ ] **Step 4: Modify `in()` method to use timezone**

Replace the `in()` method body. The key changes:
1. Look up user timezone before parsing
2. Pass timezone to `parseDuration`
3. If result is date-based and no timezone was set, prompt the user
4. Display target time in user's timezone in confirmation

Replace the `in()` method (lines 25-77):

```php
    public function in(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
    {
        global $entityManager;
        $host = $args->host;
        if (isset($this->cmdLimit[$host]) && $this->cmdLimit[$host] > time()) {
            if (!isset($this->limitWarns[$host]) || $this->limitWarns[$host] < time() - 2) {
                $bot->pm($args->chan, "You're going too fast, wait awhile");
                $this->limitWarns[$host] = time();
            }
            return;
        }
        $this->cmdLimit[$host] = time() + 2;
        unset($this->limitWarns[$host]);

        $userTz = $this->getUserTimezone($args->nick);
        $tzName = $userTz?->timezone;

        $parsed = \parseDuration($cmdArgs['timemsg'], $tzName);
        if ($parsed === null) {
            $bot->pm($args->chan, "Couldn't understand that time. Try: 1h30m, 1 hour 15 min, 2 days, next tuesday, tomorrow 3pm, next month, aug 15");
            return;
        }

        if ($parsed->remainder === '') {
            $bot->pm($args->chan, "You need to tell me what to remind you about!");
            return;
        }

        if ($parsed->targetTime !== null && $tzName === null) {
            $bot->pm($args->chan, "You need to set your timezone first: .settz America/New_York (e.g. .settz America/New_York, Europe/London, Asia/Tokyo, UTC)");
            return;
        }

        $in = $parsed->seconds;
        if ($in < 15) {
            $bot->pm($args->chan, "Give me a time at least 15 seconds in the future");
            return;
        }
        if ($in > \string2Seconds("69y")) {
            $bot->pm($args->chan, "Yeah sure I'll totally remind you in " . \Duration_toString($in) . " ;-)");
            return;
        }

        $r = new reminder();
        $r->nick = $args->nick;
        $r->chan = $args->chan;
        $r->at = $parsed->targetTime ?? time() + $in;
        $r->msg = $parsed->remainder;
        $r->network = $this->network;
        $entityManager->persist($r);
        $entityManager->flush();

        if ($parsed->targetTime !== null) {
            $dt = Carbon::createFromTimestamp($parsed->targetTime, $tzName);
            $fromNow = $dt->shortRelativeToNowDiffForHumans(Carbon::now(), 10);
            $bot->pm($args->chan, "Ok, I'll remind you on " . $dt->toCookieString() . " ($fromNow)");
        } else {
            $bot->pm($args->chan, "Ok, I'll remind you in " . \Duration_toString($in));
        }
        $this->sendDelayed($bot, $r, $in);
    }
```

- [ ] **Step 5: Run existing tests to verify nothing is broken**

Run: `vendor/bin/phpunit tests/Duration/ParseDurationTest.php`
Expected: All 75 tests pass

- [ ] **Step 6: Run static analysis**

Run: `composer phpstan`
Expected: No errors in modified files

- [ ] **Step 7: Commit**

```bash
git add scripts/remindme/remindme.php
git commit -m "feat: add settimezone/settz command, integrate timezone into remindme"
```

---

### Task 6: Final Verification

- [ ] **Step 1: Run full test suite**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 2: Run phpstan**

Run: `composer phpstan`
Expected: No errors

- [ ] **Step 3: Verify bot boots**

Run: `php bootstrap.php` (or just check that `require` of modified files doesn't error)
Expected: No fatal errors

- [ ] **Step 4: Manual smoke test with PHP CLI**

```bash
php -r '
require "vendor/autoload.php";
require "library/Duration.inc";

$r = parseDuration("tomorrow 3pm test msg", "America/New_York");
echo "NY: " . date("r", $r->targetTime) . " remainder={$r->remainder}\n";

$r = parseDuration("tomorrow 3pm test msg", "UTC");
echo "UTC: " . date("r", $r->targetTime) . " remainder={$r->remainder}\n";

$r = parseDuration("1h30m test msg", "America/New_York");
echo "Duration (should be same regardless of tz): {$r->seconds}s remainder={$r->remainder}\n";
'
```

Expected: NY and UTC show different timestamps (offset by the timezone difference), duration is the same regardless.
