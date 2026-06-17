<?php

namespace scripts\stocks;

use async_get_exception;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;
use JsonMapper\JsonMapperBuilder;

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
    public function stock(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
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
    public function findsymbol(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
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

}
