<?php

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;

class async_get_exception extends Exception {
    public function getMessageStripped($maxLen = 200): string {
        $out = $this->getMessage();
        $out = str_replace(["\n", "\r"], " ", $out);
        $out = html_entity_decode($out,  ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $out = htmlspecialchars_decode($out);
        $out = str_replace("\x01", "[CTCP]", $out);
        return substr($out, 0, 200);
    }

    public function getIRCMsg(): string {
        return "Error ({$this->getCode()}): {$this->getMessageStripped()}";
    }
}

/**
 * @param $url
 * @param string[] $headers
 * @throws async_get_exception
 * @return string
 */
function async_get_contents(string $url, array $headers = []): string {
    $client = HttpClientBuilder::buildDefault();
    $request = new Request($url);
    $request->setHeaders($headers);
    /** @var Response $response */
    $response = $client->request($request);
    $body =  $response->getBody()->buffer();
    if ($response->getStatus() != 200) {
        throw new async_get_exception($body, $response->getStatus());
    }
    return $body;
}