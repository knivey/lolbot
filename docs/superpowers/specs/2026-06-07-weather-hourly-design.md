# Weather: Add Hourly Forecast (`--hourly`)

## Summary

Add a `--hourly` / `--hr` flag to the `weather` command that displays the next 12 hours of forecast in a compact inline format. An optional `--detailed` / `--d` flag expands each hourly entry with wind, humidity, and precipitation probability.

## Design

### Option registration

Add `--hourly` and `--hr` to the existing `#[Options]` attribute on the weather command (`weather.php:139`):

```php
#[Options("--si", "--metric", "--us", "--imperial", "--fc", "--forecast", "--hourly", "--hr", "--detailed", "--d")]
```

### Mutual exclusivity

`--hourly` / `--hr` and `--fc` / `--forecast` are mutually exclusive. If both are present, the bot replies with an error message (same pattern as the existing `--si` / `--us` conflict at `weather.php:158-162`).

### API call modification

When `--hourly` is active, change the API `exclude` parameter from `minutely,hourly` to `minutely` only, so the hourly array is included in the OpenWeatherMap One Call 3.0 response.

When `--hourly` is not active, keep `exclude=minutely,hourly` as-is (no change to current behavior).

### Output format

**Default (condition + temp only):**

```
\2{location}:\2 Hourly: 1P: Clear 72°F, 2P: Cloudy 70°F, 3P: Rain 65°F, ...
```

Each entry: `{time}: {condition} {temp}`

**With `--detailed` / `--d` (adds wind, humidity, precip):**

```
\2{location}:\2 Hourly: 1P: Clear 72°F SW5mph 45%h 10%p, 2P: Cloudy 70°F NW8mph 50%h 30%p, ...
```

Each entry: `{time}: {condition} {temp} {wind_dir}{wind_speed} {humidity}%h {pop}%p`

### Data mapping from OpenWeatherMap hourly objects

| API field            | Output           | Notes |
|----------------------|------------------|-------|
| `dt`                 | Time             | Formatted as `gA` (e.g., `1P`, `2P`), offset by `timezone_offset` |
| `weather[0].description` | Condition   | First letter capitalized |
| `temp`               | Temperature      | Formatted per unit preference (°F/°C, mph/m/s) |
| `wind_speed`         | Wind speed       | Only with `--detailed` |
| `wind_deg`           | Wind direction   | Converted to compass abbreviation (N, NE, etc.), only with `--detailed` |
| `humidity`           | Humidity %       | Only with `--detailed` |
| `pop`                | Precip chance %  | Only with `--detailed` |

### Hour count

Fixed at 12 hours (first 12 entries from the `hourly` array).

### `--detailed` scope

`--detailed` only affects output when `--hourly` is active. It is silently ignored when used without `--hourly`.

### Message splitting

If the formatted string exceeds IRC line length limits, the bot's existing message sending handles splitting (same as the daily forecast behavior).

## Files touched

| File | Change |
|------|--------|
| `scripts/weather/weather.php` | Add `--hourly`/`--hr` and `--detailed`/`--d` options, exclusivity check with `--fc`, conditional API exclude logic, hourly formatting block |

No new files, no entity changes, no config changes, no database migrations.
