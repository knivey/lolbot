# Move Cryptocurrency to Its Own Script — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract all CoinGecko crypto functionality out of `scripts/stocks/stocks.php` into a new `scripts\crypto\crypto` class, add `!coin`/`!findcoin` lookup commands backed by CoinGecko `/search`, and add two rate-limiting layers (a global API guard and a per-channel anti-spam cooldown).

**Architecture:** A new `crypto` script class (class-based script pattern, extends `scripts\script_base`) owns every CoinGecko call. A single `coinApiGet()` chokepoint enforces a global 2s API guard (brave pattern, set-before-await, no mutex). Per-channel sliding-window cooldown (linktitles pattern) gates every handler. A pure `matchCoin()` selects the coin for `!coin`. The four existing shortcuts collapse into a shared `showCoin()` core. `stocks.php` is trimmed to finnhub-only.

**Tech Stack:** PHP 8.1+, Amp v3 / Revolt async, Doctrine-less script class, `knivey/cmdr` attributes (`#[Cmd]`, `#[Syntax]`), `JsonMapper` DTOs, `Amp\Cache\LocalCache`, PHPUnit 10.

**Spec:** `docs/superpowers/specs/2026-06-17-crypto-script-design.md`

---

## File Structure

| File | Responsibility |
|---|---|
| `scripts/crypto/crypto.php` | **Create.** The `crypto` script class: all commands + CoinGecko access + rate limiting. |
| `scripts/crypto/coinSearchResult.php` | **Create.** JsonMapper DTO for one CoinGecko `/search` coin entry. |
| `scripts/crypto/ApiRateLimitException.php` | **Create.** Sentinel exception for the global API guard. |
| `scripts/stocks/stocks.php` | **Modify.** Remove crypto code; keep finnhub stock code. |
| `lolbot.php` | **Modify.** Register the new `crypto` class. |
| `tests/Crypto/MatchCoinTest.php` | **Create.** Unit tests for the pure `matchCoin()` selector. |

The `scripts\` → `scripts/` PSR-4 mapping already covers `scripts/crypto/`. No autoload change needed.

### Verification commands (used throughout)

- Static analysis: `composer phpstan` and `vendor/bin/psalm`
- Tests: `composer test` (or targeted: `vendor/bin/phpunit tests/Crypto/MatchCoinTest.php`)
- Formatting: `vendor/bin/php-cs-fixer fix`
- Both `composer phpstan` and `vendor/bin/psalm` run at strict levels (9 / 1) across the whole tree including `scripts/` and `tests/`, so every task must keep them green.

---

## Task 1: Create supporting types (DTO + sentinel exception)

These two small files have no behavior of their own; they are depended on by later tasks.

**Files:**
- Create: `scripts/crypto/coinSearchResult.php`
- Create: `scripts/crypto/ApiRateLimitException.php`

- [ ] **Step 1: Create the `coinSearchResult` DTO**

Create `scripts/crypto/coinSearchResult.php`. It mirrors the `scripts/stocks/symbol.php` JsonMapper convention. `market_cap_rank` is nullable because CoinGecko returns `null` for obscure coins.

```php
<?php

namespace scripts\crypto;

use JsonMapper\Middleware\Attributes\MapFrom;

class coinSearchResult
{
    #[MapFrom("id")]
    public string $id;

    #[MapFrom("name")]
    public string $name;

    #[MapFrom("api_symbol")]
    public string $api_symbol;

    #[MapFrom("symbol")]
    public string $symbol;

    #[MapFrom("market_cap_rank")]
    public ?int $market_cap_rank = null;
}
```

- [ ] **Step 2: Create the `ApiRateLimitException` sentinel**

Create `scripts/crypto/ApiRateLimitException.php`:

```php
<?php

namespace scripts\crypto;

