# Move Cryptocurrency to Its Own Script

## Problem

Cryptocurrency functionality currently lives inside `scripts/stocks/stocks.php`, mixed
with the unrelated finnhub stock-quote code. The crypto commands (`!btc`, `!eth`,
`!doge`, `!bch`) are hardcoded to four coins, so users cannot look up any other coin.
Additionally, the CoinGecko API access has no rate-limit protection beyond the existing
price/chart cache, and there is no protection against a single IRC channel being flooded
with crypto command output.

(GitHub issue #104: "move cryptocurrency to its own script"; comment: "also add a command
to lookup more coins, will have to search for the right symbols to send the api".)

## Solution

Extract the CoinGecko crypto functionality into a dedicated `scripts\crypto\crypto` script
class, add generic coin lookup commands (`!coin`, `!findcoin`) backed by CoinGecko's
`/search` endpoint, and add two independent rate-limiting layers: a low-level global guard
that protects CoinGecko API access, and a per-channel anti-spam cooldown.

## Design

### File layout

| File | Change |
|---|---|
| `scripts/crypto/crypto.php` | **New.** Class `scripts\crypto\crypto extends \scripts\script_base`. Holds all crypto commands and CoinGecko access. |
| `scripts/crypto/coinSearchResult.php` | **New.** JsonMapper DTO for one entry of CoinGecko `/search` `coins[]`: `string $id`, `string $name`, `string $symbol`, `string $api_symbol`, `?int $market_cap_rank`. Mirrors the `scripts/stocks/symbol.php` convention. |
| `scripts/crypto/ApiRateLimitException.php` | **New.** Tiny `class ApiRateLimitException extends \Exception {}`. Used so handlers can catch the global API guard specifically. |
| `scripts/stocks/stocks.php` | **Trimmed.** Keeps only finnhub/stock code (`stock`, `findsymbol`, `quote()`, `symbolSearch()`). Removes the four crypto commands, `getCoinPrice`, `getCoinChart`, the `static LocalCache` + `getCache()`, and the now-unused imports (`Amp\Cache\LocalCache`, `draw`, `knivey\irctools`). Keeps `multi_array_padding` (used by `findsymbol`). |
| `lolbot.php` | **Edited.** Add `use scripts\crypto\crypto;`, then instantiate + `$router->loadMethods($crypto)` immediately after the existing stocks block (`lolbot.php:188-189`). |

The `scripts\` → `scripts/` PSR-4 mapping already covers the new directory; no autoload
change is required.

### Commands (all on the `crypto` class)

| Command | Behavior |
|---|---|
| `!btc` | One-liner delegating to `showCoin('bitcoin')`. |
| `!eth` | One-liner delegating to `showCoin('ethereum')`. |
| `!doge` | One-liner delegating to `showCoin('dogecoin')`. |
| `!bch` | One-liner delegating to `showCoin('bitcoin-cash')`. |
| `!coin <name>` | Search CoinGecko, pick best match, then `showCoin($matched->id)`. |
| `!findcoin <query>` | Search CoinGecko, list top 5 results in a table (mirrors `!findsymbol`). |

The four shortcut commands' observable behavior is byte-for-byte identical to today; only
their duplicated bodies collapse into the shared `showCoin()` core.

### Core methods

- **`showCoin(\Irc\Event\ChatEvent $args, \Irc\Client $bot, string $id): void`** — the
  unified logic currently copy-pasted across the four shortcuts (`stocks.php:159-233`):
  - `try` → if `$this->server->throttle`: `pm(getCoinPrice($id))` and return; else `pm`
    each line of `getCoinChart($id)`.
  - `catch (async_get_exception)` → `pm("Error getting data")`.
  - `catch (ApiRateLimitException)` → `apiWarn($args, $bot)` and return.

- **`getCoinPrice(string $id): string`** and **`getCoinChart(string $id): string[]`** —
  moved **verbatim** from `stocks` (static `LocalCache` + `getCache()` come with them).
  Cache keys `price:{coin}` (900s) and `chart:{coin}` (900s); `getCoinChart`'s inner price
  lookup reuses `price:{coin}`. The only edit is that each `async_get_contents(...)` call
  site is replaced with `$this->coinApiGet(...)` so it goes through the API guard. The
  guard runs only on a cache miss (cache hits never reach the network).

- **`searchCoins(string $query): list<coinSearchResult>`** — calls CoinGecko
  `/search?query={rawurlencode($query)}` via `coinApiGet`, maps `$j->coins` to the DTO
  array with JsonMapper. **Cached** under `search:{rawurlencode($query)}` (900s) using the
  same `LocalCache`. Throws `\Exception` on a malformed API response (missing/non-numeric
  `count`, or missing `coins`); returns `[]` on zero matches so each caller can format its
  own message. Note: `ApiRateLimitException` (a `\Exception` subclass) is thrown separately
  by `coinApiGet` before any network access, so callers can distinguish the two.

- **`matchCoin(string $query, list<coinSearchResult> $results): ?coinSearchResult`** —
  pure (no I/O), the selection logic for `!coin`:
  1. Case-insensitive exact match on `id` **or** `symbol` (ticker) → first such hit.
     (`!coin bitcoin` → bitcoin; `!coin BTC` → bitcoin.)
  2. Else → first result (CoinGecko pre-ranks by relevance/market cap).
     (`!coin bit` → top-ranked fuzzy hit.)
  3. Else (empty input list) → `null`.
  `market_cap_rank` is null for obscure coins, so the DTO types it `?int`.

- **`coinApiGet(string $url): string`** — the single chokepoint for every CoinGecko HTTP
  call. Replaces `async_get_contents` inside `getCoinPrice`, `getCoinChart`, and
  `searchCoins`. Enforces the Layer A global guard (see below); throws
  `ApiRateLimitException` when the global ceiling is exceeded.

### `!coin <name>` handler

1. `searchCoins($name)`.
2. `matchCoin($name, $results)`.
3. Empty results or `null` match → `pm("\2Coin:\2 No coin found for '{name}' (try !findcoin {name})")` and return.
4. Else `showCoin($args, $bot, $matched->id)`.
5. The preamble (steps 1-2) is wrapped so that:
   - `catch (ApiRateLimitException)` → `apiWarn($args, $bot)` → return.
   - `catch (\Exception)` → `pm("\2Coin:\2 Error getting data")` → return.
   (Catch `ApiRateLimitException` before `\Exception`, since the former subclasses the
   latter. The `showCoin` call in step 4 handles its own `ApiRateLimitException`
   internally — see below — so it does not propagate here.)

### `!findcoin <query>` handler

Structurally identical to `findsymbol` (`stocks.php:128-157`): take top 5 results, build a
`[["ID", "Name", "Symbol"]]` table, `multi_array_padding` + `pm` each line (prefix
`\2Coin:\2`).

1. `searchCoins($query)`.
2. Empty → `pm("\2Coin:\2 No results found")` and return.
3. Else render the table.
4. The `searchCoins` call (step 1) is wrapped so that:
   - `catch (ApiRateLimitException)` → `apiWarn($args, $bot)` → return.
   - `catch (\Exception)` → `pm("\2Coin:\2 Error getting data")` → return.
   (`ApiRateLimitException` caught before `\Exception`.)

### Rate limiting — Layer A: low-level API guard (global)

Protects CoinGecko API access across **all** networks/connections (the `crypto` class is
instantiated per-bot, but the guard state is `static`, so it is process-global — this is
the cross-network protection the existing per-instance `LocalCache` does not provide).

- `private static int $apiRatelimit = 0;` on the `crypto` class.
- Implemented inside `coinApiGet` in the **brave pattern** (`scripts/brave/brave.php:9-31`):
  `if (time() < self::$apiRatelimit) throw new ApiRateLimitException;` else
  `self::$apiRatelimit = time() + 2;` *before* the `async_get_contents` await.
- Hard-coded **2-second** global ceiling (matches brave's `time() + 2`; not configurable).
- **No mutex required.** Although the bot is single-threaded cooperative async, Amp
  coroutines interleave at `await` points. The reservation is set **synchronously before**
  the await, so any coroutine that interleaves during the HTTP call already sees the
  updated `self::$apiRatelimit` — there is no read→await→write window to race on.
- Fires only on a cache miss (cache hits return before reaching `coinApiGet`).

### Rate limiting — Layer B: per-channel anti-spam cooldown

Prevents a single IRC channel from being flooded with crypto output. Instance-level (per
bot/connection), keyed by `$args->chan`, in the **linktitles pattern**
(`scripts/linktitles/linktitles.php:78-108`).

- `private array $chanRatelimit = [];` — `array<string, list<int>>`, chan → recent timestamps.
- **Combined bucket**: all crypto commands share one bucket per channel, so alternating
  `!btc`/`!eth`/`!coin` cannot bypass the limit.
- Sliding window, checked at the top of every crypto command handler (before any API work):
  - `$max = (int)($config['crypto_rate_cmds'] ?? 3);`
  - `$window = (int)($config['crypto_rate_secs'] ?? 20);`
  - filter stale entries (`$now - $ts < $window`); if `count >= $max` → blocked; else
    record `$now` and proceed.
- Defaults are intentionally generous (`3` per `20s`); operators tune via config.

### Warning messages

Two separate helpers, two distinct messages, two separate per-channel cooldown trackers
(neither suppresses the other; each is self-cooled against spam):

- **`spamWarn($args, $bot): void`** — Layer B message:
  `pm("\2Coin:\2 You're running crypto commands too fast, slow down.")`.
  Cooldown tracked in `private array $chanWarn = [];` keyed by chan.
- **`apiWarn($args, $bot): void`** — Layer A message:
  `pm("\2Coin:\2 API rate limit reached, try again in a moment.")`.
  Cooldown tracked in `private array $apiWarnChan = [];` keyed by chan.
- Both use the same cooldown duration: `$wc = (int)($config['crypto_warn_secs'] ?? 30);`.
  A helper emits its message only if `time() - ($track[$chan] ?? 0) >= $wc`, then sets
  `$track[$chan] = time()`.

Distinct messages ensure users can tell channel-spam feedback apart from global API
back-pressure.

### Handler rate-limit flow

Every crypto command handler, top-to-bottom:

1. **Layer B check.** If channel bucket exceeded → `spamWarn($args, $bot)` → return.
2. Else record `$now` in the channel bucket.
3. Proceed to the command's work.
4. The four shortcuts simply call `showCoin(...)`, which self-handles
   `ApiRateLimitException` (its own `try`/`catch` calls `apiWarn` and returns).
5. `!coin` and `!findcoin` wrap their `searchCoins` preamble in
   `catch (ApiRateLimitException) → apiWarn → return` (before a broader
   `catch (\Exception)`), since `showCoin`'s internal catch does not cover the preamble.

This guarantees a legit command is never silently dropped: Layer B gives channel-feedback,
Layer A gives API-feedback, and both are cooled.

### Config keys (new, all optional — sensible defaults)

| Key | Default | Purpose |
|---|---|---|
| `crypto_rate_cmds` | `3` | Max crypto commands per window per channel (Layer B). |
| `crypto_rate_secs` | `20` | Layer B window length in seconds. |
| `crypto_warn_secs` | `30` | Per-channel cooldown for both warning messages. |

Naming follows the existing `linktitles_rate_urls` / `linktitles_rate_seconds` convention.
The Layer A global ceiling is hard-coded (not configurable), matching brave.

### Testing

- No stocks/crypto tests exist today. Add `tests/Crypto/` covering the **pure**
  `matchCoin()` selection logic: exact-id, exact-symbol, ranked-fallback, and empty-input.
  Follow the existing PHPUnit layout (`phpunit.xml`, `composer test`).
- Network-dependent methods (`searchCoins`, `getCoinPrice`, `getCoinChart`, `coinApiGet`)
  and the rate-limit/warning helpers are I/O-bound and remain untested, consistent with
  `stocks` and `brave` (which have no tests).

## What's not changing

- No CoinGecko API key handling (the free API continues to need none; no config key added
  for it).
- No persistent or file-based caching (in-memory `LocalCache` only, as today).
- No database or migration changes.
- No change to stock-command behavior (`stock`, `findsymbol`).
- No trimming of art/chart output (per project rule: never trim rendered art).
- The four shortcut commands behave exactly as before.
