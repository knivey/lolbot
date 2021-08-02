<?php

use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use Symfony\Component\Yaml\Yaml;

//For now just using a config file channel: casturl
//channels should be lowercase without #
//cast url should be normal url to click (https://owncast.local/)

#[Cmd("owncast", "popcorn")]
#[CallWrap("Amp\asyncCall")]
function owncast($args, \Irc\Client $bot, \knivey\cmdr\Request $req)
{
    if(!file_exists(__DIR__.'/casts.yaml')) {
        echo "casts.yaml doernt exist\n";
        return;
    }
    $casts = Yaml::parseFile(__DIR__.'/casts.yaml');
    var_dump($casts);
    $chan = strtolower(ltrim($args->chan, '#'));
    var_dump($chan);
    if(!isset($casts[$chan]))
        return;
    try {
        $url = "{$casts[$chan]}/api/status";
        $surl = $casts[$chan];
        $body = yield async_get_contents($url);
        $j = json_decode($body, true);
        if(!$j['online']) {
            $bot->pm($args->chan, "$surl - Stream is offline now.");
            return;
        }
        $line = "$surl ";
        //seems like stream proxied through nginx always shows no viewsers so just hide this part if 0
        if($j['viewerCount'] > 0)
            $line .= " {$j['viewerCount']} viewers, ";
        $line .= "streaming: {$j['streamTitle']}";
        $bot->pm($args->chan, $line);
    } catch (\async_get_exception $e) {
        $bot->pm($args->chan, "owncast exception: {$e->getIRCMsg()}");
    } catch (\Exception $e) {
        $bot->pm($args->chan, "owncast exception: {$e->getMessage()}");
    }
}