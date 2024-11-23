<?php
namespace artbot_scripts;

use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Desc;
use knivey\cmdr\attributes\Options;

#[Cmd("artfart")]
#[Desc("play a random artfart")]
#[Options("--rainbow", "--rnb")]
function artfart($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
{
    $filename = __DIR__.'/farts.xml';
    if(!file_exists($filename)) {
        $bot->pm($args->chan, "\2artfart:\2 artfart db not found");
        return;
    }
    try {
        $xml = simplexml_load_file($filename);
        if($xml === false)
            throw new \Exception("couldn't understand artfart db");

        //can't use array_rand on xml element
        $fart = $xml->farts->fart[random_int(0, count($xml->farts->fart) - 1)];
        $title = (string)$fart->full . ' - ' . (string)$fart->author;
        $fart = (string)$fart->content;
        if($cmdArgs->optEnabled('--rnb') || $cmdArgs->optEnabled('--rainbow'))
            $fart = \knivey\ircTools\diagRainbow($fart);
        $fart = explode("\n", $fart);
        $fart = array_map(rtrim(...), $fart);
        array_unshift($fart, $title);
        pumpToChan($args->chan, $fart);
    } catch (\Exception $error) {
        echo $error->getMessage();
        $bot->pm($args->chan, "\2artfart:\2 {$error->getMessage()}");
    }
}