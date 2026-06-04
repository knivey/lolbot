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
use draw\RenderContext;
use draw\SVGParser;
use draw\Transform;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;

#[Cmd("rain")]
#[Syntax('[urls]...')]
function rain(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
{
    $urls = [];
    $preset = null;
    $noSS = false;
    $rawArgs = explode(' ', $cmdArgs[0] ?? '');
    foreach ($rawArgs as $a) {
        $a = trim($a);
        if ($a === '') {
            continue;
        }
        if ($a === '--no-supersample') {
            $noSS = true;
            continue;
        }
        if (preg_match('/^https?:\/\//i', $a)) {
            $urls[] = $a;
        } else {
            $preset = $a;
        }
    }

    $defaultDir = __DIR__ . '/rain-defaults';
    $useDefaults = empty($urls) && $preset === null;

    $docs = [];
    if ($useDefaults || $preset !== null) {
        if ($preset !== null) {
            $subdir = "$defaultDir/$preset";
        } else {
            $subdirs = glob("$defaultDir/*", GLOB_ONLYDIR);
            if (empty($subdirs)) {
                $bot->notice($args->nick, "No preset themes found");
                return;
            }
            $subdir = $subdirs[array_rand($subdirs)];
        }
        foreach (glob("$subdir/*.svg") as $file) {
            $body = file_get_contents($file);
            if ($body === false) {
                continue;
            }
            try {
                $docs[] = SVGParser::parseString($body, $bot->log);
            } catch (\Throwable) {
            }
        }
        if (empty($docs)) {
            $bot->notice($args->nick, "No SVGs found in " . basename($subdir));
            return;
        }
    }

    $maxSize = 2 * 1024 * 1024;

    try {
        foreach ($urls as $url) {
            if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', (string)$url)) {
                $bot->notice($args->nick, "URL must be http or https: $url");
                return;
            }
        }

        foreach ($urls as $url) {
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
                $bot->notice($args->nick, "URL does not appear to be an SVG file: $url");
                return;
            }

            $docs[] = SVGParser::parseString($body, $bot->log);
        }

        $displayW = 80;
        $displayH = 360;
        $ssFactor = $noSS ? 1 : 3;
        $renderW = $displayW * $ssFactor;
        $renderH = $displayH * $ssFactor;

        $canvas = Canvas::createBlank($renderW, $renderH, true);

        $pal = [
            [0.0, 25, 60, 150],
            [0.4, 80, 140, 210],
            [0.7, 150, 200, 240],
            [1.0, 200, 230, 255],
        ];
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

        $numClouds = rand(4, 8);
        for ($c = 0; $c < $numClouds; $c++) {
            $cloudX = rand(20, $renderW - 20);
            $cloudY = rand(15, $renderH - 15);
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

        $numCopies = rand(20, 32);
        $copies = [];

        for ($i = 0; $i < $numCopies; $i++) {
            $doc = $docs[array_rand($docs)];
            $svgW = $doc->getWidth();
            $svgH = $doc->getHeight();
            $vb = $doc->getViewBox();
            if ($vb !== null) {
                $svgW = $vb[2];
                $svgH = $vb[3];
            }
            if ($svgW <= 0 || $svgH <= 0) {
                continue;
            }

            $scalePct = 20 + pow(mt_rand() / mt_getrandmax(), 2.5) * 40;
            $copyW = (int)round(($scalePct / 100.0) * $renderW);
            $aspect = $svgH / $svgW;
            $copyH = (int)round($copyW * $aspect);
            $copyH = $copyH - ($copyH % 2);
            $copyW = max(10, $copyW);
            $copyH = max(2, $copyH);
            $rotation = deg2rad(rand(-20, 20));
            $copies[] = ['w' => $copyW, 'h' => $copyH, 'doc' => $doc, 'rot' => $rotation];
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
                $tx = rand((int)-($cw / 5), $renderW - (int)($cw * 0.8));
                $ty = rand((int)-($ch / 5), $renderH - (int)($ch * 0.8));

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

            $doc = $copy['doc'];
            $rot = $copy['rot'];
            $absCos = abs(cos($rot));
            $absSin = abs(sin($rot));
            $rotW = (int)round($cw * $absCos + $ch * $absSin);
            $rotH = (int)round($cw * $absSin + $ch * $absCos);
            $rotW = max($rotW, $cw);
            $rotH = max($rotH, $ch);
            $offX = (int)(($rotW - $cw) / 2);
            $offY = (int)(($rotH - $ch) / 2);

            $tempCanvas = Canvas::createBlank($rotW, $rotH, true);
            $vbt = $doc->getViewBoxTransform((float)$cw, (float)$ch);
            $tempCanvas->save();
            $tempCanvas->concatTransform(Transform::translate((float)$offX, (float)$offY));
            if ($vbt !== null) {
                $tempCanvas->concatTransform($vbt);
            }
            $cx = $cw / 2.0;
            $cy = $ch / 2.0;
            $tempCanvas->concatTransform(
                Transform::translate($cx, $cy)
                    ->multiply(Transform::rotate($rot))
                    ->multiply(Transform::translate(-$cx, -$cy))
            );
            $doc->getRoot()->render($tempCanvas, RenderContext::defaults());
            $tempCanvas->restore();

            for ($py = 0; $py < $rotH; $py++) {
                for ($px = 0; $px < $rotW; $px++) {
                    $dstX = $bestX - $offX + $px;
                    $dstY = $bestY - $offY + $py;
                    if ($dstX >= 0 && $dstX < $renderW && $dstY >= 0 && $dstY < $renderH) {
                        $sp = $tempCanvas->data[$py][$px];
                        if ($sp->fg !== null) {
                            $canvas->data[$dstY][$dstX] = clone $sp;
                        }
                    }
                }
            }
        }
        unset($copy);

        // --- Motion lines ---
        foreach ($placed as $p) {
            if ($p['y'] < (int)($renderH * 0.3)) {
                continue;
            }
            $numLines = rand(3, 5);
            $lineLen = (int)($p['w'] * 0.15 + $p['h'] * 0.1);
            $lineLen = max(5, min($lineLen, 30));
            $motionColor = new Color(0, null);
            for ($ml = 0; $ml < $numLines; $ml++) {
                $lx = $p['x'] + (int)(($ml + 0.5) / $numLines * $p['w']);
                $ly = $p['y'] - rand(2, 8);
                $spread = rand(-3, 3);
                $canvas->drawPath(
                    Path::line($lx, $ly, $lx + $spread, $ly - $lineLen),
                    null,
                    new StrokeStyle($motionColor),
                );
            }
        }

        $canvas = $canvas->resampleTo($displayW, $displayH);

        $output = (string)$canvas;
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
