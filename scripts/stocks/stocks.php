<?php
namespace scripts\stocks;

use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;


//#[Cmd("stock")]
#[Syntax('<query>')]
#[CallWrap("Amp\asyncCall")]
function stock($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    global $config;
    if(!isset($config['iexKey'])) {
        echo "iexKey not set in config\n";
        return;
    }

    $query = rawurlencode($cmdArgs['query']);
    $url = "https://cloud.iexapis.com/stable/stock/$query/quote?token=" . $config['iexKey'] . '&displayPercent=true';
    try {
        $body = yield \async_get_contents($url);
        $j = json_decode($body, true);

        $change = $j['change'];
        if($change > 0) {
            $change = "\x0309$change\x0F";
        } else {
            $change = "\x0304$change\x0F";
        }

        if($j['isUSMarketOpen'] || !($j['extendedPrice'] ?? false))
            $bot->pm($args->chan, "$j[symbol] ($j[companyName]) $j[latestPrice] $j[currency] $change ($j[changePercent]%)");
        else {
            $eChange = $j['extendedChange'];
            if($eChange > 0) {
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
function doge($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    global $config;
    try {
        if(($config['throttle']??true)) {
            $bot->pm($args->chan, yield getCoinPrice('dogecoin'));
            return;
        }

        $chart = yield from getCoinChart("dogecoin");
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
function bch($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    global $config;
    try {
        if(($config['throttle']??true)) {
            $bot->pm($args->chan, yield getCoinPrice('bitcoin-cash'));
            return;
        }

        $chart = yield from getCoinChart("bitcoin-cash");
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
function eth($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    global $config;
    try {
        if(($config['throttle']??true)) {
            $bot->pm($args->chan, yield getCoinPrice('ethereum'));
            return;
        }

        $chart = yield from getCoinChart("ethereum");
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
function btc($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    global $config;
    try {
        if(($config['throttle']??true)) {
            $bot->pm($args->chan, yield getCoinPrice('bitcoin'));
            return;
        }

        $chart = yield from getCoinChart("bitcoin");
    } catch (\Exception $e) {
        $bot->pm($args->chan, "Error getting data");
        return;
    }
    foreach ($chart as $l) {
        $bot->pm($args->chan, $l);
    }
}

function getCoinPrice($coin) {
    return \Amp\call(function () use ($coin) {
        $json = json_decode(yield async_get_contents("https://api.coingecko.com/api/v3/simple/price?ids=$coin&vs_currencies=usd&include_24hr_change=true"));
        //hope this works out lol
        $current = $json->$coin->usd;
        return "Current price for $coin: $current USD";
    });
}

function getCoinChart($coin) {
    $data = yield async_get_contents("https://api.coingecko.com/api/v3/coins/$coin/market_chart?vs_currency=usd&days=7");
    $json = json_decode($data);

    $w = 86; // api gives hourly for 7 days cut out half those data points and give room for box
    $h = 26;

    $chart = array_fill(0, $h, array_fill(0, $w, ' '));

    //box
    foreach($chart as &$l) {
        $l[0] = "┃";
        $l[$w-1] = "┃";
    }
    for($i=0;$i<$w;$i++) {
        $chart[0][$i] = "━";
        $chart[$h-1][$i] = "━";
    }
    $chart[0][0] = "┏";
    $chart[$h-1][0] = "┗";
    $chart[$h-1][$w-1] = "┛";
    $chart[0][$w-1] = "┓";


    $prices = [];
    $cnt=0;
    foreach($json->prices as $p) {
        if($cnt++ %2 == 0)
            continue;
        $prices[] = $p[1];
    }

    $min = min($prices);
    $max = max($prices);
    $rng = $max-$min;

    $i = 1;
    foreach($prices as $p) {
        $y = $h - 2 - floor( (($p - $min) / $rng) * ($h-3) );
        $chart[$y][$i] = 'x';
        $i++;
    }

    $out = [];
    //for some reason a foreach here duplicated second to last line and showed it as the last line, php bug?
    for($k=0; $k<count($chart); $k++) {
        $out[] = implode('', $chart[$k]);
    }
    $json = json_decode(yield async_get_contents("https://api.coingecko.com/api/v3/simple/price?ids=$coin&vs_currencies=usd&include_24hr_change=true"));
    //hope this works out lol
    $current = $json->$coin->usd;
    $out[] = "7 day min price: $min USD";
    $out[] = "7 day max price: $max USD";
    $out[] = "Current price: $current USD";
    return $out;
}