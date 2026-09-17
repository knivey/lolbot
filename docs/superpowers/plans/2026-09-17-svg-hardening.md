# SVG Parser & Rasterizer Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent attacker-supplied SVGs and images from OOMing, stack-exhausting, or CPU-hanging the bot via the `@svg`/`@rain`/`@url`/`@ascii`/`@yoda`/`@doubleyoda` commands.

**Architecture:** Central `draw\RenderLimits` constants; guards at choke points — `Canvas::createBlank()` (allocation), `SVGParser` (parse depth/element/segment caps, reference-cycle guard, attribute clamps), `Canvas` rasterizer loops (scanline/arc/dash/coordinate caps), and a root-namespace `ImageGuard` helper for the Imagick paths (`urlimg.php`, `yoda.php`), mirroring the proven linktitles pattern. Guards throw `InvalidArgumentException` with user-showable messages (entry scripts already catch and display); clamps degrade silently.

**Tech Stack:** PHP 8.1+, PHPUnit (composer test), PHPStan level 9 (scoped), php-cs-fixer.

**Spec:** `docs/superpowers/specs/2026-09-17-svg-hardening-design.md`

**Repo rules (from AGENTS.md):** NEVER remove or overwrite existing comments. NEVER trim art outputs. Do not commit `.php-cs-fixer.dist.php` if php-cs-fixer auto-creates it (delete it). Verify with `composer test`; scoped phpstan must show no NEW errors vs the touched files' baseline.

**Baseline notes for verification:** Before your first edit, record scoped phpstan baselines:
`vendor/bin/phpstan analyse library/draw/ artbot_scripts/ scripts/yoda/ library/async_get_contents.php --no-progress --memory-limit=1G > /tmp/phpstan-baseline.txt 2>&1` — the draw library has pre-existing errors; your changes must not add any.

---

### Task 1: `RenderLimits` constants + `Canvas::createBlank` allocation guard

**Files:**
- Create: `library/draw/RenderLimits.php`
- Modify: `library/draw/Canvas.php:50-63`
- Test: `tests/Canvas/CreateBlankLimitsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Canvas/CreateBlankLimitsTest.php`:

```php
<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\RenderLimits;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

class CreateBlankLimitsTest extends TestCase
{
    public function test_legal_size_still_works(): void
    {
        $canvas = Canvas::createBlank(400, 400);
        $this->assertSame(400, $canvas->w);
        $this->assertSame(400, $canvas->h);
    }

    public function test_zero_or_negative_dimension_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('canvas too large');
        Canvas::createBlank(0, 100);
    }

    public function test_total_pixels_over_cap_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('canvas too large');
        Canvas::createBlank(RenderLimits::maxCanvasPixels + 1, 1);
    }

    public function test_side_over_cap_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('canvas too large');
        Canvas::createBlank(1, RenderLimits::maxCanvasSide + 1);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Canvas/CreateBlankLimitsTest.php`
Expected: FAIL — `Class "draw\RenderLimits" not found`

- [ ] **Step 3: Create `RenderLimits` and guard `createBlank`**

Create `library/draw/RenderLimits.php`:

```php
<?php

namespace draw;

/**
 * Central caps for rendering untrusted SVG/image input.
 * Guards against memory bombs (allocation) and CPU bombs (rasterizer loops).
 * See docs/superpowers/specs/2026-09-17-svg-hardening-design.md
 */
final class RenderLimits
{
    //total canvas pixels (Pixel objects cost ~100 bytes each; 4M ≈ 400MB worst case)
    public const maxCanvasPixels = 4000000;

    //per-dimension sanity bound
    public const maxCanvasSide = 100000;

    //XML nesting depth / element count caps for SVGParser
    public const maxParseDepth = 200;
    public const maxElements = 100000;

    //path `d` attribute segment cap
    public const maxPathSegments = 50000;

    //attribute clamps (silent, degrade gracefully)
    public const maxStrokeWidth = 500;
    public const maxBlurStdDev = 100;
    public const maxFontSize = 1000;
    public const maxDashPatternEntries = 16;
    public const maxDashCount = 10000;
    public const maxArcSteps = 5000;

    //coordinate magnitude clamp before rasterization
    public const maxCoordMagnitude = 10000000;
}
```

In `library/draw/Canvas.php`, modify `createBlank` (currently lines 50-63). Keep the existing comments intact:

```php
    public static function createBlank(int $w, int $h, bool $halfblocks = false): Canvas
    {
        if ($w <= 0 || $h <= 0
            || $w * $h > RenderLimits::maxCanvasPixels
            || $w > RenderLimits::maxCanvasSide
            || $h > RenderLimits::maxCanvasSide) {
            throw new \InvalidArgumentException("canvas too large {$w}x{$h}");
        }
        $new = new self($halfblocks);
        //lol all pixels were same instance
        //$new->canvas = array_fill(0, $h, array_fill(0, $w, new Pixel()));
        for ($y = 0;$y < $h;$y++) {
            for ($x = 0;$x < $w;$x++) {
                $new->data[$y][$x] = new Pixel();
            }
        }
        $new->w = $w;
        $new->h = $h;
        return $new;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Canvas/CreateBlankLimitsTest.php`
Expected: PASS (4 tests)

- [ ] **Step 5: Run full Canvas suite + scoped phpstan, then commit**

