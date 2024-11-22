<?php

namespace scripts\stocks;

use async_get_exception;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;
use JsonMapper\JsonMapperBuilder;
use draw;
use knivey\irctools;

use function knivey\tools\multi_array_padding;

class stocks extends \scripts\script_base
{
    private function getKey(): string|false {
        if (!isset($this->config['alphavantage'])) {
            $this->logger->warning("alphavantage key not set in config");
            return false;
        }
        return $this->config['alphavantage'];
    }

    /**
     * 
     * @param string $symbol 
     * @return lastClose
     */
    public function lastClose(string $symbol): lastClose {
            if (false === $key = $this->getKey()) {
                throw new \Exception("alphavantage key not set");
            }
            $symbol = rawurlencode($symbol);
            $url = "https://www.alphavantage.co/query?function=GLOBAL_QUOTE&symbol=$symbol&apikey=$key";
            $body = \async_get_contents($url);
            $mapper = JsonMapperBuilder::new()
                ->withTypedPropertiesMiddleware()
                ->withAttributesMiddleware()
                ->build();
            $lastClose = new lastClose();
            $j = json_decode($body);
            if(!isset($j->{'Global Quote'})) {
                var_dump($j);
                throw new \Exception("Symbol $symbol not found or api error");
            }
            $mapper->mapObject($j->{'Global Quote'}, $lastClose);
            if(!isset($lastClose->symbol))
                throw new \Exception("Symbol $symbol not found");
            return $lastClose;
    }

    /**
     * 
     * @param string $keyword 
     * @return list<ticker>
     * @throws async_get_exception 
     */
    public function tickerSearch(string $keyword): array {
            if (false === $key = $this->getKey()) {
                throw new \Exception("alphavantage key not set");
            }
            $keyword = rawurlencode($keyword);
            $url = "https://www.alphavantage.co/query?function=SYMBOL_SEARCH&keywords=$keyword&apikey=$key";
            $body = \async_get_contents($url);
            $j = json_decode($body);
            if(!isset($j->bestMatches)) {
                throw new \Exception("API error");
            }
            $j = $j->bestMatches;
            $mapper = JsonMapperBuilder::new()
                ->withTypedPropertiesMiddleware()
                ->withAttributesMiddleware()
                ->build();
            return $mapper->mapToClassArray($j, ticker::class);
    }

    #[Cmd("stock")]
    #[Syntax('<symbol>')]
    public function stock($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (false === $this->getKey()) {
            return;
        }

        try {
            $q = $this->lastClose($cmdArgs['symbol']);
            $t = $this->tickerSearch($cmdArgs['symbol']);
            if(!isset($t[0]))
                throw new \Exception("Error getting ticker info");
            $t = $t[0];
            var_dump($t);
            if ($q->changeP > 0) {
                $change = "\x0309$q->change\x0F";
            } else {
                $change = "\x0304$q->change\x0F";
            }

            $bot->pm($args->chan, "$q->symbol ($t->name) Last Close ($q->date): $q->price $t->currency $change ($q->changeP) High: $q->high Low: $q->low");
        } catch (\async_get_exception $error) {
            echo $error;
            $bot->pm($args->chan, "\2Stocks:\2 {$error->getIRCMsg()}");
        } catch (\Exception $error) {
            echo $error->getMessage();
            $bot->pm($args->chan, "\2Stocks:\2 {$error->getMessage()}");
        }
    }

    #[Cmd("findticker")]
    #[Syntax('<query>')]
    public function findticker($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (false === $this->getKey()) {
            return;
        }

        try {
            $tickers = $this->tickerSearch($cmdArgs['query']);
            if(!isset($tickers[0]))
                throw new \Exception("No results found");
            
            $tickers = array_slice($tickers, 0, 10);
            $out = [["Symbol", "Name", "Type", "Region", "Currency"]];
            foreach($tickers as $t) {
                $out[] = [$t->symbol, $t->name, $t->type, $t->region, $t->currency];
            }
            $out = multi_array_padding($out);
            foreach($out as $line) {
                $bot->pm($args->chan, implode($line));
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
    public function doge($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        try {
            if ($this->server->throttle) {
                $bot->pm($args->chan, self::getCoinPrice('dogecoin'));
                return;
            }

            $chart = self::getCoinChart("dogecoin");
        } catch (\Exception $e) {
            $bot->pm($args->chan, "Error getting data");
            return;
        }
        foreach ($chart as $l) {
            $bot->pm($args->chan, $l);
        }
    }

    #[Cmd("bch")]
    public function bch($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        try {
            if ($this->server->throttle) {
                $bot->pm($args->chan, self::getCoinPrice('bitcoin-cash'));
                return;
            }

            $chart = self::getCoinChart("bitcoin-cash");
        } catch (\Exception $e) {
            $bot->pm($args->chan, "Error getting data");
            return;
        }
        foreach ($chart as $l) {
            $bot->pm($args->chan, $l);
        }
    }

    #[Cmd("eth")]
    public function eth($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        try {
            if ($this->server->throttle) {
                $bot->pm($args->chan, self::getCoinPrice('ethereum'));
                return;
            }

            $chart = self::getCoinChart("ethereum");
        } catch (\Exception $e) {
            $bot->pm($args->chan, "Error getting data");
            return;
        }
        foreach ($chart as $l) {
            $bot->pm($args->chan, $l);
        }
    }

    #[Cmd("btc")]
    public function btc($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        try {
            if ($this->server->throttle) {
                $bot->pm($args->chan, self::getCoinPrice('bitcoin'));
                return;
            }

            $chart = self::getCoinChart("bitcoin");
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
        $json = json_decode(async_get_contents("https://api.coingecko.com/api/v3/simple/price?ids=$coin&vs_currencies=usd&include_24hr_change=true"));
        //hope this works out lol
        $current = $json->$coin->usd;
        return "Current price for $coin: $current USD";
    }

    public function getCoinChart($coin)
    {
        $data = async_get_contents("https://api.coingecko.com/api/v3/coins/$coin/market_chart?vs_currency=usd&days=7");
        $json = json_decode($data);

        $w = 86; // api gives hourly for 7 days cut out half those data points and give room for box
        $h = 30;
        $canvas = draw\Canvas::createBlank($w, $h, true);

        //box

        $canvas->drawLine(      0,        0,       0, $h - 1, new draw\Color(14));
        $canvas->drawLine( $w - 1,        0,  $w - 1, $h - 1, new draw\Color(14));
        $canvas->drawLine(      0,        0,  $w - 1,      0, new draw\Color(14));
        $canvas->drawLine(      0,   $h - 1,  $w - 1, $h - 1, new draw\Color(14));
        for($x = 0; $x < $w; $x+=12) {
            $canvas->drawPoint($x, 0, new draw\Color(15));
            $canvas->drawPoint($x, $h - 1, new draw\Color(15));
        }




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
                $canvas->drawLine($i+1,$ly,$i,$y, $color);
            }
            $ly = $y;
            $i--;
        }

        $json = json_decode(async_get_contents("https://api.coingecko.com/api/v3/simple/price?ids=$coin&vs_currencies=usd&include_24hr_change=true"));
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
