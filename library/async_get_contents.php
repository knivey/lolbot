<?php

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;

function async_get_contents($url) {
    return \Amp\call(function () use ($url) {
        $client = HttpClientBuilder::buildDefault();
        $request = new Request($url);
        /** @var Response $response */
        $response = yield $client->request($request);
        $body = yield $response->getBody()->buffer();
        if ($response->getStatus() != 200) {
            var_dump($body);
            // Just in case its huge or some garbage
            $body = substr($body, 0, 200);
            $body = str_replace(["\n", "\r"], "", $body);
            throw new \Exception("Error (" . $response->getStatus() . ") $body");
        }
        return $body;
    });
}