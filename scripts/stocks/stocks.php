<?php

namespace scripts\stocks;

use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;

use draw;
use knivey\irctools;


class stocks extends \scripts\script_base
{
    //#[Cmd("stock")]
    #[Syntax('<query>')]
    #[CallWrap("Amp\asyncCall")]
    public function stock($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        global $config;
        if (!isset($config['iexKey'])) {
            echo "iexKey not set in config\n";
            return;
        }

        $query = rawurlencode($cmdArgs['query']);
        $url = "https://cloud.iexapis.com/stable/stock/$query/quote?token=" . $config['iexKey'] . '&displayPercent=true';
        try {
            $body = yield \async_get_contents($url);
            $j = json_decode($body, true);

            $change = $j['change'];
            if ($change > 0) {
                $change = "\x0309$change\x0F";
            } else {
                $change = "\x0304$change\x0F";
            }

            if ($j['isUSMarketOpen'] || !($j['extendedPrice'] ?? false)) {
                $bot->pm($args->chan, "$j[symbol] ($j[companyName]) $j[latestPrice] $j[currency] $change ($j[changePercent]%)");
            } else {
                $eChange = $j['extendedChange'];
                if ($eChange > 0) {
                    $eChange = "\x0309$eChange\x0F";
                } else {
                    $eChange = "\x0304$eChange\x0F";
                }
                $bot->pm($args->chan, "$j[symbol] ($j[companyName]) [Close: $j[latestPrice] $j[currency] $change ($j[changePercent]%)] [Extended: $j[extendedPrice] $j[currency] $eChange ($j[extendedChangePercent]%)]");
            }
        } catch (\async_get_exception $error) {
            echo $error;
            $bot->pm($args->chan, "\2Stocks:\2 {$error->getIRCMsg()}");
        } catch (\Exception $error) {
            echo $error->getMessage();
            $bot->pm($args->chan, "\2Stocks:\2 {$error->getMessage()}");
        }
    }

    #[Cmd("doge")]
    #[CallWrap("\Amp\asyncCall")]
    public function doge($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        try {
            if ($this->server->throttle) {
                $bot->pm($args->chan, yield self::getCoinPrice('dogecoin'));
                return;
            }

            $chart = yield from self::getCoinChart("dogecoin");
        } catch (\Exception $e) {
            $bot->pm($args->chan, "Error getting data");
            return;
        }
        foreach ($chart as $l) {
            $bot->pm($args->chan, $l);
        }
    }

    #[Cmd("bch")]
    #[CallWrap("\Amp\asyncCall")]
    public function bch($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        try {
            if ($this->server->throttle) {
                $bot->pm($args->chan, yield self::getCoinPrice('bitcoin-cash'));
                return;
            }

            $chart = yield from self::getCoinChart("bitcoin-cash");
        } catch (\Exception $e) {
            $bot->pm($args->chan, "Error getting data");
            return;
        }
        foreach ($chart as $l) {
            $bot->pm($args->chan, $l);
        }
    }

    #[Cmd("eth")]
    #[CallWrap("\Amp\asyncCall")]
    public function eth($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        try {
            if ($this->server->throttle) {
                $bot->pm($args->chan, yield self::getCoinPrice('ethereum'));
                return;
            }

            $chart = yield from self::getCoinChart("ethereum");
        } catch (\Exception $e) {
            $bot->pm($args->chan, "Error getting data");
            return;
        }
        foreach ($chart as $l) {
            $bot->pm($args->chan, $l);
        }
    }

    #[Cmd("btc")]
    #[CallWrap("\Amp\asyncCall")]
    public function btc($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        try {
            if ($this->server->throttle) {
                $bot->pm($args->chan, yield self::getCoinPrice('bitcoin'));
                return;
            }

            $chart = yield from self::getCoinChart("bitcoin");
        } catch (\Exception $e) {
            $bot->pm($args->chan, "Error getting data");
            return;
        }
        foreach ($chart as $l) {
            $bot->pm($args->chan, $l);
        }
    }

    public function getCoinPrice($coin)
    {
        return \Amp\call(function () use ($coin) {
            $json = json_decode(yield async_get_contents("https://api.coingecko.com/api/v3/simple/price?ids=$coin&vs_currencies=usd&include_24hr_change=true"));
            //hope this works out lol
            $current = $json->$coin->usd;
            return "Current price for $coin: $current USD";
        });
    }

    public function getCoinChart($coin)
    {
        $data = yield async_get_contents("https://api.coingecko.com/api/v3/coins/$coin/market_chart?vs_currency=usd&days=7");
        $json = json_decode($data);

        $w = 86; // api gives hourly for 7 days cut out half those data points and give room for box
        $h = 26;
        $canvas = draw\Canvas::createBlank(86, 26, true);

        $chart = array_fill(0, $h, array_fill(0, $w, ' '));

        //box
        $canvas->drawLine(0,1,0,24, new draw\Color(14));
        $canvas->drawLine(85,1,85,24, new draw\Color(14));
        $canvas->drawLine(1,0,84,0, new draw\Color(14));
        $canvas->drawLine(1,25,84,25, new draw\Color(14));
        $canvas->drawPoint(0,0, new draw\Color(15));
        $canvas->drawPoint(0,25, new draw\Color(15));
        $canvas->drawPoint(85,25, new draw\Color(15));
        $canvas->drawPoint(85,0, new draw\Color(15));


        $prices = [];
        $cnt = 0;
        foreach ($json->prices as $p) {
            if ($cnt++ % 2 == 0) {
                continue;
            }
            $prices[] = $p[1];
        }

        $min = min($prices);
        $max = max($prices);
        $rng = $max - $min;

        $i = 1;
        $ly = 0;
        foreach ($prices as $p) {
            $y = $h - 2 - (int)round((($p - $min) / $rng) * ($h - 3));
            if($i != 1)
                $canvas->drawLine($i-1,$ly,$i,$y, new draw\Color(4));
            $ly = $y;
            $i++;
        }

        $json = json_decode(yield async_get_contents("https://api.coingecko.com/api/v3/simple/price?ids=$coin&vs_currencies=usd&include_24hr_change=true"));
        $out = explode("\n", (string)$canvas);
        foreach($out as &$line)
            $line = irctools\fixColors($line);

        //hope this works out lol
        $current = $json->$coin->usd;
        $out[] = "7 day min price: $min USD";
        $out[] = "7 day max price: $max USD";
        $out[] = "Current price: $current USD";
        return $out;
    }

}
