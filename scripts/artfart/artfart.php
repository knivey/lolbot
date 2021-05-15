<?php
namespace knivey\lolbot\artfart;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Options;

#[Cmd("artfart")]
#[CallWrap("Amp\asyncCall")]
#[Options("--rainbow", "--rnb")]
function artfart($args, \Irc\Client $bot, \knivey\Cmdr\Request $req)
{
    $url = "http://www.asciiartfarts.com/random.cgi";
    try {
        $client = HttpClientBuilder::buildDefault();
        $request = new Request($url);
        /** @var Response $response */
        $response = yield $client->request($request);
        $body = yield $response->getBody()->buffer();
        if ($response->getStatus() != 200) {
            var_dump($body);
            $bot->pm($args->chan, "Error (" . $response->getStatus() . ")");
            return;
        }
        if(!preg_match('/<table cellpadding=10><tr><td bgcolor="[^"]+"><font color="[^"]+"><pre>([^<]+)<\/pre>/i', $body, $m)) {
            $bot->pm($args->chan, "bad response");
            return;
        }

        $fart = trim(htmlspecialchars_decode($m[1], ENT_QUOTES|ENT_HTML5), "\n\r");
        if($req->args->getOpt('--rnb') || $req->args->getOpt('--rainbow'))
            $fart = \knivey\ircTools\diagRainbow($fart);
        foreach (explode("\n", $fart) as $line) {
            $bot->pm($args->chan, rtrim($line));
        }
    } catch (\Exception $error) {
        // If something goes wrong Amp will throw the exception where the promise was yielded.
        // The HttpClient::request() method itself will never throw directly, but returns a promise.
        echo $error;
        $bot->pm($args->chan, "\2artfart:\2 " . substr($error, 0, strpos($error, "\n")));
    }
}