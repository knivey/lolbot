<?php
namespace scripts\stocks;

use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;


#[Cmd("stock")]
#[Syntax('<query>')]
#[CallWrap("Amp\asyncCall")]
function stock($args, \Irc\Client $bot, \knivey\cmdr\Request $req)
{
    global $config;
    if(!isset($config['iexKey'])) {
        echo "iexKey not set in config\n";
        return;
    }

    $query = urlencode(htmlentities($req->args['query']));
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

        $bot->pm($args->chan, "$j[symbol] ($j[companyName]) $j[latestPrice] $change ($j[changePercent]%)");
    } catch (\async_get_exception $error) {
        echo $error;
        $bot->pm($args->chan, "\2Stocks:\2 {$error->getIRCMsg()}");
    }
}

#[Cmd("doge")]
#[CallWrap("\Amp\asyncCall")]
function doge($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    try {
        $data = yield async_get_contents("https://api.coingecko.com/api/v3/coins/dogecoin/market_chart?vs_currency=usd&days=7");
    } catch (\Exception $e) {
        $bot->pm($args->chan, "Error getting data");
        return;
    }
    $json = json_decode($data);

    $w = 86; // api gives hourly for 7 days cut out half those data points and give room for box
    $h = 26;

    $chart = array_fill(0, $h, array_fill(0, $w, ' '));

    //box
    foreach($chart as &$l) {
        $l[0] = "|";
        $l[$w-1] = "|";
    }
    for($i=1;$i<$w-1;$i++) {
        $chart[0][$i] = "-";
        $chart[$h-1][$i] = "-";
    }

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

    echo "$min $max\n";

    $i = 1;
    foreach($prices as $p) {
        $y = floor( (($p - $min) / $rng) * ($h-3) );
        $chart[$y+1][$i] = 'x';
        $i++;
    }
    $chart = array_reverse($chart);

    foreach($chart as $l) {
        $bot->msg($args->chan, implode('', $l));
    }
    try {
        $json = json_decode(yield async_get_contents("https://api.coingecko.com/api/v3/simple/price?ids=dogecoin&vs_currencies=usd&include_24hr_change=true"));
        $current = $json->dogecoin->usd;
    } catch (\Exception $e) {
        $bot->pm($args->chan, "Error getting current price data");
        return;
    }
    $bot->msg($args->chan, "7 day min price: $min USD");
    $bot->msg($args->chan, "7 day max price: $max USD");
    $bot->msg($args->chan, "Current price: $current USD");
}