Run: `vendor/bin/phpunit tests/Canvas/ && vendor/bin/phpstan analyse library/draw/Canvas.php library/draw/RenderLimits.php --no-progress --memory-limit=1G 2>&1 | tail -3`
Expected: all green, no NEW phpstan errors vs baseline.
Commit: `git add library/draw/RenderLimits.php library/draw/Canvas.php tests/Canvas/CreateBlankLimitsTest.php && git commit -m "feat(draw): RenderLimits + createBlank canvas allocation cap"`

---

### Task 2: Canvas rasterizer CPU guards (scanline, coordinates, arcs, dashes)

**Files:**
- Modify: `library/draw/Canvas.php` — `fillPolygonScanlineMulti` (~line 714), `fillSpan` closure (~line 745), `drawPath` snap loop (~line 489-498), `arcPoints` (~line 978), `makeCap` (~line 1024), `applyDashPattern` (~line 1074)
- Test: `tests/Canvas/RasterizerBombsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Canvas/RasterizerBombsTest.php`:

```php
<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\Path;
use draw\Color;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

class RasterizerBombsTest extends TestCase
{
    public function test_huge_coordinate_polygon_fills_bounded(): void
    {
        //pre-fix this iterates ~1e9 scanlines; the test process would hang for minutes.
        //post-fix it fills only the canvas intersection and returns fast.
        $canvas = Canvas::createBlank(80, 40);
        $path = Path::polygon([[0, 0], [1000000000, 1000000000], [0, 1000000000]]);
        $start = hrtime(true);
        $canvas->drawPath($path, new Color(1, null));
        $ms = (hrtime(true) - $start) / 1e6;
        $this->assertLessThan(5000.0, $ms, 'scanline fill should be bounded by canvas size');
        $this->assertSame(80, $canvas->w);
    }

    public function test_normal_fill_unchanged(): void
    {
        $canvas = Canvas::createBlank(20, 20);
        $path = Path::polygon([[2, 2], [17, 2], [17, 17], [2, 17]]);
        $canvas->drawPath($path, new Color(4, null));
        $this->assertNotNull($canvas->data[10][10]->fg);
        $this->assertNull($canvas->data[0][0]->fg);
    }
}
```

- [ ] **Step 2: Run test to verify it fails (or hangs)**

Run: `timeout 30 vendor/bin/phpunit tests/Canvas/RasterizerBombsTest.php`
Expected: the huge-coordinate test HANGS and is killed by `timeout 30` (exit 124), or exceeds the 5s assertion. The normal-fill test passes.

- [ ] **Step 3: Implement the rasterizer guards**

All edits in `library/draw/Canvas.php`. Preserve every existing comment.

3a. In `fillPolygonScanlineMulti`, replace the scanline loop head (line ~714):

```php
        $YStart = max(0, (int) ceil($minY));
        $YEnd = min($this->h - 1, (int) floor($maxY));
        for ($Y = $YStart; $Y <= $YEnd; $Y++) {
```

3b. In the `$fillSpan` closure inside the same method (line ~745), clamp x to the canvas:

```php
            $fillSpan = function (float $x0, float $x1) use ($Y, $paint, $text): void {
                $xL = max((int) ceil($x0), 0);
                $xR = min((int) floor($x1), $this->w - 1);
                for ($xx = $xL; $xx <= $xR; $xx++) {
```

3c. In `drawPath`, in the `$snapped` loop (lines ~489-498), clamp coordinate magnitude before rounding:

```php
        $snappedSubpaths = [];
        $mag = (float) RenderLimits::maxCoordMagnitude;
        foreach ($subpaths as $sp) {
            $snapped = [];
            foreach ($sp['vertices'] as $v) {
                if ($needTransform) {
                    $v = $effective->apply($v[0], $v[1]);
                }
                $vx = $v[0] > $mag ? $mag : ($v[0] < -$mag ? -$mag : $v[0]);
                $vy = $v[1] > $mag ? $mag : ($v[1] < -$mag ? -$mag : $v[1]);
                $snapped[] = [(int) round($vx), (int) round($vy)];
            }
            $snappedSubpaths[] = ['vertices' => $snapped, 'closed' => $sp['closed']];
        }
```

3d. In `arcPoints` (line ~978), cap steps:

```php
        $steps = max(3, (int) ceil(abs($diff) * $radius / 2.0));
        $steps = min($steps, RenderLimits::maxArcSteps);
```

3e. In `makeCap` (line ~1024), same cap:

```php
        $steps = max(3, (int) ceil(abs($diff) * $halfW / 2.0));
        $steps = min($steps, RenderLimits::maxArcSteps);
```

3f. In `applyDashPattern`, bound the dash walk loop (line ~1074):

```php
        $maxDashSegments = RenderLimits::maxDashCount;
        while ($pos < $totalLen) {
            if (count($result) >= $maxDashSegments) {
                break;
            }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `timeout 30 vendor/bin/phpunit tests/Canvas/RasterizerBombsTest.php && vendor/bin/phpunit tests/Canvas/`
Expected: all pass quickly (bomb test well under 5s), existing Canvas suite green.

- [ ] **Step 5: Commit**

`git add library/draw/Canvas.php tests/Canvas/RasterizerBombsTest.php && git commit -m "fix(draw): bound scanline/coord/arc/dash loops to render limits"`

---

### Task 3: SVGParser structural guards (libxml flags, depth, element count, path segments)

**Files:**
- Modify: `library/draw/SVGParser.php` — `parseString` (~346-375), `parseElement` (~415-441), `collectAllDefs` (~560-574), `collectStyles` (~1265-1280), `parseDString` (the token loop, ~100-207)
- Test: `tests/Canvas/SVGParserStructuralLimitsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Canvas/SVGParserStructuralLimitsTest.php`:

```php
<?php

