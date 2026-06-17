<?php

namespace scripts\crypto;

use scripts\script_base;
use Amp\Cache\LocalCache;
use JsonMapper\JsonMapperBuilder;
use draw;
use knivey\irctools;
use async_get_exception;
use knivey\cmdr\attributes\Cmd;

class crypto extends script_base
{
    /** @var LocalCache<mixed>|null */
    private static ?LocalCache $cache = null;

    private static int $apiRatelimit = 0;

    /** @var array<string, list<int>> */
    private array $chanRatelimit = [];

    /** @var array<string, int> */
    private array $chanWarn = [];

    /** @var array<string, int> */
    private array $apiWarnChan = [];

    /** @return LocalCache<mixed> */
    private static function getCache(): LocalCache
    {
        if (self::$cache === null) {
            self::$cache = new LocalCache();
        }
        return self::$cache;
    }

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

    /**
     * Layer B: per-channel, combined sliding-window cooldown for all crypto commands.
     *
     * @param \Irc\Event\ChatEvent $args
     * @return bool true if allowed, false if rate-limited
     */
    private function checkChannelLimit(\Irc\Event\ChatEvent $args): bool
    {
        $maxRaw = $this->config['crypto_rate_cmds'] ?? 3;
        $max = is_int($maxRaw) ? $maxRaw : 3;
        $windowRaw = $this->config['crypto_rate_secs'] ?? 20;
        $window = is_int($windowRaw) ? $windowRaw : 20;
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

    private function spamWarn(\Irc\Event\ChatEvent $args, \Irc\Client $bot): void
    {
        $wcRaw = $this->config['crypto_warn_secs'] ?? 30;
        $wc = is_int($wcRaw) ? $wcRaw : 30;
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
        $wcRaw = $this->config['crypto_warn_secs'] ?? 30;
        $wc = is_int($wcRaw) ? $wcRaw : 30;
        $chan = $args->chan;
        $now = time();
        if ($now - ($this->apiWarnChan[$chan] ?? 0) < $wc) {
            return;
        }
        $this->apiWarnChan[$chan] = $now;
        $bot->pm($args->chan, "\2Coin:\2 API rate limit reached, try again in a moment.");
    }

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

        if (!$chartJson instanceof \stdClass) {
            throw new \Exception("API error");
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

        if ($prices === []) {
            throw new \Exception("No price data");
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
        if (!is_object($j) || !isset($j->coins) || !is_array($j->coins)) {
            throw new \Exception("API error");
        }

        $mapper = JsonMapperBuilder::new()
            ->withTypedPropertiesMiddleware()
            ->withAttributesMiddleware()
            ->build();
        $out = $mapper->mapToClassArray($j->coins, coinSearchResult::class);

        self::getCache()->set($cacheKey, $out, 900);
        return array_values($out);
    }

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
}
