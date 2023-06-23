<?php
namespace artbot_scripts;

use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Options;

#[Cmd("artfart")]
#[CallWrap("Amp\asyncCall")]
#[Options("--rainbow", "--rnb")]
function artfart($args, \Irc\Client $bot, \knivey\Cmdr\Args $cmdArgs): \Generator
{
    $url = "http://www.asciiartfarts.com/random.cgi";
    try {
        $body = yield async_get_contents($url);
        if(!preg_match('/<table cellpadding=10><tr><td bgcolor="[^"]+"><font color="[^"]+"><pre>([^<]+)<\/pre>/i', $body, $m)) {
            $bot->pm($args->chan, "\2artfart:\2 bad response from server");
            return;
        }

        $fart = trim(htmlspecialchars_decode($m[1], ENT_QUOTES|ENT_HTML5), "\n\r");
        if($cmdArgs->optEnabled('--rnb') || $cmdArgs->optEnabled('--rainbow'))
            $fart = \knivey\ircTools\diagRainbow($fart);
        $fart = explode("\n", $fart);
        $fart = array_map(rtrim(...), $fart);
        pumpToChan($args->chan, $fart);
    } catch (\async_get_exception $error) {
        echo $error;
        $bot->pm($args->chan, "\2artfart:\2 {$error->getIRCMsg()}");
    } catch (\Exception $error) {
        echo $error->getMessage();
        $bot->pm($args->chan, "\2artfart:\2 {$error->getMessage()}");
    }
}