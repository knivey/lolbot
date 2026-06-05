# Cache Crypto Prices

## Problem

The `stocks` script (`scripts/stocks/stocks.php`) fetches crypto prices from CoinGecko's free API on every command invocation with no caching. Multiple users triggering `!btc`, `!eth`, `!doge`, or `!bch` in quick succession causes rate limiting. The `getCoinChart()` method makes **two** API calls per invocation (chart data + current price), doubling the problem.

## Solution

Use the already-installed `amphp/cache` v2 library's `LocalCache` (in-memory, LRU, TTL-aware) to cache CoinGecko API responses within the bot process.

## Design

### Cache instance

A `static` property on the `stocks` class holds a single `Amp\Cache\LocalCache` instance, shared across all instances of the class (and therefore all IRC connections).

### Cached endpoints

| Method | Cache key pattern | TTL | What's cached |
|---|---|---|---|
| `getCoinPrice()` | `price:{coin}` | 900s (15 min) | Decoded JSON object from `/simple/price` |
| `getCoinChart()` | `chart:{coin}` | 900s (15 min) | Raw JSON string from `/market_chart` |
| `getCoinChart()` inner price call | `price:{coin}` | 900s (15 min) | Same as `getCoinPrice()` — reuses the same price cache |

### Behavior

- On cache hit: skip the HTTP request, return cached data
- On cache miss: make the API call, store in cache, return data
- Cache is in-memory only; lost on bot restart (acceptable — it rebuilds on next command)
- `LocalCache` handles TTL expiry and garbage collection automatically

### Scope of changes

Single file: `scripts/stocks/stocks.php`

- Add `use Amp\Cache\LocalCache` import
- Add `private static LocalCache $cache` property (initialized lazily or in constructor)
- Modify `getCoinPrice()`: check cache before API call
- Modify `getCoinChart()`: check cache for chart data and for the inner price call

### What's not changing

- No new dependencies
- No changes to command handlers (`btc`, `eth`, `doge`, `bch`, `stock`, `findsymbol`)
- No changes to the stock/finnhub functionality
- No persistent storage or file-based caching