class ApiRateLimitException extends \Exception
{
}
```

- [ ] **Step 3: Verify static analysis passes**

Run: `composer phpstan && vendor/bin/psalm`
Expected: PASS (both files are trivial; no issues).

- [ ] **Step 4: Commit**

```bash
git add scripts/crypto/coinSearchResult.php scripts/crypto/ApiRateLimitException.php
git commit -m "feat(crypto): add coinSearchResult DTO and ApiRateLimitException (#104)"
```

---

## Task 2: TDD the pure `matchCoin()` selector

Create the `crypto` class skeleton containing only the pure `matchCoin()` static method, test-driven. This method has no I/O so it is fully unit-testable (the only such method in the feature).

**Files:**
- Create: `scripts/crypto/crypto.php`
- Test: `tests/Crypto/MatchCoinTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Crypto/MatchCoinTest.php`. (Namespace follows the existing `Tests\Weather` / `Tests\Alias` convention.)

```php
<?php

namespace Tests\Crypto;

use PHPUnit\Framework\TestCase;
use scripts\crypto\coinSearchResult;
use scripts\crypto\crypto;

class MatchCoinTest extends TestCase
{
    private static function mkCoin(string $id, string $name, string $symbol): coinSearchResult
    {
        $c = new coinSearchResult();
        $c->id = $id;
        $c->name = $name;
        $c->api_symbol = $id;
        $c->symbol = $symbol;
        $c->market_cap_rank = null;
        return $c;
    }

    public function test_exact_id_match(): void
    {
        $results = [
            self::mkCoin('ethereum', 'Ethereum', 'ETH'),
            self::mkCoin('bitcoin', 'Bitcoin', 'BTC'),
        ];
        $match = crypto::matchCoin('bitcoin', $results);
        $this->assertNotNull($match);
        $this->assertSame('bitcoin', $match->id);
    }

    public function test_exact_symbol_match_case_insensitive(): void
    {
        $results = [
            self::mkCoin('ethereum', 'Ethereum', 'ETH'),
            self::mkCoin('bitcoin', 'Bitcoin', 'BTC'),
        ];
        $match = crypto::matchCoin('BTC', $results);
        $this->assertNotNull($match);
        $this->assertSame('bitcoin', $match->id);
    }

    public function test_exact_match_preferred_over_ranked_first(): void
    {
        // First (ranked) result does NOT exact-match; second does.
        $results = [
            self::mkCoin('bitcoin-cash', 'Bitcoin Cash', 'BCH'),
            self::mkCoin('bitcoin', 'Bitcoin', 'BTC'),
        ];
        $match = crypto::matchCoin('bitcoin', $results);
        $this->assertNotNull($match);
        $this->assertSame('bitcoin', $match->id);
    }

    public function test_ranked_fallback_returns_first_when_no_exact(): void
    {
        $results = [
            self::mkCoin('bitcoin', 'Bitcoin', 'BTC'),
            self::mkCoin('bitcoin-cash', 'Bitcoin Cash', 'BCH'),
        ];
        $match = crypto::matchCoin('bit', $results);
        $this->assertNotNull($match);
        $this->assertSame('bitcoin', $match->id);
    }

