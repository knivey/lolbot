<?php

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;

function stock($a, $bot, $chan)
{
    global $config;
    if (!isset($a[1])) {
        $bot->pm($chan, "give me something to lookup");
        return;
    }

    $stocks = $a[1];
    if (substr_count($stocks, ',') > 0) {
        $bot->pm($chan, "Please only 1 stock at time wtf");
        return;
    }
    $query = urlencode(htmlentities($stocks));
    $url = "https://cloud.iexapis.com/stable/stock/$query/quote?token=" . $config['iexKey'] . '&displayPercent=true';
    try {
        $client = HttpClientBuilder::buildDefault();
        /** @var Response $response */
        $response = yield $client->request(new Request($url));
        $body = yield $response->getBody()->buffer();
        if ($response->getStatus() != 200) {
            // Just in case its huge or some garbage
            $body = substr($body, 0, 200);
            $bot->pm($chan, "Error (" . $response->getStatus() . ") $body");
            return;
        }
        $j = json_decode($body, true);

        $bot->pm($chan, "$j[symbol] ($j[companyName]) $j[latestPrice] $j[change] ($j[changePercent]%)");
    } catch (HttpException $error) {
        // If something goes wrong Amp will throw the exception where the promise was yielded.
        // The HttpClient::request() method itself will never throw directly, but returns a promise.
        echo $error;
        $bot->pm($chan, "\2Stocks:\2" . $error);
    }
}