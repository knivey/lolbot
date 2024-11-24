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
        if (!isset($this->config['finnhub'])) {
            $this->logger->warning("finnhub key not set in config");
            return false;
        }
        return $this->config['finnhub'];
    }

    public function quote(string $symbol): quote {
        if (false === $key = $this->getKey()) {
            throw new \Exception("finnhub key not set");
        }
        $url = "https://finnhub.io/api/v1/quote?symbol=$symbol&token=$key";
        $body = \async_get_contents($url);

        $mapper = JsonMapperBuilder::new()
        ->withTypedPropertiesMiddleware()
        ->withAttributesMiddleware()
        ->build();
        
        $out = $mapper->mapToClassFromString($body, quote::class);
        if(!$out->verify())
            throw new \Exception("quote lookup failed");
        return $out;
    }

    /**
     * 
     * @param string $keyword 
     * @return list<symbol>
     * @throws async_get_exception 
     */
    public function symbolSearch(string $keyword): array {
            if (false === $key = $this->getKey()) {
                throw new \Exception("finnhub key not set");
            }
            $keyword = rawurlencode($keyword);
            $url = "https://finnhub.io/api/v1/search?q=$keyword&exchange=US&token=$key";
            $body = \async_get_contents($url);
            $j = json_decode($body);
            if(!isset($j->count) || !is_numeric($j->count)) {
                var_dump($j);
                throw new \Exception("API error");
            }
            if($j->count < 1) {
                throw new \Exception("No results for search");
            }
            $mapper = JsonMapperBuilder::new()
                ->withTypedPropertiesMiddleware()
                ->withAttributesMiddleware()
                ->build();
            $out = $mapper->mapToClassArray($j->result, symbol::class);
            return $out;
    }

    #[Cmd("stock")]
    #[Syntax('<symbol>')]
    public function stock($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (false === $this->getKey()) {
            return;
        }

        try {
            $t = $this->symbolSearch($cmdArgs['symbol']);
            if(!isset($t[0]))
                throw new \Exception("Error getting symbol info");
            foreach($t as $v) {
                if(!$v->verify())
                    continue;
                if(strtoupper($v->symbol) == strtoupper($cmdArgs['symbol'])) {
                    $t = $v;
                    break;
                }
            }
            if(is_array($t))
                throw new \Exception("no matching symbols found");
            $q = $this->quote($t->symbol);
            
            $c = "";
            $co = "\x0F";
            if ($q->changePercent > 0) {
                $c = "\x0309";
            } else {
                $c = "\x0304";
            }

            $time = (new \DateTime("@{$q->time}"))->format(DATE_RSS);

            $bot->pm($args->chan, "$t->symbol ($t->description) at $time: $q->price USD {$c}$q->change{$co} ({$c}$q->changePercent%{$co}) High: $q->high Low: $q->low");
        } catch (\async_get_exception $error) {
            echo $error;
            $bot->pm($args->chan, "\2Stocks:\2 {$error->getIRCMsg()}");
        } catch (\Exception $error) {
            echo $error->getMessage();
            $bot->pm($args->chan, "\2Stocks:\2 {$error->getMessage()}");
        }
    }

    #[Cmd("findsymbol")]
    #[Syntax('<query>')]
    public function findsymbol($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (false === $this->getKey()) {
            return;
        }

        try {
            $symbols = $this->symbolSearch($cmdArgs['query']);
            if(!isset($symbols[0]))
                throw new \Exception("No results found");
            
            $symbols = array_slice($symbols, 0, 5);
            $out = [["Symbol", "Description", "Type"]];
            foreach($symbols as $t) {
                $out[] = [$t->symbol, $t->description, $t->type];
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
