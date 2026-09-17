<?php

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use draw\Canvas;
use draw\Dithering;
use draw\RenderLimits;
use draw\SVGParser;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Option;
use knivey\cmdr\attributes\Syntax;

function svgClampUserWidth(int $w): int
{
    return max(0, min($w, 500));
}

function svgClampUserHeight(int $h): int
{
    return max(0, min($h, 10000));
}

/**
 * @return array{int, int}
 */
function svgComputeRenderSize(float $svgW, float $svgH, int $userWidth, int $userHeight, int $ssFactor, bool $halfblocks): array
{
    $userWidth = svgClampUserWidth($userWidth);
    $userHeight = svgClampUserHeight($userHeight);

    if ($userWidth > 0 && $userHeight > 0) {
        $width = $userWidth;
        $height = $userHeight;
    } elseif ($userWidth > 0) {
        $width = $userWidth;
        $height = $svgW > 0 ? (int) round($userWidth * $svgH / $svgW) : 40;
    } elseif ($userHeight > 0) {
        $height = $userHeight;
        $width = $svgH > 0 ? (int) round($userHeight * $svgW / $svgH) : 80;
    } else {
        $width = 80;
        $height = $svgW > 0 ? (int) round(80 * $svgH / $svgW) : 40;
    }

    if ($halfblocks) {
        $height = (int) make_even($height);
    }

    $width = max($width, 10);
    $height = max($height, 2);

    $renderW = $ssFactor > 0 ? $width * $ssFactor : $width;
    $renderH = $ssFactor > 0 ? $height * $ssFactor : $height;

    if ($renderW * $renderH > RenderLimits::maxCanvasPixels
        || $renderW > RenderLimits::maxCanvasSide
        || $renderH > RenderLimits::maxCanvasSide) {
        throw new \InvalidArgumentException("svg render too large {$renderW}x{$renderH}");
    }
    return [$renderW, $renderH];
}

#[Cmd("svg")]
#[Syntax('<url>')]
#[Option("--width", "Canvas width (default 80)")]
#[Option("--height", "Canvas height (derived from aspect ratio)")]
#[Option("--nohalfblock", "Disable halfblock rendering")]
#[Option("--dither", "Dithering mode: ordered4x4, shaderblocks, shaderblocksall")]
#[Option("--supersample", "Supersample factor 2-4 (default off)")]
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

    $halfblocks = !$cmdArgs->optEnabled("--nohalfblock");
    $dither = match (strtolower($cmdArgs->getOpt("--dither") ?: 'none')) {
        'ordered4x4', '4x4', 'spatial', 'ordered' => Dithering::Ordered4x4,
        'shaderblocks', 'shader', 'shade', 'blocks' => Dithering::ShaderBlocks,
        'shaderblocksall', 'all', 'shaderall' => Dithering::ShaderBlocksAll,
        'none' => Dithering::None,
        default => Dithering::None,
    };
    $ssFactor = 0;
    if ($cmdArgs->optEnabled("--supersample")) {
        $ssFactor = (int)($cmdArgs->getOpt("--supersample") ?: 2);
        $ssFactor = max(2, min(4, $ssFactor));
    }

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

        $doc = SVGParser::parseString($body, $bot->log);

        $svgW = $doc->getWidth();
        $svgH = $doc->getHeight();
        $vb = $doc->getViewBox();
        if ($vb !== null) {
            $svgW = $vb[2];
            $svgH = $vb[3];
        }

        $userWidth = (int)($cmdArgs->getOpt("--width") ?: 0);
        $userHeight = (int)($cmdArgs->getOpt("--height") ?: 0);

        [$renderW, $renderH] = svgComputeRenderSize(
            (float)$svgW, (float)$svgH, $userWidth, $userHeight, $ssFactor, $halfblocks
        );
        $width = $ssFactor > 0 ? intdiv($renderW, $ssFactor) : $renderW;
        $height = $ssFactor > 0 ? intdiv($renderH, $ssFactor) : $renderH;

        $canvas = Canvas::createBlank($renderW, $renderH, $halfblocks);
        if ($dither !== Dithering::None) {
            $canvas->setDithering($dither);
        }
        $doc->render($canvas);

        if ($ssFactor > 0) {
            $canvas = $canvas->resampleTo($width, $height);
        }

        // Do NOT trim whitespace - trailing spaces can be colored and are part of the art
        $output = (string)$canvas;
        if ($output === '') {
            $bot->notice($args->nick, "SVG rendered as empty");
            return;
        }

        $lines = explode("\n", $output);
        \pumpToChan($bot, $args->chan, $lines);
    } catch (\Amp\Http\Client\ParseException $e) {
        $bot->notice($args->nick, "SVG file too large (max 2MB)");
    } catch (\InvalidArgumentException $e) {
        $bot->notice($args->nick, $e->getMessage());
    } catch (\Throwable $e) {
        $bot->notice($args->nick, "Failed to fetch SVG: " . $e->getMessage());
    }
}
