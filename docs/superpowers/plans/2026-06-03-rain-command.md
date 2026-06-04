# `@rain` Command Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create a new `@rain` art bot command that renders multiple randomly-scaled copies of a user-provided SVG falling through a generated sky scene on a 100x120 canvas with 3x supersample.

**Architecture:** Single new file `artbot_scripts/rain.php` containing the `rain()` function registered via `#[Cmd("rain")]`. The function fetches an SVG URL, parses it, generates a sky background with gradient/sun/clouds, places 5-8 randomly-scaled copies with overlap avoidance (smallest first for depth), draws motion lines above each copy, then resamples from 300x360 to 100x120 and pumps output to IRC. One line added to `artbots.php` to include the new file.

**Tech Stack:** PHP 8.1+, Amp async HTTP, draw\ namespace (Canvas, SVGParser, SVGDocument, Path, Color, LinearGradient, RadialGradient, ColorStop, StrokeStyle, Paint), knivey\cmdr attributes.

---

### Task 1: Create rain.php with SVG fetch & parse

**Files:**
- Create: `artbot_scripts/rain.php`

- [ ] **Step 1: Create the file with imports, command attributes, URL validation, and SVG fetch/parse**

```php
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

        // TODO: sky, copies, motion lines, resample (next tasks)

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
```

- [ ] **Step 2: Add require_once to artbots.php**

Add after the existing `require_once 'artbot_scripts/svg.php';` line (line 61 in `artbots.php`):

```php
require_once 'artbot_scripts/rain.php';
```

- [ ] **Step 3: Commit**

```bash
git add artbot_scripts/rain.php artbots.php
git commit -m "feat: add @rain command skeleton with SVG fetch and parse"
```

---

### Task 2: Add sky background rendering

**Files:**
- Modify: `artbot_scripts/rain.php`

- [ ] **Step 1: Add the sky palette definitions and rendering functions inside the `rain()` function, replacing the `// TODO` comment**

Insert the following code after the `$renderH = $displayH * $ssFactor;` line, replacing the `// TODO: sky, copies, motion lines, resample (next tasks)` comment and the subsequent code that references `$canvas`. This is the full replacement from that point to just before the `catch` blocks:

```php
        $canvas = Canvas::createBlank($renderW, $renderH, true);

        // --- Sky background ---
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

        // Sun
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

        // Clouds
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

        // TODO: generate and render SVG copies (next task)
        // TODO: motion lines (next task)

        // Resample
        $canvas = $canvas->resampleTo($displayW, $displayH);

        $output = trim((string)$canvas);
        if ($output === '') {
            $bot->notice($args->nick, "Rendered as empty");
            return;
        }

        $lines = explode("\n", $output);
        \pumpToChan($bot, $args->chan, $lines);
```

- [ ] **Step 2: Run phpstan to check for errors**

Run: `composer phpstan -- --no-progress artbot_scripts/rain.php`
Expected: No errors

- [ ] **Step 3: Commit**

```bash
git add artbot_scripts/rain.php
git commit -m "feat: add sky background rendering to @rain command"
```

---

### Task 3: Add SVG copy generation, placement, and rendering

**Files:**
- Modify: `artbot_scripts/rain.php`

- [ ] **Step 1: Add SVG copy generation and rendering code after the cloud drawing block, replacing the `// TODO: generate and render SVG copies (next task)` comment**

```php
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
            $found = false;

            for ($attempt = 0; $attempt < 20; $attempt++) {
                $tx = rand(-($cw / 5), $canvasW - (int)($cw * 0.8));
                $ty = rand(-($ch / 5), $canvasH - (int)($ch * 0.8));

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
                    $ratio = $smallerArea > 0 ? $intersection / $smallerArea : 0;
                    $maxOverlap = max($maxOverlap, $ratio);
                }

                if ($maxOverlap < 0.5) {
                    $bestX = $tx;
                    $bestY = $ty;
                    $found = true;
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
```

- [ ] **Step 2: Run phpstan**

Run: `composer phpstan -- --no-progress artbot_scripts/rain.php`
Expected: No errors

- [ ] **Step 3: Commit**

```bash
git add artbot_scripts/rain.php
git commit -m "feat: add SVG copy generation, overlap avoidance, and rendering"
```

---

### Task 4: Add motion lines

**Files:**
- Modify: `artbot_scripts/rain.php`

- [ ] **Step 1: Add motion line drawing code after the SVG copy rendering block, replacing the `// TODO: motion lines (next task)` comment**

```php
        // --- Motion lines ---
        foreach ($placed as $p) {
            if ($p['y'] < $canvasH * 0.3) {
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
```

- [ ] **Step 2: Run phpstan**

Run: `composer phpstan -- --no-progress artbot_scripts/rain.php`
Expected: No errors

- [ ] **Step 3: Commit**

```bash
git add artbot_scripts/rain.php
git commit -m "feat: add motion lines to @rain command"
```

---

### Task 5: Verify and clean up

**Files:**
- Verify: `artbot_scripts/rain.php`
- Verify: `artbots.php`

- [ ] **Step 1: Run full static analysis**

Run: `composer phpstan`
Expected: No errors

- [ ] **Step 2: Run existing tests**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 3: Review the complete rain.php file for consistency**

Read the full file and verify:
- All imports are used
- No syntax errors
- The rendering pipeline follows the spec: sky → copies (small→large) → motion lines → resample → output
- Error handling matches `svg.php` pattern

- [ ] **Step 4: Final commit if any fixes needed**

```bash
git add -A
git commit -m "fix: clean up @rain command after review"
```
