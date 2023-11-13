<?php

namespace scripts\bing;

use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Desc;
use knivey\cmdr\attributes\Option;
use knivey\cmdr\attributes\Options;
use knivey\cmdr\attributes\Syntax;

$ratelimit = 0;
$warned = false;

#[Cmd("bing", "brave")]
#[Syntax('<query>...')]
#[Desc("Search brave.com")]
#[CallWrap("Amp\asyncCall")]
#[Option("--amt", "How many results to show")]
function brave($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    global $config, $warned, $ratelimit;
    if (!isset($config['braveKey'])) {
        echo "braveKey not set in config\n";
        return;
    }

    if (time() < $ratelimit) {
        if (!$warned)
            $bot->pm($args->chan, "Whoa slow down!");
        $warned = true;
        return;
    }
    $warned = false;
    $ratelimit = time() + 2;

    $query = urlencode($cmdArgs['query']);
    $url = "https://api.search.brave.com/res/v1/web/search?q=$query&country=US&safesearch=off&spellcheck=false&result_filter=web";
    try {
        $headers = ['X-Subscription-Token' => $config['braveKey']];
        $body = yield async_get_contents($url, $headers);
        $j = json_decode($body);

        if (!isset($j->web->results) || count($j->web->results) < 1) {
            $bot->pm($args->chan, "\2Brave:\2 No Results");
            return;
        }
        $end = 1;
        if($cmdArgs->optEnabled("--amt")) {
            $end = $cmdArgs->getOpt("--amt");
            if($end < 1 || $end > 10) {
                $bot->pm($args->chan, "\2Brave:\2 --amt should be from 1 to 10");
                return;
            }
        }

        for ($i = 0; $i <= $end-1; $i++) {
            if(!isset($j->web->results[$i])) {
                $bot->pm($args->chan, "End of results :(");
                break;
            }
            $res = $j->web->results[$i];
            $bot->pm($args->chan, "\2Brave web search:\2 $res->url $res->title -- $res->description");
        }
    } catch (\async_get_exception $error) {
        echo $error;
        $bot->pm($args->chan, "\2Brave:\2 {$error->getIRCMsg()}");
    } catch (\Exception $error) {
        echo $error->getMessage();
        $bot->pm($args->chan, "\2Brave:\2 {$error->getMessage()}");
    }
}