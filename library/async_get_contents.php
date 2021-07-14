<?php

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;

class async_get_exception extends Exception {
    public function getMessageStripped($maxLen = 200) {
        $out = $this->getMessage();
        $out = str_replace(["\n", "\r"], " ", $out);
        $out = html_entity_decode($out,  ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $out = htmlspecialchars_decode($out);

        $out = str_replace("\x01", "[CTCP]", $out);
        return $out;
    }
}

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
            throw new async_get_exception($body, $response->getStatus());
        }
        return $body;
    });
}