namespace Tests\Canvas;

use draw\SVGParser;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

class SVGParserStructuralLimitsTest extends TestCase
{
    public function test_normal_svg_still_parses(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><g><path d="M0 0 L10 10" fill="red"/></g></svg>';
        $doc = SVGParser::parseString($svg);
        $this->assertNotNull($doc);
    }

    public function test_deep_nesting_throws(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg">' . str_repeat('<g>', 300) . '</g></svg>';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('svg nesting too deep');
        SVGParser::parseString($svg);
    }

    public function test_too_many_elements_throws(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg">' . str_repeat('<g/>', 100001) . '</svg>';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('svg too many elements');
        SVGParser::parseString($svg);
    }

    public function test_long_path_data_throws(): void
    {
        $d = 'M0 0' . str_repeat(' L1 1', 60000);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><path d="' . $d . '" fill="red"/></svg>';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('svg path too long');
        SVGParser::parseString($svg);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `timeout 120 vendor/bin/phpunit tests/Canvas/SVGParserStructuralLimitsTest.php`
Expected: FAIL — no exception thrown for the three bomb tests (the 100k-element test may take a while but completes; if any test hangs instead, note which — the guards will bound it after Step 3).

- [ ] **Step 3: Implement structural guards**

All edits in `library/draw/SVGParser.php`.

3a. Add static counters near the top of the class (after the `PRESENTATION_PROPS` const, ~line 344):

```php
    private static int $parseDepth = 0;
    private static int $elementCount = 0;
    private static array $refStack = [];
```

(`$refStack` is used by Task 4; declaring it now avoids touching this block twice.)

3b. In `parseString` (~line 346-351), reset counters and pass the no-network libxml flag:

```php
    public static function parseString(string $svg, ?LoggerInterface $logger = null): SVGDocument
    {
        self::$parseDepth = 0;
        self::$elementCount = 0;
        self::$refStack = [];
        $xml = @simplexml_load_string($svg, null, LIBXML_NONET);
        if ($xml === false) {
            throw new \InvalidArgumentException('Failed to parse SVG XML');
        }
```

3c. In `parseElement` (~line 415), add counters with try/finally around the match:

```php
    private static function parseElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger, Transform $parentTransform): SceneNode
    {
        if (++self::$elementCount > RenderLimits::maxElements) {
            throw new \InvalidArgumentException('svg too many elements');
        }
        if (++self::$parseDepth > RenderLimits::maxParseDepth) {
            throw new \InvalidArgumentException('svg nesting too deep');
        }
        try {
            $name = $el->getName();
            return match ($name) {
                // ... entire existing match expression unchanged ...
            };
        } finally {
            self::$parseDepth--;
        }
    }
```

(Keep the existing match body byte-identical; only wrap it.)

3d. In `collectAllDefs` (~line 560), thread a depth parameter:

```php
    private static function collectAllDefs(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger, int $depth = 0): void
    {
        if ($depth > RenderLimits::maxParseDepth) {
            throw new \InvalidArgumentException('svg nesting too deep');
        }
        foreach (self::svgChildren($el) as $child) {
            $name = $child->getName();
            if ($name === 'defs') {
                self::parseDefsElement($child, $defs, $styles, $logger);
            } elseif ($name === 'linearGradient' || $name === 'radialGradient') {
                self::parseGradientElement($child, $defs, $styles, $logger);
            } elseif ($name === 'clipPath' || $name === 'mask' || $name === 'filter') {
                self::parseClipMaskElement($child, $defs, $styles, $logger);
            } else {
                self::collectAllDefs($child, $defs, $styles, $logger, $depth + 1);
            }
        }
    }
```

3e. In `collectStyles` (~line 1265), same pattern:

```php
    private static function collectStyles(\SimpleXMLElement $el, int $depth = 0): array
    {
        if ($depth > RenderLimits::maxParseDepth) {
            throw new \InvalidArgumentException('svg nesting too deep');
        }
        $styles = [];
        $name = $el->getName();
        if ($name === 'style') {
            $text = trim((string)$el);
            if ($text !== '') {
                $styles = self::parseStyleBlock($text);
            }
            return $styles;
        }
        foreach (self::svgChildren($el) as $child) {
            $styles = array_merge($styles, self::collectStyles($child, $depth + 1));
        }
        return $styles;
    }
```

3f. In `parseDString`, find the main token loop (`foreach ($tokens as ...)` switching on command letters, lines ~100-207) and add a segment counter as the first statement inside the loop body, before the switch:

```php
        $segments = 0;
        foreach ($tokens as $token) {
            if ($segments++ > RenderLimits::maxPathSegments) {
                throw new \InvalidArgumentException('svg path too long');
            }
            // ... existing switch unchanged ...
```

(Declare `$segments = 0;` immediately before the `foreach`. If the loop uses a different variable name/shape, adapt the placement so the counter increments exactly once per command token iteration.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `timeout 120 vendor/bin/phpunit tests/Canvas/SVGParserStructuralLimitsTest.php && vendor/bin/phpunit tests/Canvas/`
Expected: all pass; existing Canvas/SVG tests green.

- [ ] **Step 5: Commit**

`git add library/draw/SVGParser.php tests/Canvas/SVGParserStructuralLimitsTest.php && git commit -m "fix(draw): SVG parser depth/element/path-segment caps + LIBXML_NONET"`

---

### Task 4: SVGParser cycle guard + attribute clamps

**Files:**
- Modify: `library/draw/SVGParser.php` — `wrapWithClipMask` (~808-875), `parseStrokeAttr` (~1156-1169), tspan stroke-width (~1443), `stdDeviation` sites (~900, ~915), `font-size` sites (~1412, ~1432)
- Modify: `library/draw/GaussianBlurPrimitive.php` — defensive clamp in `apply` (~24-38)
- Test: `tests/Canvas/SVGParserClampsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Canvas/SVGParserClampsTest.php`:

```php
<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\SVGParser;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

class SVGParserClampsTest extends TestCase
{
    private function render(string $inner, int $w = 80, int $h = 40): Canvas
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 40">' . $inner . '</svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank($w, $h);
        $doc->render($canvas);
        return $canvas;
    }

    public function test_circular_clippath_throws(): void
    {
        $inner = '<defs><clipPath id="a"><rect clip-path="url(#a)" width="10" height="10"/></clipPath></defs>'
            . '<rect width="80" height="40" fill="red" clip-path="url(#a)"/>';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('circular');
        $this->render($inner);
    }

    public function test_circular_mask_throws(): void
    {
        $inner = '<defs><mask id="m"><rect clip-path="url(#c)" width="10" height="10"/></mask>'
            . '<clipPath id="c"><rect mask="url(#m)" width="10" height="10"/></clipPath></defs>'
            . '<rect width="80" height="40" fill="red" mask="url(#m)"/>';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('circular');
        $this->render($inner);
    }

    public function test_huge_stroke_width_renders(): void
    {
        $canvas = $this->render('<path d="M10 20 L70 20" stroke="red" stroke-width="1000000000" fill="none"/>');
        $this->assertSame(80, $canvas->w);
    }

    public function test_huge_blur_stddev_renders(): void
    {
        $canvas = $this->render('<defs><filter id="f"><feGaussianBlur stdDeviation="1e9"/></filter></defs>'
            . '<rect width="30" height="20" fill="red" filter="url(#f)"/>');
        $this->assertSame(80, $canvas->w);
    }

    public function test_huge_font_size_renders(): void
    {
        $canvas = $this->render('<text x="10" y="30" font-size="1e9" fill="red">A</text>');
        $this->assertSame(80, $canvas->w);
    }

    public function test_huge_dash_pattern_renders(): void
    {
        $canvas = $this->render('<path d="M5 20 L75 20" stroke="red" fill="none" stroke-dasharray="0.001 0.001"/>');
        $this->assertSame(80, $canvas->w);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `timeout 60 vendor/bin/phpunit tests/Canvas/SVGParserClampsTest.php`
Expected: circular tests FAIL with no exception (or stack exhaustion crash — if PHP segfaults, that itself is the demonstration; the remaining clamp tests hang or take minutes).

- [ ] **Step 3: Implement cycle guard + clamps**

All edits preserve existing comments.

3a. `wrapWithClipMask` — clip branch (~811-823). After resolving `$clipEl`, guard the child re-parse with the ref stack:

```php
            if (isset($defs[$clipId]) && $defs[$clipId]->getName() === 'clipPath') {
                if (in_array($clipId, self::$refStack, true)) {
                    throw new \InvalidArgumentException("circular clipPath reference #{$clipId}");
                }
                $clipEl = $defs[$clipId];
                $clipContent = new Group();
                $childTransform = $parentTransform;
                $clipTransform = self::parseOptionalTransform($clipEl, $styles);
                if ($clipTransform !== null) {
                    $childTransform = $parentTransform->multiply($clipTransform);
                }
                self::$refStack[] = $clipId;
                try {
                    foreach (self::svgChildren($clipEl) as $clipChild) {
                        $clipContent->addChild(self::parseElement($clipChild, $defs, $styles, $logger, $childTransform));
                    }
                } finally {
                    array_pop(self::$refStack);
                }
```

3b. Same method — mask branch (~835-841):

```php
            if (isset($defs[$maskId]) && $defs[$maskId]->getName() === 'mask') {
                if (in_array($maskId, self::$refStack, true)) {
                    throw new \InvalidArgumentException("circular mask reference #{$maskId}");
                }
                $maskEl = $defs[$maskId];
                $maskContent = new Group();
                $maskTransform = self::parseOptionalTransform($maskEl, $styles);
                self::$refStack[] = $maskId;
                try {
                    foreach (self::svgChildren($maskEl) as $maskChild) {
                        $maskContent->addChild(self::parseElement($maskChild, $defs, $styles, $logger, $parentTransform));
                    }
                } finally {
                    array_pop(self::$refStack);
                }
```

(Leave the filter branch unchanged — `parseFilterElement` builds primitives only, no recursive element parse.)

3c. `parseStrokeAttr` stroke width (~1156-1159), add clamp:

```php
        $width = (float)(self::getEffectiveAttr($el, 'stroke-width', $styles) ?: '1.0');
        if ($width < 0) {
            $width = 1.0;
        }
        $width = min($width, (float) RenderLimits::maxStrokeWidth);
```

3d. `parseStrokeAttr` dash array (~1161-1169), cap pattern entries:

```php
        if ($dashStr !== '' && $dashStr !== 'none') {
            $dashArray = array_map('floatval', preg_split('/[\s,]+/', trim($dashStr)));
            $dashArray = array_filter($dashArray, fn($v) => $v > 0);
            $dashArray = array_slice($dashArray, 0, RenderLimits::maxDashPatternEntries);
            if (empty($dashArray)) {
                $dashArray = null;
            }
        }
```

3e. Tspan stroke width (~1443):

```php
                    $strokeWidth = (float) self::getEffectiveAttr($child, 'stroke-width', $styles) ?: 1.0;
                    $strokeWidth = min($strokeWidth, (float) RenderLimits::maxStrokeWidth);
```

3f. `stdDeviation` clamps in the filter primitive match (~899-903 and ~912-920):

```php
                'feGaussianBlur' => new GaussianBlurPrimitive(
                    min((float)($child['stdDeviation'] ?? 0), (float) RenderLimits::maxBlurStdDev),
                    input: self::parseOptionalString($child['in']),
                    result: self::parseOptionalString($child['result']),
                ),
```

```php
                'feDropShadow' => new DropShadowPrimitive(
                    (float)($child['dx'] ?? 2),
                    (float)($child['dy'] ?? 2),
                    min((float)($child['stdDeviation'] ?? 2), (float) RenderLimits::maxBlurStdDev),
                    self::parseFloodColor($child),
                    (float)($child['flood-opacity'] ?? 1),
                    input: self::parseOptionalString($child['in']),
                    result: self::parseOptionalString($child['result']),
                ),
```

3g. `font-size` clamps (~1411-1412 and ~1431-1432):

```php
        $fontSizeStr = self::getEffectiveAttr($el, 'font-size', $styles);
        $textNode->fontSize = $fontSizeStr !== '' ? min((float) $fontSizeStr, (float) RenderLimits::maxFontSize) : 16;
```

```php
                $fontSizeStr = self::getEffectiveAttr($child, 'font-size', $styles);
                $tspan->fontSize = $fontSizeStr !== '' ? min((float) $fontSizeStr, (float) RenderLimits::maxFontSize) : null;
```

3h. `GaussianBlurPrimitive::apply` (library/draw/GaussianBlurPrimitive.php ~24-38), defensive re-clamp:

```php
    public function apply(Canvas $input, FilterPipeline $pipeline): Canvas
    {
        $stdDeviation = min($this->stdDeviation, (float) RenderLimits::maxBlurStdDev);
        if ($stdDeviation < 0.001) {
            $output = Canvas::createBlank($input->w, $input->h, $input->halfblocks);
            Compositor::blend($output, $input);
            if ($this->result !== null) {
                $pipeline->setResult($this->result, $output);
            }
            return $output;
        }

        $boxRadius = (int) floor($stdDeviation * sqrt(12.0 / 3.0) / 2.0 + 0.5);
        if ($boxRadius < 1) {
            $boxRadius = 1;
        }
```

(Rest of the method unchanged.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `timeout 120 vendor/bin/phpunit tests/Canvas/SVGParserClampsTest.php && vendor/bin/phpunit tests/Canvas/`
Expected: all pass quickly; existing suite green.

- [ ] **Step 5: Commit**

`git add library/draw/SVGParser.php library/draw/GaussianBlurPrimitive.php tests/Canvas/SVGParserClampsTest.php && git commit -m "fix(draw): clip/mask cycle guard + stroke/blur/font/dash clamps"`

---

### Task 5: `@svg` sizing extraction + option clamps + friendly errors

**Files:**
- Modify: `artbot_scripts/svg.php` (sizing math lines ~84-111, catch block ~132-133)
- Test: `tests/Artbot/SvgComputeRenderSizeTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Artbot/SvgComputeRenderSizeTest.php`:

```php
<?php

namespace Tests\Artbot;

use draw\RenderLimits;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../artbot_scripts/svg.php';

class SvgComputeRenderSizeTest extends TestCase
{
    public function test_default_80_col_sizing(): void
    {
        [$w, $h] = svgComputeRenderSize(100.0, 50.0, 0, 0, 0, false);
        $this->assertSame(80, $w);
        $this->assertSame(40, $h);
    }

    public function test_aspect_bomb_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('svg render too large');
        svgComputeRenderSize(0.001, 1000000000.0, 0, 0, 0, false);
    }

    public function test_width_option_clamped_to_1000(): void
    {
        [$w, $h] = svgComputeRenderSize(100.0, 100.0, 50000, 0, 0, false);
        $this->assertSame(1000, $w);
        $this->assertSame(1000, $h);
    }

    public function test_supersample_bomb_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        svgComputeRenderSize(100.0, 100.0, 1000, 0, 4, false);
    }

    public function test_explicit_user_dims_bomb_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        svgComputeRenderSize(100.0, 100.0, 999, 9999, 0, false);
    }

    public function test_height_only_sizing(): void
    {
        [$w, $h] = svgComputeRenderSize(100.0, 50.0, 0, 20, 0, false);
        $this->assertSame(20, $h);
        $this->assertSame(40, $w);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Artbot/SvgComputeRenderSizeTest.php`
Expected: FAIL — `svgComputeRenderSize` / `svgClampUserWidth` not defined.

- [ ] **Step 3: Implement sizing helper and rewire `svg()`**

3a. Add helpers near the top of `artbot_scripts/svg.php` (after the `use` statements, before the `#[Option]` attributes). `make_even` is a global helper already available in the artbot runtime; the helper only calls it when `$halfblocks` is true:

```php
function svgClampUserWidth(int $w): int
{
    return max(0, min($w, 1000));
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
        $height = make_even($height);
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
```

Add `use draw\RenderLimits;` to the file's `use` block (it already imports `Canvas`, `SVGParser` etc. — follow the same style).

3b. Replace the sizing block inside `svg()` (lines ~84-111) with:

```php
        $userWidth = (int)($cmdArgs->getOpt("--width") ?: 0);
        $userHeight = (int)($cmdArgs->getOpt("--height") ?: 0);

        [$renderW, $renderH] = svgComputeRenderSize(
            (float)$svgW, (float)$svgH, $userWidth, $userHeight, $ssFactor, $halfblocks
        );

        $canvas = Canvas::createBlank($renderW, $renderH, $halfblocks);
```

(Delete the now-duplicated inline `$width`/`$height`/`max()`/`$renderW`/`$renderH` computation, keeping everything else — the `$doc->render($canvas)`, `resampleTo($width, $height)` call below needs `$width`/`$height`: compute them as `$width = $ssFactor > 0 ? intdiv($renderW, $ssFactor) : $renderW; $height = $ssFactor > 0 ? intdiv($renderH, $ssFactor) : $renderH;` immediately after the helper call.)

3c. Show the actual message on parse/guard errors — change the catch at lines ~132-133:

```php
    } catch (\InvalidArgumentException $e) {
        $bot->notice($args->nick, $e->getMessage());
    } catch (\Throwable $e) {
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Artbot/SvgComputeRenderSizeTest.php && vendor/bin/phpunit tests/Canvas/`
Expected: all pass.

- [ ] **Step 5: Commit**

`git add artbot_scripts/svg.php tests/Artbot/SvgComputeRenderSizeTest.php && git commit -m "fix(svg): clamp render sizing + friendly oversize error"`

---

### Task 6: `@rain` copy-size clamps + friendly errors

**Files:**
- Modify: `artbot_scripts/rain.php` (copy sizing ~180-189, catch ~310-311)
- Test: `tests/Artbot/RainClampCopyTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Artbot/RainClampCopyTest.php`:

```php
<?php

namespace Tests\Artbot;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../artbot_scripts/rain.php';

class RainClampCopyTest extends TestCase
{
    public function test_aspect_bomb_copy_clamped(): void
    {
        [$w, $h] = rainClampCopy(60, 100000000, 200, 100);
        $this->assertLessThanOrEqual(400, $w);
        $this->assertLessThanOrEqual(200, $h);
    }

    public function test_normal_copy_unchanged(): void
    {
        [$w, $h] = rainClampCopy(60, 40, 200, 100);
        $this->assertSame(60, $w);
        $this->assertSame(40, $h);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Artbot/RainClampCopyTest.php`
Expected: FAIL — `rainClampCopy` not defined.

- [ ] **Step 3: Implement the clamp helper and wire it in**

3a. Add to `artbot_scripts/rain.php` near the top (after the `use` statements):

```php
/**
 * Clamp a rain copy to a sane multiple of the render canvas so
 * attacker-controlled SVG aspect ratios cannot drive huge allocations.
 * @return array{int, int}
 */
function rainClampCopy(int $w, int $h, int $renderW, int $renderH): array
{
    return [min($w, $renderW * 2), min($h, $renderH * 2)];
}
```

3b. In the copy loop (lines ~183-186), apply the clamp where the minimums are applied:

```php
            $scalePct = 20 + pow(mt_rand() / mt_getrandmax(), 2.5) * 40;
            $copyW = (int)round(($scalePct / 100.0) * $renderW);
            $aspect = $svgH / $svgW;
            $copyH = (int)round($copyW * $aspect);
            $copyH = $copyH - ($copyH % 2);
            $copyW = max(10, $copyW);
            $copyH = max(2, $copyH);
            [$copyW, $copyH] = rainClampCopy($copyW, $copyH, $renderW, $renderH);
            $rotation = deg2rad(rand(-20, 20));
```

(`$renderW`/`$renderH` are the scene canvas dimensions already used at lines ~202-203; they are in scope.)

3c. Show the actual message on guard errors — change the catch at lines ~310-311:

```php
    } catch (\InvalidArgumentException $e) {
        $bot->notice($args->nick, $e->getMessage());
    } catch (\Throwable $e) {
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Artbot/RainClampCopyTest.php && vendor/bin/phpunit tests/Artbot tests/Canvas`
Expected: all pass.

- [ ] **Step 5: Commit**

`git add artbot_scripts/rain.php tests/Artbot/RainClampCopyTest.php && git commit -m "fix(rain): clamp copy sizing + friendly oversize error"`

---

### Task 7: `ImageGuard` + harden `@url`/`@ascii`/`@yoda`/`@doubleyoda` Imagick paths

**Files:**
- Create: `library/ImageGuard.php`
- Modify: `library/async_get_contents.php:28-32` (optional size cap)
- Modify: `artbot_scripts/urlimg.php` — `url()` image branch (~49-57), `ascii()` (~164-243)
- Modify: `scripts/yoda/yoda.php` — `yoda_cmd` (~22-43), `doubleyoda_cmd` (~83-89)
- Test: `tests/ImageGuardTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/ImageGuardTest.php`:

```php
<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../library/ImageGuard.php';

class ImageGuardTest extends TestCase
{
    public function test_bomb_header_rejected(): void
    {
        $body = file_get_contents(__DIR__ . '/fixtures/bomb_header_60000x60000.png');
        assert(is_string($body));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('too large');
        \ImageGuard::guardBody($body);
    }

    public function test_animated_bomb_rejected(): void
    {
        $body = file_get_contents(__DIR__ . '/fixtures/animated_bomb_10f_1600x1600.gif');
        assert(is_string($body));
        $this->expectException(\InvalidArgumentException::class);
        \ImageGuard::guardBody($body);
    }

    public function test_normal_image_passes(): void
    {
        $body = file_get_contents(__DIR__ . '/fixtures/200x100_blue.png');
        assert(is_string($body));
        \ImageGuard::guardBody($body);
        $this->assertTrue(true);
    }

    public function test_ping_reports_frame_aware_pixels(): void
    {
        $body = file_get_contents(__DIR__ . '/fixtures/animated_bomb_10f_1600x1600.gif');
        assert(is_string($body));
        $ping = \ImageGuard::ping($body);
        $this->assertTrue(\ImageGuard::oversize($ping));
        $ping->clear();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/ImageGuardTest.php`
Expected: FAIL — `ImageGuard` not found.

- [ ] **Step 3: Create `ImageGuard` and wire into the commands**

3a. Create `library/ImageGuard.php` (root namespace, same style as `library/Nicks.php`):

```php
<?php

/**
 * Guards against image decompression bombs before Imagick decodes them.
 * Mirrors the linktitles ai vision guard: pingImageBlob reads headers only,
 * total cost scales with pixels * frames.
 */
class ImageGuard
{
    public const maxPixels = 25000000;

    public static function ping(string $body): \Imagick
    {
        $ping = new \Imagick();
        $ping->pingImageBlob($body);
        return $ping;
    }

    public static function oversize(\Imagick $ping): bool
    {
        $w = $ping->getImageWidth();
        $h = $ping->getImageHeight();
        $frames = max(1, $ping->getNumberImages());
        return $w * $h * $frames > self::maxPixels;
    }

    /**
     * @throws \InvalidArgumentException when the image exceeds maxPixels
     */
    public static function guardBody(string $body, string $what = 'image'): void
    {
        $ping = self::ping($body);
        try {
            if (self::oversize($ping)) {
                $w = $ping->getImageWidth();
                $h = $ping->getImageHeight();
                throw new \InvalidArgumentException("{$what} too large {$w}x{$h} (max " . self::maxPixels . " pixels)");
            }
        } finally {
            $ping->clear();
        }
    }
}
```

3b. `library/async_get_contents.php` — add an optional byte cap (default 16MB protects all callers, including `@doubleyoda`):

```php
function async_get_contents(string $url, array $headers = [], int $maxBytes = 16777216): string {
    $client = HttpClientBuilder::buildDefault();
    $request = new Request($url);
    $request->setBodySizeLimit($maxBytes);
```

(rest of the function unchanged, docblock unchanged.)

3c. `artbot_scripts/urlimg.php` — add `require_once __DIR__ . '/../library/ImageGuard.php';` to the top `use` block area.

In `url()`: after `new Request($url)` (~line 33) add `$request->setBodySizeLimit(16 * 1024 * 1024);`. In the `if($type[0] == 'image')` branch, before `file_put_contents($filename, $body);` (~line 57), add:

```php
            ImageGuard::guardBody($body, 'image');
```

(The existing `catch (\Exception $error)` at ~line 130 already shows `URL Error: {message}` — the guard message becomes the visible error.)

In `ascii()`: after `new Request($url)` (~line 166) add `$request->setBodySizeLimit(16 * 1024 * 1024);`. Before `$img->readImageBlob($body);` (~line 200), add:

```php
        ImageGuard::guardBody($body, 'image');
```

And clamp the aspect-derived target height (~lines 232-242) before the sample buffers are computed:

```php
        $origSize = $img->getImageGeometry();
        $factor = $width / $origSize['width'];
        $targetW = (int)round($origSize['width'] * $factor);
        if($cmdArgs->optEnabled("--halfblock"))
            $targetH = (int)make_even(round($origSize['height'] * $factor));
        else
            $targetH = (int)round($origSize['height'] * $factor / 2);
        $targetH = min($targetH, 500);
        if($cmdArgs->optEnabled("--halfblock"))
            $targetH = (int)make_even($targetH);
```

3d. `scripts/yoda/yoda.php` — add `require_once __DIR__ . '/../../library/ImageGuard.php';` after the `use` block.

In `yoda_cmd`: after `new Request($url)` (~line 24) add `$request->setBodySizeLimit(16 * 1024 * 1024);`. After the status check, before `$img = new Imagick();` (~line 34), add:

```php
        try {
            ImageGuard::guardBody($body, 'image');
        } catch (\InvalidArgumentException $e) {
            $bot->pm($args->chan, $e->getMessage());
            return;
        }
```

In `doubleyoda_cmd`: after `$body = async_get_contents($url);` (~line 84), before `$img->readImageBlob($body);` (~line 86), add the same guard block as above.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/ImageGuardTest.php && php -l artbot_scripts/urlimg.php && php -l scripts/yoda/yoda.php && php -l library/async_get_contents.php`
Expected: tests pass, lint clean.

- [ ] **Step 5: Commit**

`git add library/ImageGuard.php library/async_get_contents.php artbot_scripts/urlimg.php scripts/yoda/yoda.php tests/ImageGuardTest.php && git commit -m "fix(urlimg,yoda): ping-first pixel guard + body size caps (decompression bombs)"`

---

### Task 8: FontManager cache cap (fc-match process spam)

**Files:**
- Modify: `library/draw/FontManager.php` (~line 46-67)
- Test: `tests/Canvas/FontManagerCacheCapTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Canvas/FontManagerCacheCapTest.php`:

```php
<?php

namespace Tests\Canvas;

use draw\FontManager;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

class FontManagerCacheCapTest extends TestCase
{
    public function test_full_cache_returns_null_without_spawning_fc_match(): void
    {
        $prop = new \ReflectionProperty(FontManager::class, 'pathCache');
        $dummy = [];
        for ($i = 0; $i < 128; $i++) {
            $dummy["font$i||"] = '/nonexistent/font' . $i . '.ttf';
        }
        $prop->setValue(null, $dummy);

        $method = new \ReflectionMethod(FontManager::class, 'resolveFontPath');
        $result = $method->invoke(null, 'brand-new-bomb-font', null, null);

        $this->assertNull($result);
        $this->assertCount(128, $prop->getValue());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Canvas/FontManagerCacheCapTest.php`
Expected: FAIL — cache grows to 129 (fc-match spawned and cached a path).

- [ ] **Step 3: Implement the cap**

In `library/draw/FontManager.php`, add a constant and early return in `resolveFontPath`:

```php
class FontManager
{
    private const MAX_PATH_CACHE = 128;
```

```php
    private static function resolveFontPath(string $fontFamily, ?string $weight, ?string $style): ?string
    {
        $cacheKey = $fontFamily . '|' . ($weight ?? '') . '|' . ($style ?? '');

        if (array_key_exists($cacheKey, self::$pathCache)) {
            return self::$pathCache[$cacheKey];
        }

        if (count(self::$pathCache) >= self::MAX_PATH_CACHE) {
            return null;
        }
```

(rest unchanged — the `return null` makes `resolve()` fall back to the default font.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Canvas/FontManagerCacheCapTest.php && vendor/bin/phpunit tests/Canvas/`
Expected: all pass.

- [ ] **Step 5: Commit**

`git add library/draw/FontManager.php tests/Canvas/FontManagerCacheCapTest.php && git commit -m "fix(draw): cap FontManager path cache (fc-match process spam)"`

---

### Task 9: Final verification

**Files:** none (verification only)

- [ ] **Step 1: Full test suite**

Run: `composer test`
Expected: OK (allow the pre-existing time-of-day Duration skips and the one vendor deprecation; no failures).

- [ ] **Step 2: Scoped phpstan — no NEW errors**

Run: `vendor/bin/phpstan analyse library/draw/ artbot_scripts/svg.php artbot_scripts/rain.php artbot_scripts/urlimg.php scripts/yoda/yoda.php library/ImageGuard.php library/async_get_contents.php tests/Canvas/CreateBlankLimitsTest.php tests/Canvas/RasterizerBombsTest.php tests/Canvas/SVGParserStructuralLimitsTest.php tests/Canvas/SVGParserClampsTest.php tests/Canvas/FontManagerCacheCapTest.php tests/Artbot/ tests/ImageGuardTest.php --no-progress --memory-limit=1G 2>&1 | tail -5`
Compare error count against `/tmp/phpstan-baseline.txt`; new files must be clean, modified files must not add errors.

- [ ] **Step 3: Formatting**

Run: `vendor/bin/php-cs-fixer fix library/draw/RenderLimits.php library/draw/Canvas.php library/draw/SVGParser.php library/draw/GaussianBlurPrimitive.php library/draw/FontManager.php library/ImageGuard.php library/async_get_contents.php artbot_scripts/svg.php artbot_scripts/rain.php artbot_scripts/urlimg.php scripts/yoda/yoda.php tests/Canvas/CreateBlankLimitsTest.php tests/Canvas/RasterizerBombsTest.php tests/Canvas/SVGParserStructuralLimitsTest.php tests/Canvas/SVGParserClampsTest.php tests/Canvas/FontManagerCacheCapTest.php tests/Artbot/SvgComputeRenderSizeTest.php tests/Artbot/RainClampCopyTest.php tests/ImageGuardTest.php`
If php-cs-fixer auto-creates `.php-cs-fixer.dist.php` in the repo root, DELETE it and never commit it. Re-run `composer test` if the fixer changed anything.

- [ ] **Step 4: Verify no stray files and final commit if needed**

Run: `git status --short`
Expected: only intended files. Commit any fixer adjustments: `git add -A && git commit -m "style: cs-fixer on hardening files"` (only if there are changes; do not commit `.php-cs-fixer.dist.php`).
