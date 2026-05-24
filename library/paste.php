<?php

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;

function createPaste(string $content, string $title, string $host, string $key): string
{
    $client = HttpClientBuilder::buildDefault();
    $url = rtrim($host, '/') . '/api/pastes';
    $request = new Request($url, 'POST');
    $request->setHeader('X-API-Key', $key);
    $request->setHeader('Content-Type', 'application/json');
    $request->setBody(json_encode([
        'content' => $content,
        'title' => $title,
    ]));
    $response = $client->request($request);
    $body = $response->getBody()->buffer();
    if ($response->getStatus() !== 200 && $response->getStatus() !== 201) {
        throw new \Exception("Paste service error ({$response->getStatus()}): $body");
    }
    $data = json_decode($body, true);
    if (!isset($data['url'])) {
        throw new \Exception("Paste service returned unexpected response");
    }
    return $data['url'];
}
