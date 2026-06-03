<?php

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use draw\Canvas;
use draw\SVGParser;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Option;
use knivey\cmdr\attributes\Syntax;

#[Cmd("svg")]
#[Syntax('<url>')]
#[Option("--width", "Canvas width (default 80)")]
#[Option("--height", "Canvas height (default 40)")]
#[Option("--nohalfblock", "Disable halfblock rendering")]
function svg(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
{
    $url = $cmdArgs[0] ?? '';
    if ($url === '') {
        $bot->notice($args->nick, "Usage: @svg <url>");
        return;
    }

    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', (string)$url)) {
        $bot->notice($args->nick, "URL must be http or https");
        return;
    }

    $width = (int)($cmdArgs->getOpt("--width") ?? 80);
    $height = (int)($cmdArgs->getOpt("--height") ?? 40);
    $halfblocks = !$cmdArgs->optEnabled("--nohalfblock");

    $maxSize = 2 * 1024 * 1024;

    try {
        $client = HttpClientBuilder::buildDefault();
        $request = new Request($url);
        $request->setBodySizeLimit($maxSize);

        /** @var Response $response */
        $response = $client->request($request);

        if ($response->getStatus() !== 200) {
            $bot->notice($args->nick, "Failed to fetch SVG: HTTP " . $response->getStatus());
            return;
        }

        $body = $response->getBody()->buffer();

        $contentType = strtolower($response->getHeader('content-type') ?? '');
        $isSvgType = str_contains($contentType, 'svg')
            || str_contains($contentType, 'xml')
            || str_contains($body, '<svg');

        if (!$isSvgType) {
            $bot->notice($args->nick, "URL does not appear to be an SVG file");
            return;
        }

        $doc = SVGParser::parseString($body);
        $canvas = Canvas::createBlank($width, $height, $halfblocks);
        $doc->render($canvas);

        $output = trim((string)$canvas);
        if ($output === '') {
            $bot->notice($args->nick, "SVG rendered as empty");
            return;
        }

        $lines = explode("\n", $output);
        \pumpToChan($bot, $args->chan, $lines);
    } catch (\Amp\Http\Client\ParseException $e) {
        $bot->notice($args->nick, "SVG file too large (max 2MB)");
    } catch (\InvalidArgumentException $e) {
        $bot->notice($args->nick, "Failed to parse SVG");
    } catch (\Throwable $e) {
        $bot->notice($args->nick, "Failed to fetch SVG: " . $e->getMessage());
    }
}
