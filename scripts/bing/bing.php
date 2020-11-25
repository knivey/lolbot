<?php

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;

$router->add('bing <query>...', 'bing');
function bing($args, $nick, $chan, \Irc\Client $bot)
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
    $query = urlencode(htmlentities(implode(' ', $args['query'])));
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
            $bot->pm($chan, "Error (" . $response->getStatus() . ") $body");
            return;
        }
        $j = json_decode($body, true);

        if (!array_key_exists('webPages', $j)) {
            $bot->pm($chan, "\2Bing:\2 No Results");
            return;
        }
        $results = $j['webPages']['totalEstimatedMatches'];
        $res = $j['webPages']['value'][0];

        $bot->pm($chan, "\2Bing (\2$results Results\2):\2 $res[url] ($res[name]) -- $res[snippet]");
    } catch (HttpException $error) {
        // If something goes wrong Amp will throw the exception where the promise was yielded.
        // The HttpClient::request() method itself will never throw directly, but returns a promise.
        echo $error;
        $bot->pm($chan, "\2Bing:\2" . $error);
    }
}