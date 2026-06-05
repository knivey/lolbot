# User Timezones for Remindme

## Overview

Add per-user timezone storage so that date-based expressions like `sunday 11am` resolve in the user's local timezone instead of UTC. Users without a timezone set get a prompt to set one when using date-based parsing.

## Entity

**File:** `scripts/remindme/entities/UserTimezone.php`

```php
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

- `nick` stored lowercase via `Symfony\Component\String\u($nick)->lower()` (same as weather/lastfm)
- Unique constraint on `(nick, network_id)` — one timezone per nick per network

## Bootstrap Registration

Add `__DIR__ . "/scripts/remindme/entities"` to the `$paths` array in `bootstrap.php`. Note: this directory already exists (contains `reminder.php`), it just wasn't registered as a Doctrine annotation path.

## Migration

Create a Doctrine migration that creates the `user_timezones` table:

- `id` — integer, auto-increment, primary key
- `nick` — string, not null
- `timezone` — string, not null
- `network_id` — integer, foreign key to `Networks(id)` with `ON DELETE CASCADE`
- Unique constraint on `(nick, network_id)`

## Commands

All commands are methods on the existing `remindme` class in `scripts/remindme/remindme.php`.

### `.settimezone` / `.settz`

```
#[Cmd("settimezone", "settz")]
#[Syntax("[timezone]")]
#[Desc("Set your timezone (e.g. America/New_York, Europe/London). With no args, shows your current setting.")]
```

**With arg:** Validate the timezone string via `new \DateTimeZone($tz)`. If invalid, reply with error and a few example timezones. If valid, upsert the `UserTimezone` entity and confirm.

**Without arg:** Look up the user's timezone. If found, reply with it. If not found, reply that no timezone is set and how to set one.

### Display confirmation

On successful set: `"Timezone set to America/New_York"`

### Validation error

If timezone string is not a valid PHP timezone identifier: `"Invalid timezone. Examples: America/New_York, Europe/London, Asia/Tokyo, UTC"`

## Parser Changes

### `parseDuration` function signature

Currently: `parseDuration(string $input): ?ParseResult`
New: `parseDuration(string $input, ?string $tzName = null): ?ParseResult`

Duration-based parsing (Phase 1: `parseDurationRegex`) is unaffected — it returns relative seconds, no timezone needed.

### `parseDurationDate` function signature

Currently: `parseDurationDate(string $input): ?ParseResult`
New: `parseDurationDate(string $input, ?string $tzName = null): ?ParseResult`

When `$tzName` is provided and valid, all date calculations use `DateTime` with that timezone instead of `mktime()`/`strtotime()` server-local assumptions. Specifically:

- `strtotime('today')` becomes `new DateTime('today', new DateTimeZone($tzName))` for the base timestamp
- Day-of-week offsets are computed relative to "today" in the user's timezone
- Time-of-day (hours/minutes/seconds) is applied in the user's timezone
- The final `targetTime` is a Unix timestamp (UTC-based) — no ambiguity

When `$tzName` is null (no timezone set), date parsing still works but uses server time (UTC). The calling code in the `in()` method handles the "no timezone" prompt.

### `in()` / `remindme` command behavior change

After `parseDuration` returns a result with a `targetTime` (date-based) and the user has no stored timezone:

1. Look up `UserTimezone` for the user's nick + network
2. If found, pass the timezone name to `parseDuration`
3. If not found and the result was date-based (`targetTime !== null`), reply: `"You need to set your timezone first: .settz America/New_York"` (include a few common examples)
4. Duration-based results (no `targetTime`) are unaffected — they work without timezone

The confirmation message in `in()` should display the target time in the user's timezone when available.

## Files Changed

| File | Change |
|------|--------|
| `scripts/remindme/entities/UserTimezone.php` | New entity |
| `scripts/remindme/remindme.php` | Add `settimezone`/`settz` command, modify `in()` to use timezone |
| `library/Duration.inc` | Add `$tzName` parameter to `parseDuration` and `parseDurationDate`, use `DateTime` for tz-aware calculations |
| `bootstrap.php` | Add remindme entities path |
| `Migrations/Version*.php` | New migration for `user_timezones` table |
| `tests/Duration/ParseDurationTest.php` | Add tests for timezone-aware parsing |
