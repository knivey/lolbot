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