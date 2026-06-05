# Cache Crypto Prices Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add in-memory caching to the `stocks` class crypto price/chart methods using `amphp/cache` `LocalCache` to prevent CoinGecko API rate limiting.

**Architecture:** A static `LocalCache` property on the `stocks` class caches decoded API responses with 15-minute TTL. Both `getCoinPrice()` and `getCoinChart()` check the cache before making HTTP calls. The cache is shared across all bot connections since it's static.

**Tech Stack:** PHP 8.1+, `amphp/cache` v2 (`Amp\Cache\LocalCache`), existing CoinGecko API calls

---

### Task 1: Add LocalCache to getCoinPrice()

**Files:**
- Modify: `scripts/stocks/stocks.php` (lines 5-10 imports, line 14 class declaration, lines 222-228 `getCoinPrice`)

- [ ] **Step 1: Add import and static cache property**

Add the `LocalCache` import and a static property to the class. In `scripts/stocks/stocks.php`:

After the existing `use` statements (around line 10), add:
```php
use Amp\Cache\LocalCache;
```

Inside the class body (after line 15's opening brace), add the static property:
```php
private static ?LocalCache $cache = null;

private static function getCache(): LocalCache
{
    if (self::$cache === null) {
        self::$cache = new LocalCache();
    }
    return self::$cache;
}
```

- [ ] **Step 2: Modify getCoinPrice() to use cache**

Replace the current `getCoinPrice()` method (lines 222-228) with:

```php
public function getCoinPrice(string $coin): string
{
    $cacheKey = "price:$coin";
    $cached = self::getCache()->get($cacheKey);
    if ($cached !== null) {
        $current = $cached;
    } else {
        $json = json_decode(async_get_contents("https://api.coingecko.com/api/v3/simple/price?ids=$coin&vs_currencies=usd&include_24hr_change=true"));
        $current = $json->$coin->usd;
        self::getCache()->set($cacheKey, $current, 900);
    }
    return "Current price for $coin: $current USD";
}
```

- [ ] **Step 3: Verify syntax**

Run: `php -l scripts/stocks/stocks.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add scripts/stocks/stocks.php
git commit -m "feat: cache crypto prices with 15-min TTL using LocalCache"
```

---

### Task 2: Add LocalCache to getCoinChart()

**Files:**
- Modify: `scripts/stocks/stocks.php` (lines 233-303 `getCoinChart`)

- [ ] **Step 1: Modify getCoinChart() to cache chart data and reuse price cache**

Replace the current `getCoinChart()` method (lines 233-303) with:

```php
public function getCoinChart(string $coin): array
{
    $chartCacheKey = "chart:$coin";
    $priceCacheKey = "price:$coin";

    $chartJson = self::getCache()->get($chartCacheKey);
    if ($chartJson === null) {
        $chartJson = json_decode(async_get_contents("https://api.coingecko.com/api/v3/coins/$coin/market_chart?vs_currency=usd&days=7"));
        self::getCache()->set($chartCacheKey, $chartJson, 900);
    }

    $w = 86;
    $h = 30;
    $canvas = draw\Canvas::createBlank($w, $h, true);

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
        $json = json_decode(async_get_contents("https://api.coingecko.com/api/v3/simple/price?ids=$coin&vs_currencies=usd&include_24hr_change=true"));
        $current = $json->$coin->usd;
        self::getCache()->set($priceCacheKey, $current, 900);
    }

    $out = explode("\n", (string)$canvas);
    foreach($out as &$line)
        $line = irctools\fixColors($line);

    $out[] = "7 day min price: $min USD";
    $out[] = "7 day max price: $max USD";
    $out[] = "Current price: $current USD";
    return $out;
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l scripts/stocks/stocks.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Run static analysis**

Run: `composer phpstan`
Expected: PASS (no new errors)

- [ ] **Step 4: Commit**

```bash
git add scripts/stocks/stocks.php
git commit -m "feat: cache crypto chart data and reuse price cache in getCoinChart"
```

---

### Task 3: Final verification

- [ ] **Step 1: Run full test suite**

Run: `composer test`
Expected: All existing tests pass

- [ ] **Step 2: Run phpstan**

Run: `composer phpstan`
Expected: No new errors
