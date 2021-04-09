<?php

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;

global $router; //lol makes ide not complain
$router->add('artfart', '\Amp\asyncCall', ['artfart']);

function artfart($nick, $chan, \Irc\Client $bot, $req)
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
            $bot->pm($chan, "Error (" . $response->getStatus() . ")");
            return;
        }
        $start = strpos($body, "<table cellpadding=10><tr><td bgcolor=\"#000000\"><font color=\"#ffffff\"><pre>");
        if($start === false) {
            $bot->pm($chan, "bad response");
            return;
        }
        $start += strlen("<table cellpadding=10><tr><td bgcolor=\"#000000\"><font color=\"#ffffff\"><pre>");
        $len = strpos($body, "</pre>", $start) - $start;
        $fart = trim(htmlspecialchars_decode(substr($body, $start, $len), ENT_QUOTES|ENT_HTML5), "\n\r");
        foreach (explode("\n", $fart) as $line) {
            $bot->pm($chan, rtrim($line));
        }
    } catch (HttpException $error) {
        // If something goes wrong Amp will throw the exception where the promise was yielded.
        // The HttpClient::request() method itself will never throw directly, but returns a promise.
        echo $error;
        $bot->pm($chan, "\2artfart:\2" . $error);
    }
}