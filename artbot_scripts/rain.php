<?php

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use draw\Canvas;
use draw\Color;
use draw\ColorStop;
use draw\LinearGradient;
use draw\RadialGradient;
use draw\Path;
use draw\StrokeStyle;
use draw\SVGParser;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;

#[Cmd("rain")]
#[Syntax('<url>')]
function rain(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
{
    $url = $cmdArgs[0] ?? '';
    if ($url === '') {
        $bot->notice($args->nick, "Usage: @rain <url>");
        return;
    }

    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', (string)$url)) {
        $bot->notice($args->nick, "URL must be http or https");
        return;
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
        if ($svgW <= 0 || $svgH <= 0) {
            $bot->notice($args->nick, "SVG has invalid dimensions");
            return;
        }

        $displayW = 100;
        $displayH = 120;
        $ssFactor = 3;
        $renderW = $displayW * $ssFactor;
        $renderH = $displayH * $ssFactor;

        $canvas = Canvas::createBlank($renderW, $renderH, true);

        $palettes = [
            [
                [0.0, 40, 20, 80],
                [0.4, 180, 100, 60],
                [0.7, 255, 180, 100],
                [1.0, 255, 230, 180],
            ],
            [
                [0.0, 25, 60, 150],
                [0.4, 80, 140, 210],
                [0.7, 150, 200, 240],
                [1.0, 200, 230, 255],
            ],
            [
                [0.0, 60, 20, 80],
                [0.3, 200, 60, 100],
                [0.6, 255, 120, 50],
                [1.0, 255, 180, 60],
            ],
            [
                [0.0, 160, 100, 40],
                [0.4, 230, 180, 60],
                [0.7, 255, 220, 130],
                [1.0, 255, 245, 200],
            ],
            [
                [0.0, 15, 15, 50],
                [0.4, 30, 50, 90],
                [0.7, 60, 70, 120],
                [1.0, 90, 80, 130],
            ],
        ];

        $pal = $palettes[array_rand($palettes)];
        $skyStops = [];
        foreach ($pal as $stop) {
            $skyStops[] = new ColorStop($stop[0], $stop[1], $stop[2], $stop[3]);
        }

        $skyGrad = new LinearGradient(0.0, 0.0, 0.0, (float)$renderH, $skyStops);
        $canvas->drawPath(Path::rect(0, 0, $renderW, $renderH), $skyGrad, null);

        $sunX = rand(40, $renderW - 40);
        $sunY = rand(30, 80);
        $sunR = rand(20, 40);
        $sunGrad = new RadialGradient(
            $sunX, $sunY, $sunR,
            [
                new ColorStop(0.0, 255, 255, 220),
                new ColorStop(0.5, 255, 240, 180),
                new ColorStop(1.0, $pal[0][1], $pal[0][2], $pal[0][3]),
            ],
        );
        $canvas->drawPath(Path::circle($sunX, $sunY, $sunR), $sunGrad, null);

        $numClouds = rand(2, 4);
        for ($c = 0; $c < $numClouds; $c++) {
            $cloudX = rand(20, $renderW - 20);
            $cloudY = rand(15, (int)($renderH * 0.5));
            $numBlobs = rand(2, 3);
            for ($b = 0; $b < $numBlobs; $b++) {
                $bw = rand(25, 55);
                $bh = rand(12, 22);
                $ox = rand(-15, 15);
                $oy = rand(-8, 8);
                $canvas->drawPath(
                    Path::ellipse($cloudX + $ox, $cloudY + $oy, $bw, $bh),
                    new Color(0, null),
                    null,
                );
            }
        }

        // --- Generate SVG copies ---
        $numCopies = rand(5, 8);
        $copies = [];
        $canvasW = $renderW;
        $canvasH = $renderH;

        for ($i = 0; $i < $numCopies; $i++) {
            $scalePct = 20 + (mt_rand() / mt_getrandmax()) * 40;
            $copyW = (int)round(($scalePct / 100.0) * $canvasW);
            $aspect = $svgH / $svgW;
            $copyH = (int)round($copyW * $aspect);
            $copyH = $copyH - ($copyH % 2);
            $copyW = max(10, $copyW);
            $copyH = max(2, $copyH);
            $copies[] = ['w' => $copyW, 'h' => $copyH];
        }

        usort($copies, fn($a, $b) => $a['w'] <=> $b['w']);

        $placed = [];
        foreach ($copies as &$copy) {
            $cw = $copy['w'];
            $ch = $copy['h'];
            $bestX = 0;
            $bestY = 0;
            $bestOverlap = PHP_FLOAT_MAX;

            for ($attempt = 0; $attempt < 20; $attempt++) {
                $tx = rand((int)-($cw / 5), $canvasW - (int)($cw * 0.8));
                $ty = rand((int)-($ch / 5), $canvasH - (int)($ch * 0.8));

                $maxOverlap = 0.0;
                foreach ($placed as $p) {
                    $ox1 = max($tx, $p['x']);
                    $oy1 = max($ty, $p['y']);
                    $ox2 = min($tx + $cw, $p['x'] + $p['w']);
                    $oy2 = min($ty + $ch, $p['y'] + $p['h']);
                    $ow = max(0, $ox2 - $ox1);
                    $oh = max(0, $oy2 - $oy1);
                    $intersection = $ow * $oh;
                    $smallerArea = min($cw * $ch, $p['w'] * $p['h']);
                    $ratio = $intersection / $smallerArea;
                    $maxOverlap = max($maxOverlap, $ratio);
                }

                if ($maxOverlap < 0.5) {
                    $bestX = $tx;
                    $bestY = $ty;
                    break;
                }

                if ($maxOverlap < $bestOverlap) {
                    $bestOverlap = $maxOverlap;
                    $bestX = $tx;
                    $bestY = $ty;
                }
            }

            $copy['x'] = $bestX;
            $copy['y'] = $bestY;
            $placed[] = $copy;

            $tempCanvas = Canvas::createBlank($cw, $ch, true);
            $vbt = $doc->getViewBoxTransform((float)$cw, (float)$ch);
            $tempCanvas->save();
            if ($vbt !== null) {
                $tempCanvas->concatTransform($vbt);
            }
            $doc->getRoot()->render($tempCanvas, \draw\RenderContext::defaults());
            $tempCanvas->restore();

            for ($py = 0; $py < $ch; $py++) {
                for ($px = 0; $px < $cw; $px++) {
                    $dstX = $bestX + $px;
                    $dstY = $bestY + $py;
                    if ($dstX >= 0 && $dstX < $canvasW && $dstY >= 0 && $dstY < $canvasH) {
                        $sp = $tempCanvas->data[$py][$px];
                        if ($sp->fg !== null) {
                            $canvas->data[$dstY][$dstX] = clone $sp;
                        }
                    }
                }
            }
        }
        unset($copy);

        // TODO: motion lines (next task)

        $canvas = $canvas->resampleTo($displayW, $displayH);

        $output = trim((string)$canvas);
        if ($output === '') {
            $bot->notice($args->nick, "Rendered as empty");
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
