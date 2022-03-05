<?php
namespace scripts\artfart;

use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Options;

#[Cmd("artfart")]
#[CallWrap("Amp\asyncCall")]
#[Options("--rainbow", "--rnb")]
function artfart($args, \Irc\Client $bot, \knivey\Cmdr\Request $req): \Generator
{
    $url = "http://www.asciiartfarts.com/random.cgi";
    try {
        $body = yield async_get_contents($url);
        if(!preg_match('/<table cellpadding=10><tr><td bgcolor="[^"]+"><font color="[^"]+"><pre>([^<]+)<\/pre>/i', $body, $m)) {
            $bot->pm($args->chan, "\2artfart:\2 bad response from server");
            return;
        }

        $fart = trim(htmlspecialchars_decode($m[1], ENT_QUOTES|ENT_HTML5), "\n\r");
        if($req->args->getOpt('--rnb') || $req->args->getOpt('--rainbow'))
            $fart = \knivey\ircTools\diagRainbow($fart);
        foreach (explode("\n", $fart) as $line) {
            $bot->pm($args->chan, rtrim($line));
        }
    } catch (\async_get_exception $error) {
        echo $error;
        $bot->pm($args->chan, "\2artfart:\2 {$error->getIRCMsg()}");
    } catch (\Exception $error) {
        echo $error->getMessage();
        $bot->pm($args->chan, "\2artfart:\2 {$error->getMessage()}");
    }
}