    public function test_empty_results_returns_null(): void
    {
        $this->assertNull(crypto::matchCoin('anything', []));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Crypto/MatchCoinTest.php`
Expected: FAIL — `Class "scripts\crypto\crypto" not found`.

- [ ] **Step 3: Create the `crypto` class with `matchCoin()`**

Create `scripts/crypto/crypto.php`. For now it contains only the class shell, the constructor is inherited from `script_base`, and the single static method under test. Note `matchCoin` is `public static` so the test can call it without constructing (mirrors how `tests/Weather/FormatHourlyEntryTest.php` calls `weather::formatHourlyEntry`).

```php
<?php

namespace scripts\crypto;

use scripts\script_base;

class crypto extends script_base
{
    /**
     * Pick the best coin from CoinGecko search results.
     *
     * @param string $query
     * @param list<coinSearchResult> $results
     * @return coinSearchResult|null
     */
    public static function matchCoin(string $query, array $results): ?coinSearchResult
    {
        $q = strtolower($query);
        foreach ($results as $r) {
            if (strtolower($r->id) === $q || strtolower($r->symbol) === $q) {
                return $r;
            }
        }
        return $results[0] ?? null;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Crypto/MatchCoinTest.php`
Expected: PASS — 5 tests.

- [ ] **Step 5: Verify static analysis**

Run: `composer phpstan && vendor/bin/psalm`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add scripts/crypto/crypto.php tests/Crypto/MatchCoinTest.php
git commit -m "feat(crypto): add matchCoin selector with tests (#104)"
```

---

## Task 3: CoinGecko fetch layer (cache + API guard + price/chart/search)

Add the data-access methods to `scripts/crypto/crypto.php`. These move **verbatim** from `stocks.php` (per the project rule, preserve every existing comment — including `//box`, `// api gives hourly...`, `//hope this works out lol`), with the single change that each `async_get_contents(...)` call site becomes `$this->coinApiGet(...)` so all CoinGecko HTTP access is funneled through one rate-limited chokepoint.

**Files:**
- Modify: `scripts/crypto/crypto.php`

- [ ] **Step 1: Add the needed `use` statements**

At the top of `scripts/crypto/crypto.php`, immediately after the existing `use scripts\script_base;` line, add:

```php
use Amp\Cache\LocalCache;
use JsonMapper\JsonMapperBuilder;
use draw;
use knivey\irctools;
```

- [ ] **Step 2: Add the static cache + global API-guard state and helpers**

Inside the `crypto` class (e.g. right after the opening `{` of the class, before `matchCoin`), add the static `$cache`/`getCache()` (moved verbatim from `stocks.php:17-27`) and the new global `$apiRatelimit`:

```php
    /** @var LocalCache<mixed>|null */
    private static ?LocalCache $cache = null;

    private static int $apiRatelimit = 0;

    /** @return LocalCache<mixed> */
    private static function getCache(): LocalCache
    {
        if (self::$cache === null) {
            self::$cache = new LocalCache();
        }
        return self::$cache;
    }
```

- [ ] **Step 3: Add the `coinApiGet()` chokepoint (Layer A: global API guard)**

Still inside the class, add:

```php
    /**
     * Single chokepoint for every CoinGecko HTTP call.
     *
     * Enforces a process-global 2s API guard in the brave pattern
     * (scripts/brave/brave.php): the reservation is set synchronously
     * BEFORE the await, so no mutex is needed despite async interleaving.
     *
     * @throws ApiRateLimitException when the global ceiling is exceeded
     */
    private function coinApiGet(string $url): string
    {
        if (time() < self::$apiRatelimit) {
            throw new ApiRateLimitException();
        }
        self::$apiRatelimit = time() + 2;
        return async_get_contents($url);
    }
```

- [ ] **Step 4: Add `getCoinPrice()` (moved verbatim)**

```php
    public function getCoinPrice(string $coin): string
    {
        $cacheKey = "price:$coin";
        $cached = self::getCache()->get($cacheKey);
        if ($cached !== null) {
            $current = $cached;
        } else {
            $json = json_decode($this->coinApiGet("https://api.coingecko.com/api/v3/simple/price?ids=$coin&vs_currencies=usd&include_24hr_change=true"));
            $current = $json->$coin->usd;
            if ($current !== null) {
                self::getCache()->set($cacheKey, $current, 900);
            }
        }
        return "Current price for $coin: $current USD";
    }
```

- [ ] **Step 5: Add `getCoinChart()` (moved verbatim — keep all comments)**

```php
    /**
     * @return string[]
     */
    public function getCoinChart(string $coin): array
    {
        $chartCacheKey = "chart:$coin";
        $priceCacheKey = "price:$coin";

        $chartJson = self::getCache()->get($chartCacheKey);
        if ($chartJson === null) {
            $chartJson = json_decode($this->coinApiGet("https://api.coingecko.com/api/v3/coins/$coin/market_chart?vs_currency=usd&days=7"));
            if ($chartJson !== null) {
                self::getCache()->set($chartCacheKey, $chartJson, 900);
            }
        }

        $w = 86; // api gives hourly for 7 days cut out half those data points and give room for box
        $h = 30;
        $canvas = draw\Canvas::createBlank($w, $h, true);

        //box

        $canvas->drawPath(draw\Path::line(      0,        0,       0, $h - 1), null, new draw\StrokeStyle(new draw\Color(14)));
        $canvas->drawPath(draw\Path::line( $w - 1,        0,  $w - 1, $h - 1), null, new draw\StrokeStyle(new draw\Color(14)));
        $canvas->drawPath(draw\Path::line(      0,        0,  $w - 1,      0), null, new draw\StrokeStyle(new draw\Color(14)));
        $canvas->drawPath(draw\Path::line(      0,   $h - 1,  $w - 1, $h - 1), null, new draw\StrokeStyle(new draw\Color(14)));
        for($x = 0; $x < $w; $x+=12) {
            $canvas->drawPoint($x, 0, new draw\Color(15));
            $canvas->drawPoint($x, $h - 1, new draw\Color(15));
        }

        $prices = [];
        $cnt = 0;
        foreach ($chartJson->prices as $p) {
            if ($cnt++ % 2 == 0) {
                continue;
            }
            $prices[] = $p[1];
        }

        $min = min($prices);
        $max = max($prices);
        $rng = $max - $min;

        $i = count($prices);
        echo $i;
        $prices=array_reverse($prices);
        $ly = 0;
        $red = new draw\Color(4);
        $green = new draw\Color(9);
        $yellow = new draw\Color(8);
        $color = $yellow;
        foreach ($prices as $p) {
            $y = $h - 2 - (int)round((($p - $min) / $rng) * ($h - 3));
            if($i != count($prices)) {
                if($ly == $y)
                    $color = $yellow;
                if($ly > $y)
                    $color = $red;
                if($ly < $y)
                    $color = $green;
                $canvas->drawPath(draw\Path::line($i+1, $ly, $i, $y), null, new draw\StrokeStyle($color));
            }
            $ly = $y;
            $i--;
        }

        $current = self::getCache()->get($priceCacheKey);
        if ($current === null) {
            $json = json_decode($this->coinApiGet("https://api.coingecko.com/api/v3/simple/price?ids=$coin&vs_currencies=usd&include_24hr_change=true"));
            $current = $json->$coin->usd;
            if ($current !== null) {
                self::getCache()->set($priceCacheKey, $current, 900);
            }
        }

        $out = explode("\n", (string)$canvas);
        foreach($out as &$line)
            $line = irctools\fixColors($line);

        //hope this works out lol
        $out[] = "7 day min price: $min USD";
        $out[] = "7 day max price: $max USD";
        $out[] = "Current price: $current USD";
        return $out;
    }
```

- [ ] **Step 6: Add `searchCoins()` (cached, uses the `/search` endpoint)**

This mirrors the `symbolSearch()` fetch+map structure (`stocks.php:61-82`). It is cached under `search:{query}` (900s). It throws `\Exception` on a malformed response and returns `[]` on zero matches.

```php
    /**
     * Search CoinGecko coins. Cached for 900s.
     *
     * @return list<coinSearchResult>
     *
     * @throws ApiRateLimitException via coinApiGet
     * @throws \Exception on malformed API response
     */
    public function searchCoins(string $query): array
    {
        $cacheKey = "search:" . rawurlencode($query);
        $cached = self::getCache()->get($cacheKey);
        if ($cached !== null) {
            /** @var list<coinSearchResult> $cached */
            return $cached;
        }

        $q = rawurlencode($query);
        $body = $this->coinApiGet("https://api.coingecko.com/api/v3/search?query=$q");
        $j = json_decode($body);
        if (!isset($j->coins) || !is_array($j->coins)) {
            throw new \Exception("API error");
        }

        $mapper = JsonMapperBuilder::new()
            ->withTypedPropertiesMiddleware()
            ->withAttributesMiddleware()
            ->build();
        $out = $mapper->mapToClassArray($j->coins, coinSearchResult::class);

        self::getCache()->set($cacheKey, $out, 900);
        return $out;
    }
```

- [ ] **Step 7: Verify static analysis**

Run: `composer phpstan && vendor/bin/psalm`
Expected: PASS. (The moved chart code is identical to the currently-passing `stocks.php`; `searchCoins` mirrors `symbolSearch`'s proven fetch/map shape.)

- [ ] **Step 8: Commit**

```bash
git add scripts/crypto/crypto.php
git commit -m "feat(crypto): add CoinGecko fetch layer with cache and API guard (#104)"
```

---

## Task 4: Rate limiting (Layer B per-channel cooldown) + warning helpers

Add the per-channel anti-spam state and the two cooled warning helpers. Layer A (global guard) already exists inside `coinApiGet` from Task 3.

**Files:**
- Modify: `scripts/crypto/crypto.php`

- [ ] **Step 1: Add the per-channel tracking properties**

Inside the `crypto` class (near the other properties from Task 3 Step 2), add:

```php
    /** @var array<string, list<int>> */
    private array $chanRatelimit = [];

    /** @var array<string, int> */
    private array $chanWarn = [];

    /** @var array<string, int> */
    private array $apiWarnChan = [];
```

- [ ] **Step 2: Add the Layer B sliding-window check**

This mirrors `scripts/linktitles/linktitles.php:97-108` exactly. Returns true if the command is allowed (and records the attempt); false if the channel bucket is exceeded.

```php
    /**
     * Layer B: per-channel, combined sliding-window cooldown for all crypto commands.
     *
     * @param \Irc\Event\ChatEvent $args
     * @return bool true if allowed, false if rate-limited
     */
    private function checkChannelLimit(\Irc\Event\ChatEvent $args): bool
    {
        $max = (int)($this->config['crypto_rate_cmds'] ?? 3);
        $window = (int)($this->config['crypto_rate_secs'] ?? 20);
        $now = time();
        $chan = $args->chan;

        $this->chanRatelimit[$chan] = array_values(array_filter(
            $this->chanRatelimit[$chan] ?? [],
            fn(int $ts): bool => $now - $ts < $window
        ));

        if (count($this->chanRatelimit[$chan]) >= $max) {
            return false;
        }

        $this->chanRatelimit[$chan][] = $now;
        return true;
    }
```

- [ ] **Step 3: Add the two cooled warning helpers**

Two distinct messages with two independent per-channel cooldown trackers (neither suppresses the other). Both share the `crypto_warn_secs` cooldown duration.

```php
    private function spamWarn(\Irc\Event\ChatEvent $args, \Irc\Client $bot): void
    {
        $wc = (int)($this->config['crypto_warn_secs'] ?? 30);
        $chan = $args->chan;
        $now = time();
        if ($now - ($this->chanWarn[$chan] ?? 0) < $wc) {
            return;
        }
        $this->chanWarn[$chan] = $now;
        $bot->pm($args->chan, "\2Coin:\2 You're running crypto commands too fast, slow down.");
    }

    private function apiWarn(\Irc\Event\ChatEvent $args, \Irc\Client $bot): void
    {
        $wc = (int)($this->config['crypto_warn_secs'] ?? 30);
        $chan = $args->chan;
        $now = time();
        if ($now - ($this->apiWarnChan[$chan] ?? 0) < $wc) {
            return;
        }
        $this->apiWarnChan[$chan] = $now;
        $bot->pm($args->chan, "\2Coin:\2 API rate limit reached, try again in a moment.");
    }
```

- [ ] **Step 4: Verify static analysis**

Run: `composer phpstan && vendor/bin/psalm`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add scripts/crypto/crypto.php
git commit -m "feat(crypto): add per-channel cooldown and cooled warning helpers (#104)"
```

---

## Task 5: `showCoin()` core + the four shortcut commands

Add the unified `showCoin()` core (replaces the four duplicated bodies in `stocks.php:159-233`) and the four one-liner shortcut handlers. Behavior is byte-for-byte identical to today.

**Files:**
- Modify: `scripts/crypto/crypto.php`

- [ ] **Step 1: Add the needed `use` statements**

Add to the top of `scripts/crypto/crypto.php` (alongside the Task 3 uses):

```php
use async_get_exception;
use knivey\cmdr\attributes\Cmd;
```

- [ ] **Step 2: Add `showCoin()`**

`showCoin` self-handles `ApiRateLimitException` (it owns the price/chart fetches). It also catches `async_get_exception` exactly as the current handlers do.

```php
    public function showCoin(\Irc\Event\ChatEvent $args, \Irc\Client $bot, string $id): void
    {
        try {
            if ($this->server->throttle) {
                $bot->pm($args->chan, $this->getCoinPrice($id));
                return;
            }
            $chart = $this->getCoinChart($id);
        } catch (ApiRateLimitException $e) {
            $this->apiWarn($args, $bot);
            return;
        } catch (async_get_exception $e) {
            $bot->pm($args->chan, "Error getting data");
            return;
        }
        foreach ($chart as $l) {
            $bot->pm($args->chan, $l);
        }
    }
```

- [ ] **Step 3: Add the four shortcut command handlers**

Each does the Layer B check then delegates to `showCoin` with its hardcoded slug. (The unused `$cmdArgs` parameter is kept to match the existing handler signatures.)

```php
    #[Cmd("doge")]
    public function doge(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
    {
        if (!$this->checkChannelLimit($args)) {
            $this->spamWarn($args, $bot);
            return;
        }
        $this->showCoin($args, $bot, 'dogecoin');
    }

    #[Cmd("bch")]
    public function bch(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
    {
        if (!$this->checkChannelLimit($args)) {
            $this->spamWarn($args, $bot);
            return;
        }
        $this->showCoin($args, $bot, 'bitcoin-cash');
    }

    #[Cmd("eth")]
    public function eth(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
    {
        if (!$this->checkChannelLimit($args)) {
            $this->spamWarn($args, $bot);
            return;
        }
        $this->showCoin($args, $bot, 'ethereum');
    }

    #[Cmd("btc")]
    public function btc(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
    {
        if (!$this->checkChannelLimit($args)) {
            $this->spamWarn($args, $bot);
            return;
        }
        $this->showCoin($args, $bot, 'bitcoin');
    }
```

- [ ] **Step 4: Verify static analysis**

Run: `composer phpstan && vendor/bin/psalm`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add scripts/crypto/crypto.php
git commit -m "feat(crypto): add showCoin core and btc/eth/doge/bch shortcuts (#104)"
```

---

## Task 6: `!coin` and `!findcoin` lookup commands

Add the two new commands that use `searchCoins` + `matchCoin`. Both gate on Layer B and catch `ApiRateLimitException` around their search preamble (since `showCoin`'s internal catch does not cover the preamble).

**Files:**
- Modify: `scripts/crypto/crypto.php`

- [ ] **Step 1: Add the needed `use` statements**

Add to the top of `scripts/crypto/crypto.php`:

```php
use knivey\cmdr\attributes\Syntax;
use function knivey\tools\multi_array_padding;
```

- [ ] **Step 2: Add the `!coin` handler**

Uses variadic `<name>...` so multi-word queries like `!coin bitcoin cash` work. `matchCoin` picks the slug; `showCoin` does the rest.

```php
    #[Cmd("coin")]
    #[Syntax('<name>...')]
    public function coin(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
    {
        if (!$this->checkChannelLimit($args)) {
            $this->spamWarn($args, $bot);
            return;
        }

        $name = $cmdArgs['name'];
        try {
            $results = $this->searchCoins($name);
            $matched = self::matchCoin($name, $results);
        } catch (ApiRateLimitException $e) {
            $this->apiWarn($args, $bot);
            return;
        } catch (\Exception $e) {
            $bot->pm($args->chan, "\2Coin:\2 Error getting data");
            return;
        }

        if ($matched === null) {
            $bot->pm($args->chan, "\2Coin:\2 No coin found for '$name' (try !findcoin $name)");
            return;
        }

        $this->showCoin($args, $bot, $matched->id);
    }
```

- [ ] **Step 3: Add the `!findcoin` handler**

Renders a top-5 table, structurally identical to `findsymbol` (`stocks.php:128-157`).

```php
    #[Cmd("findcoin")]
    #[Syntax('<query>...')]
    public function findcoin(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
    {
        if (!$this->checkChannelLimit($args)) {
            $this->spamWarn($args, $bot);
            return;
        }

        $query = $cmdArgs['query'];
        try {
            $results = $this->searchCoins($query);
        } catch (ApiRateLimitException $e) {
            $this->apiWarn($args, $bot);
            return;
        } catch (\Exception $e) {
            $bot->pm($args->chan, "\2Coin:\2 Error getting data");
            return;
        }

        if (!isset($results[0])) {
            $bot->pm($args->chan, "\2Coin:\2 No results found");
            return;
        }

        $results = array_slice($results, 0, 5);
        $out = [["ID", "Name", "Symbol"]];
        foreach ($results as $r) {
            $out[] = [$r->id, $r->name, $r->symbol];
        }
        $out = multi_array_padding($out);
        foreach ($out as $line) {
            $bot->pm($args->chan, implode($line));
        }
    }
```

- [ ] **Step 4: Verify static analysis**

Run: `composer phpstan && vendor/bin/psalm`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add scripts/crypto/crypto.php
git commit -m "feat(crypto): add !coin and !findcoin lookup commands (#104)"
```

---

## Task 7: Register the `crypto` script in `lolbot.php`

Until this task the new class is unreferenced; this wires it into the router exactly like its siblings.

**Files:**
- Modify: `lolbot.php`

- [ ] **Step 1: Add the `use` import**

In `lolbot.php`, find line 79:

```php
use scripts\stocks\stocks;
```

Add immediately after it:

```php
use scripts\crypto\crypto;
```

- [ ] **Step 2: Instantiate and load the class**

Find the stocks registration block (currently `lolbot.php:188-189`):

```php
    $stocks = new stocks($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:stocks", [$logHandler]), $nicks, $chans, $router);
    $router->loadMethods($stocks);
```

Add immediately after it:

```php
    $crypto = new crypto($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:crypto", [$logHandler]), $nicks, $chans, $router);
    $router->loadMethods($crypto);
```

- [ ] **Step 3: Verify static analysis**

Run: `composer phpstan && vendor/bin/psalm`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add lolbot.php
git commit -m "feat(crypto): register crypto script in lolbot (#104)"
```

---

## Task 8: Trim `scripts/stocks/stocks.php` to finnhub-only

Now that crypto lives in its own script, remove all crypto code from `stocks.php`. Preserve the stock functionality and all its comments.

**Files:**
- Modify: `scripts/stocks/stocks.php`

- [ ] **Step 1: Remove the crypto-only `use` lines**

Delete these three lines from the top of `scripts/stocks/stocks.php` (currently lines 9-11):

```php
use draw;
use Amp\Cache\LocalCache;
use knivey\irctools;
```

Leave `use async_get_exception;`, `use knivey\cmdr\attributes\Cmd;`, `use knivey\cmdr\attributes\Syntax;`, `use JsonMapper\JsonMapperBuilder;`, and `use function knivey\tools\multi_array_padding;` — they are still used by the stock code.

- [ ] **Step 2: Remove the static cache property and `getCache()`**

Delete this entire block (currently lines 17-27), which is now unused since the crypto methods are gone:

```php
    /** @var LocalCache<mixed>|null */
    private static ?LocalCache $cache = null;

    /** @return LocalCache<mixed> */
    private static function getCache(): LocalCache
    {
        if (self::$cache === null) {
            self::$cache = new LocalCache();
        }
        return self::$cache;
    }
```

- [ ] **Step 3: Remove all crypto command methods and helpers**

Delete the contiguous block starting at the `#[Cmd("doge")]` attribute and ending at the closing brace of `getCoinChart()` (currently lines 159-336). This removes: `doge`, `bch`, `eth`, `btc`, `getCoinPrice`, and `getCoinChart`.

After deletion, the class body must end with the `findsymbol` method followed by the single class-closing `}`. The remaining methods are: `getKey`, `quote`, `symbolSearch`, `stock`, `findsymbol`.

- [ ] **Step 4: Verify the file parses and static analysis passes**

Run: `php -l scripts/stocks/stocks.php && composer phpstan && vendor/bin/psalm`
Expected: `No syntax errors detected` + PASS.

- [ ] **Step 5: Commit**

```bash
git add scripts/stocks/stocks.php
git commit -m "refactor(stocks): remove crypto code now that it lives in scripts/crypto (#104)"
```

---

## Task 9: Final verification

Confirm the whole feature is wired, formatted, type-clean, and tested.

- [ ] **Step 1: Run the full test suite**

Run: `composer test`
Expected: PASS — all existing tests plus the 5 new `MatchCoinTest` cases.

- [ ] **Step 2: Run both static analyzers**

Run: `composer phpstan && vendor/bin/psalm`
Expected: PASS with zero errors.

- [ ] **Step 3: Run the formatter**

Run: `vendor/bin/php-cs-fixer fix`
Expected: any style fixes applied to the new files; re-run `composer phpstan` after to confirm still green.

- [ ] **Step 4: Confirm the crypto class has all six commands**

Sanity-check that `scripts/crypto/crypto.php` defines six `#[Cmd(...)]` methods: `btc`, `eth`, `doge`, `bch`, `coin`, `findcoin`, and that `scripts/stocks/stocks.php` defines none of them (only `stock` and `findsymbol`).

- [ ] **Step 5: Commit any formatting changes (if any)**

```bash
git add -u
git commit -m "style(crypto): php-cs-fixer" || echo "nothing to commit"
```

---

## Self-Review Notes (resolved during planning)

- **Spec coverage:** Every spec section maps to a task — DTO/exception (T1), `matchCoin` (T2), cache/`coinApiGet`/price/chart/search (T3), Layer B + warnings (T4), `showCoin` + shortcuts (T5), `!coin`/`!findcoin` (T6), registration (T7), stocks trim (T8), config keys live in T4 (`checkChannelLimit`/`spamWarn`/`apiWarn`), testing in T2/T9.
- **Type consistency:** `matchCoin` is `public static` everywhere (T2 def, T6 `self::matchCoin` call). `coinApiGet` returns `string` (T3) and is the only replacement for `async_get_contents`. `ApiRateLimitException` is caught in `showCoin` (T5) and in the `!coin`/`!findcoin` preambles (T6) — never in the shortcut handlers (they delegate to `showCoin`).
- **Comment preservation:** All original comments in `getCoinChart` (`//box`, `// api gives hourly...`, `//hope this works out lol`) are carried over verbatim in T3 Step 5, per the project rule.
- **findcoin rendering:** The spec text mentions a `\2Coin:\2` prefix; the actual `findsymbol` reference renders a bare padded table with no per-line prefix. This plan mirrors `findsymbol` exactly (no per-line prefix) for a clean table.
