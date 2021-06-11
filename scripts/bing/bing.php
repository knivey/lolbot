<?php
namespace knivey\lolbot\bing;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\MultiReasonException;
use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Options;
use knivey\cmdr\attributes\Syntax;

#[Cmd("bing")]
#[Syntax('<query>...')]
#[CallWrap("Amp\asyncCall")]
#[Options("--amt")]
function bing($args, \Irc\Client $bot, \knivey\cmdr\Request $req)
{
    global $config;
    if(!isset($config['bingKey'])) {
        echo "bingKey not set in config\n";
        return;
    }
    if(!isset($config['bingEP'])) {
        echo "bingKbingEPey not set in config\n";
        return;
    }
    if(!isset($config['bingLang'])) {
        echo "bingLang not set in config\n";
        return;
    }
    $amt = 1;
    if($req->args->getOpt("--amt")) {
        $amt = $req->args->getOptVal("--amt");
        if($amt < 1 || $amt > 4) {
            $bot->pm($args->chan, "\2Bing:\2 Result --amt should be from 1 to 4");
            return;
        }
    }
    $query = urlencode(htmlentities($req->args['query']));
    $url = $config['bingEP'] . "search?q=$query&mkt=$config[bingLang]&setLang=$config[bingLang]";
    try {
        $client = HttpClientBuilder::buildDefault();
        $request = new Request($url);
        $request->setHeader('Ocp-Apim-Subscription-Key', $config['bingKey']);
        /** @var Response $response */
        $response = yield $client->request($request);
        $body = yield $response->getBody()->buffer();
        if ($response->getStatus() != 200) {
            var_dump($body);
            // Just in case its huge or some garbage
            $body = substr($body, 0, 200);
            $bot->pm($args->chan, "Error (" . $response->getStatus() . ") $body");
            return;
        }
        $j = json_decode($body, true);

        if (!array_key_exists('webPages', $j)) {
            $bot->pm($args->chan, "\2Bing:\2 No Results");
            return;
        }
        $results = number_format($j['webPages']['totalEstimatedMatches']);

        for ($i = 0; $i <= $amt-1; $i++) {
            if(!isset($j['webPages']['value'][$i])) {
                $bot->pm($args->chan, "End of results :(");
                break;
            }
            $res = $j['webPages']['value'][$i];
            $bot->pm($args->chan, "\2Bing (\2$results Results\2):\2 $res[url] ($res[name]) -- $res[snippet]");
        }
    } catch (\Amp\MultiReasonException $errors) {
        foreach ($errors->getReasons() as $error) {
            echo $error;
            $bot->pm($args->chan, "\2Bing Error:\2 " . substr($error, 0, strpos($error, "\n")));
        }
    } catch (\Exception $error) {
        // If something goes wrong Amp will throw the exception where the promise was yielded.
        // The HttpClient::request() method itself will never throw directly, but returns a promise.
        echo $error;
        $bot->pm($args->chan, "\2Bing Error:\2 " . substr($error, 0, strpos($error, "\n")));
    }
}