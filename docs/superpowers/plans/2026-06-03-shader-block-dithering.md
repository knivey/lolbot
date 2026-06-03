# Shader Block Dithering Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace spatial dithering with shade characters (░▒▓) when both pixels in a halfblock pair are dithered, preserving sharp ▀ rendering for solid art.

**Architecture:** Add dithering metadata to Pixel (secondBest, t). Add `nearestColorWithMeta()` to IrcPalette returning a value object. Canvas quantization sites store metadata. Canvas `__toString()` uses metadata to choose shade chars vs ▀.

**Tech Stack:** PHP 8.1+, PHPUnit 10

---

### Task 1: DitherResult Value Object

**Files:**
- Create: `library/draw/DitherResult.php`
- Test: `tests/Canvas/DitherResultTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Canvas;

use draw\DitherResult;
use PHPUnit\Framework\TestCase;

class DitherResultTest extends TestCase
{
    public function test_stores_code(): void
    {
        $r = new DitherResult(4);
        $this->assertSame(4, $r->code);
    }

    public function test_defaults_for_non_dithered(): void
    {
        $r = new DitherResult(4);
        $this->assertFalse($r->dithered);
        $this->assertSame(-1, $r->secondBest);
        $this->assertSame(0.0, $r->t);
    }

    public function test_stores_dithering_metadata(): void
    {
        $r = new DitherResult(4, dithered: true, secondBest: 40, t: 0.5);
        $this->assertTrue($r->dithered);
        $this->assertSame(40, $r->secondBest);
        $this->assertSame(0.5, $r->t);
    }

    public function test_readonly(): void
    {
        $r = new DitherResult(4);
        $this->expectException(\Error::class);
        $r->code = 5;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Canvas/DitherResultTest.php`
Expected: FAIL (class not found)

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace draw;

readonly class DitherResult
{
    public function __construct(
        public int $code,
        public bool $dithered = false,
        public int $secondBest = -1,
        public float $t = 0.0,
    ) {}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- tests/Canvas/DitherResultTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add library/draw/DitherResult.php tests/Canvas/DitherResultTest.php
git commit -m "feat(draw): add DitherResult value object"
```

---

### Task 2: Add dithering metadata to Pixel

**Files:**
- Modify: `library/draw/Pixel.php`
- Modify: `tests/Canvas/PixelTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Canvas/PixelTest.php`:

```php
public function test_default_dithered_is_false(): void
{
    $p = new Pixel();
    $this->assertFalse($p->dithered);
}

public function test_default_secondBest_is_negative_one(): void
{
    $p = new Pixel();
    $this->assertSame(-1, $p->secondBest);
}

public function test_default_t_is_zero(): void
{
    $p = new Pixel();
    $this->assertSame(0.0, $p->t);
}

public function test_dithering_properties_can_be_set(): void
{
    $p = new Pixel();
    $p->dithered = true;
    $p->secondBest = 40;
    $p->t = 0.5;
    $this->assertTrue($p->dithered);
    $this->assertSame(40, $p->secondBest);
    $this->assertSame(0.5, $p->t);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Canvas/PixelTest.php`
Expected: FAIL (property does not exist)

- [ ] **Step 3: Write minimal implementation**

Add to `library/draw/Pixel.php` after `public string $text = ' ';`:

```php
public bool $dithered = false;
public int $secondBest = -1;
public float $t = 0.0;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- tests/Canvas/PixelTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add library/draw/Pixel.php tests/Canvas/PixelTest.php
git commit -m "feat(draw): add dithering metadata fields to Pixel"
```

---

### Task 3: Add `nearestColorWithMeta()` to IrcPalette

**Files:**
- Modify: `library/draw/IrcPalette.php`
- Modify: `tests/Canvas/IrcPaletteTest.php`

This method extracts the core logic from `nearestColorDithered()` into a method that returns a `DitherResult` instead of just an int. The existing `nearestColor()` and `nearestColorDithered()` are refactored to delegate to it.

- [ ] **Step 1: Write the failing test**

Add to `tests/Canvas/IrcPaletteTest.php`:

```php
use draw\DitherResult;

public function test_nearestColorWithMeta_no_dithering_returns_not_dithered(): void
{
    $result = IrcPalette::nearestColorWithMeta(255, 0, 0, Dithering::None);
    $this->assertSame(4, $result->code);
    $this->assertFalse($result->dithered);
}

public function test_nearestColorWithMeta_exact_match_not_dithered(): void
{
    $result = IrcPalette::nearestColorWithMeta(255, 0, 0, Dithering::Ordered4x4, 0, 0);
    $this->assertSame(4, $result->code);
    $this->assertFalse($result->dithered);
}

public function test_nearestColorWithMeta_mid_color_is_dithered(): void
{
    $result = IrcPalette::nearestColorWithMeta(128, 50, 50, Dithering::Ordered4x4, 0, 0);
    $this->assertGreaterThanOrEqual(0, $result->code);
    $this->assertLessThanOrEqual(98, $result->code);
    $this->assertTrue($result->dithered);
    $this->assertGreaterThanOrEqual(0, $result->secondBest);
    $this->assertLessThanOrEqual(98, $result->secondBest);
    $this->assertNotSame($result->code, $result->secondBest);
    $this->assertGreaterThan(0, $result->t);
    $this->assertLessThanOrEqual(1, $result->t);
}

public function test_nearestColorWithMeta_t_varies_with_bayer_position(): void
{
    $results = [];
    for ($y = 0; $y < 4; $y++) {
        for ($x = 0; $x < 4; $x++) {
            $r = IrcPalette::nearestColorWithMeta(128, 50, 50, Dithering::Ordered4x4, $x, $y);
            $results[] = $r->code;
        }
    }
    $unique = array_unique($results);
    $this->assertGreaterThanOrEqual(2, count($unique));
}

public function test_nearestColorWithMeta_code_matches_nearestColor(): void
{
    $r = 128;
    $g = 50;
    $b = 50;
    foreach (Dithering::cases() as $mode) {
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $plain = IrcPalette::nearestColor($r, $g, $b, $mode, $x, $y);
                $meta = IrcPalette::nearestColorWithMeta($r, $g, $b, $mode, $x, $y);
                $this->assertSame($plain, $meta->code, "Mismatch at mode=$mode->name x=$x y=$y");
            }
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Canvas/IrcPaletteTest.php`
Expected: FAIL (method does not exist)

- [ ] **Step 3: Write minimal implementation**

Add `use draw\DitherResult;` to the imports in `library/draw/IrcPalette.php`.

Refactor `nearestColorDithered()` to extract the core into `nearestColorDitheredMeta()` which returns `DitherResult`. Then `nearestColorDithered()` delegates to it and returns `->code`. The new public method `nearestColorWithMeta()` delegates to the appropriate path.

Replace `nearestColorDithered()` with:

```php
public static function nearestColorWithMeta(int $r, int $g, int $b, Dithering $mode = Dithering::None, int $x = 0, int $y = 0): DitherResult
{
    if ($mode === Dithering::Ordered4x4) {
        return self::nearestColorDitheredMeta($r, $g, $b, $x, $y);
    }

    $key = ($r << 16) | ($g << 8) | $b;
    if (isset(self::$nearestCache[$key])) {
        return new DitherResult(self::$nearestCache[$key]);
    }

    self::$colorPalette ??= self::buildColorPalette();
    $target = new Color(new RGB($r, $g, $b));
    $bestIdx = 0;
    $bestDist = INF;
    foreach (self::$colorPalette as $idx => $palColor) {
        $d = $target->getDifferenceDin99($palColor);
        if ($d < $bestDist) {
            $bestIdx = $idx;
            $bestDist = $d;
        }
    }
    self::$nearestCache[$key] = $bestIdx;
    if (count(self::$nearestCache) > self::CACHE_LIMIT) {
        self::$nearestCache = [];
    }
    return new DitherResult($bestIdx);
}

private static function nearestColorDitheredMeta(int $r, int $g, int $b, int $x, int $y): DitherResult
{
    self::$colorPalette ??= self::buildColorPalette();
    $target = new Color(new RGB($r, $g, $b));

    $bestIdx = 0;
    $bestDist = INF;
    $secondIdx = -1;
    $secondDist = INF;
    foreach (self::$colorPalette as $idx => $palColor) {
        $d = $target->getDifferenceDin99($palColor);
        if ($d < $bestDist) {
            $secondIdx = $bestIdx;
            $secondDist = $bestDist;
            $bestIdx = $idx;
            $bestDist = $d;
        } elseif ($d < $secondDist) {
            $secondIdx = $idx;
            $secondDist = $d;
        }
    }

    if ($secondIdx === -1) {
        return new DitherResult($bestIdx);
    }

    if ($bestDist < 0.001) {
        return new DitherResult($bestIdx);
    }

    self::$rgbPalette ??= self::buildRgbPalette();
    $br = self::$rgbPalette[$bestIdx];

    $secondIdx = self::findDitherCandidate($target, $br, $bestIdx, $r, $g, $b, $secondIdx);
    if ($secondIdx === -1) {
        return new DitherResult($bestIdx);
    }

    $sr = self::$rgbPalette[$secondIdx];
    $dr = $sr[0] - $br[0];
    $dg = $sr[1] - $br[1];
    $db = $sr[2] - $br[2];
    $lenSq = $dr * $dr + $dg * $dg + $db * $db;
    if ($lenSq < 0.001) {
        return new DitherResult($bestIdx);
    }
    $ir = $r - $br[0];
    $ig = $g - $br[1];
    $ib = $b - $br[2];
    $t = ($ir * $dr + $ig * $dg + $ib * $db) / $lenSq;
    $t = max(0.0, min(1.0, $t));

    $bayer = self::BAYER_4X4[$y & 3][$x & 3];
    $threshold = ($bayer + 0.5) / 16.0;

    if ($t >= $threshold) {
        return new DitherResult($secondIdx, dithered: true, secondBest: $bestIdx, t: 1.0 - $t);
    }
    return new DitherResult($bestIdx, dithered: true, secondBest: $secondIdx, t: $t);
}
```

Then update `nearestColorDithered()` to delegate:

```php
private static function nearestColorDithered(int $r, int $g, int $b, int $x, int $y): int
{
    return self::nearestColorDitheredMeta($r, $g, $b, $x, $y)->code;
}
```

Key detail in `nearestColorDitheredMeta()`: when the Bayer threshold picks the second-best color (t >= threshold), the result's `code` is the second-best, `secondBest` is the best, and `t` is inverted to `1.0 - $t` so the renderer knows how far the chosen color is from the other option. When the best is chosen, `t` stays as-is (how far toward second-best the input was).

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- tests/Canvas/IrcPaletteTest.php`
Expected: PASS

- [ ] **Step 5: Run full test suite**

Run: `composer test`
Expected: PASS (294+ tests, existing tests unchanged since `nearestColor()` still works identically)

- [ ] **Step 6: Commit**

```bash
git add library/draw/IrcPalette.php tests/Canvas/IrcPaletteTest.php
git commit -m "feat(draw): add nearestColorWithMeta() returning DitherResult"
```

---

### Task 4: Canvas quantization stores dithering metadata

**Files:**
- Modify: `library/draw/Canvas.php`
- Modify: `tests/Canvas/CanvasTest.php`

The three quantization sites (drawPoint line ~244, drawLineInternal line ~333, fillPolygonScanlineMulti fillSpan closure line ~605) need to call `nearestColorWithMeta()` when dithering is active and store the metadata on the Pixel.

- [ ] **Step 1: Write the failing test**

Add to `tests/Canvas/CanvasTest.php`:

```php
use draw\Dithering;
use draw\LinearGradient;
use draw\ColorStop;

public function test_drawPoint_stores_dithering_metadata_when_enabled(): void
{
    $canvas = Canvas::createBlank(10, 10, true);
    $canvas->setDithering(Dithering::Ordered4x4);

    $grad = new LinearGradient(0, 0, 9, 0, [
        new ColorStop(0.0, 255, 0, 0),
        new ColorStop(1.0, 0, 0, 255),
    ]);

    $canvas->drawPoint(5, 5, $grad);

    $pixel = $canvas->getPixel(5, 5);
    $this->assertNotNull($pixel->fg);
    $this->assertTrue($pixel->dithered);
    $this->assertGreaterThanOrEqual(0, $pixel->secondBest);
    $this->assertGreaterThan(0, $pixel->t);
}

public function test_drawPoint_no_metadata_without_dithering(): void
{
    $canvas = Canvas::createBlank(10, 10, true);

    $grad = new LinearGradient(0, 0, 9, 0, [
        new ColorStop(0.0, 255, 0, 0),
        new ColorStop(1.0, 0, 0, 255),
    ]);

    $canvas->drawPoint(5, 5, $grad);

    $pixel = $canvas->getPixel(5, 5);
    $this->assertNotNull($pixel->fg);
    $this->assertFalse($pixel->dithered);
    $this->assertSame(-1, $pixel->secondBest);
    $this->assertSame(0.0, $pixel->t);
}

public function test_drawPoint_solid_color_no_metadata(): void
{
    $canvas = Canvas::createBlank(10, 10, true);
    $canvas->setDithering(Dithering::Ordered4x4);

    $canvas->drawPoint(5, 5, new Color(4));

    $pixel = $canvas->getPixel(5, 5);
    $this->assertSame(4, $pixel->fg);
    $this->assertFalse($pixel->dithered);
}
```

Note: `getPixel()` is a helper that needs to be added to Canvas for test access. If it doesn't exist, add:

```php
public function getPixel(int $x, int $y): Pixel
{
    return $this->data[$y][$x] ?? new Pixel();
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Canvas/CanvasTest.php`
Expected: FAIL (getPixel doesn't exist or dithered not set)

- [ ] **Step 3: Add getPixel helper to Canvas**

Add to `library/draw/Canvas.php`:

```php
public function getPixel(int $x, int $y): Pixel
{
    return $this->data[$y][$x] ?? new Pixel();
}
```

- [ ] **Step 4: Update the three quantization sites**

Each site currently has this pattern:

```php
if ($paint->isSolid() && $paint instanceof Color) {
    $this->data[$y][$x]->fg = $paint->fg;
    $this->data[$y][$x]->bg = $paint->bg;
} else {
    $effectiveDithering = $paint->getDithering() ?? $this->dithering;
    [$r, $g, $b] = $paint->getColorAt((float) $x, (float) $y);
    $this->data[$y][$x]->fg = IrcPalette::nearestColor($r, $g, $b, $effectiveDithering, $x, $y);
    $this->data[$y][$x]->bg = null;
}
```

Change the else branch to:

```php
$effectiveDithering = $paint->getDithering() ?? $this->dithering;
[$r, $g, $b] = $paint->getColorAt((float) $x, (float) $y);
if ($effectiveDithering !== Dithering::None) {
    $result = IrcPalette::nearestColorWithMeta($r, $g, $b, $effectiveDithering, $x, $y);
    $this->data[$y][$x]->fg = $result->code;
    $this->data[$y][$x]->dithered = $result->dithered;
    $this->data[$y][$x]->secondBest = $result->secondBest;
    $this->data[$y][$x]->t = $result->t;
} else {
    $this->data[$y][$x]->fg = IrcPalette::nearestColor($r, $g, $b);
}
$this->data[$y][$x]->bg = null;
```

Apply this to all three sites:
- `drawPoint()` (~line 241-245)
- `drawLineInternal()` (~line 330-334)
- fillSpan closure in `fillPolygonScanlineMulti()` (~line 602-606)

- [ ] **Step 5: Run test to verify it passes**

Run: `composer test -- tests/Canvas/CanvasTest.php`
Expected: PASS

- [ ] **Step 6: Run full test suite**

Run: `composer test`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add library/draw/Canvas.php tests/Canvas/CanvasTest.php
git commit -m "feat(draw): store dithering metadata on pixels during quantization"
```

---

### Task 5: Shader block rendering in `__toString()`

**Files:**
- Modify: `library/draw/Canvas.php` (`__toString()` method)
- Modify: `tests/Canvas/CanvasTest.php`

This is the core rendering change. In the halfblock path of `__toString()`, when both pixel1 and pixel2 are dithered, use a shade character instead of `▀`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Canvas/CanvasTest.php`:

```php
public function test_toString_uses_shade_chars_when_both_pixels_dithered(): void
{
    $canvas = Canvas::createBlank(4, 2, true);
    $canvas->fillColor(0, 0, new Color(1, 1));

    $pixel1 = $canvas->getPixel(0, 0);
    $pixel1->fg = 4;
    $pixel1->dithered = true;
    $pixel1->secondBest = 40;
    $pixel1->t = 0.5;

    $pixel2 = $canvas->getPixel(0, 1);
    $pixel2->fg = 12;
    $pixel2->dithered = true;
    $pixel2->secondBest = 48;
    $pixel2->t = 0.3;

    $output = (string) $canvas;
    $this->assertStringContainsString('▒', $output);
    $this->assertStringNotContainsString('▀', $output);
}

public function test_toString_uses_halfblock_when_only_one_pixel_dithered(): void
{
    $canvas = Canvas::createBlank(4, 2, true);
    $canvas->fillColor(0, 0, new Color(1, 1));

    $pixel1 = $canvas->getPixel(0, 0);
    $pixel1->fg = 4;
    $pixel1->dithered = true;
    $pixel1->secondBest = 40;
    $pixel1->t = 0.5;

    $pixel2 = $canvas->getPixel(0, 1);
    $pixel2->fg = 12;

    $output = (string) $canvas;
    $this->assertStringContainsString('▀', $output);
}

public function test_toString_uses_halfblock_when_neither_pixel_dithered(): void
{
    $canvas = Canvas::createBlank(4, 2, true);
    $canvas->fillColor(0, 0, new Color(1, 1));

    $pixel1 = $canvas->getPixel(0, 0);
    $pixel1->fg = 4;

    $pixel2 = $canvas->getPixel(0, 1);
    $pixel2->fg = 12;

    $output = (string) $canvas;
    $this->assertStringContainsString('▀', $output);
}

public function test_shade_char_selection_by_avg_t(): void
{
    $cases = [
        [0.1, 0.2, '░'],
        [0.3, 0.4, '▒'],
        [0.5, 0.5, '▒'],
        [0.6, 0.7, '▓'],
        [0.9, 0.8, '▓'],
    ];
    foreach ($cases as [$t1, $t2, $expectedChar]) {
        $canvas = Canvas::createBlank(4, 2, true);
        $canvas->fillColor(0, 0, new Color(1, 1));

        $pixel1 = $canvas->getPixel(0, 0);
        $pixel1->fg = 4;
        $pixel1->dithered = true;
        $pixel1->secondBest = 40;
        $pixel1->t = $t1;

        $pixel2 = $canvas->getPixel(0, 1);
        $pixel2->fg = 12;
        $pixel2->dithered = true;
        $pixel2->secondBest = 48;
        $pixel2->t = $t2;

        $output = (string) $canvas;
        $this->assertStringContainsString($expectedChar, $output, "avg t=" . (($t1 + $t2) / 2) . " should use '$expectedChar'");
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Canvas/CanvasTest.php`
Expected: FAIL (shade chars not in output)

- [ ] **Step 3: Implement shader block rendering**

In `Canvas::__toString()`, in the halfblock path, replace the character selection logic at lines 115-119.

Change:
```php
if ($pixel1->fg !== $pixel2->fg) {
    $out .= $hb;
} else {
    $out .= " ";
}
```

To:
```php
if ($pixel1->fg !== $pixel2->fg) {
    if ($pixel1->dithered && $pixel2->dithered) {
        $avgT = ($pixel1->t + $pixel2->t) / 2.0;
        if ($avgT < 0.33) {
            $out .= "░";
        } elseif ($avgT < 0.66) {
            $out .= "▒";
        } else {
            $out .= "▓";
        }
    } else {
        $out .= $hb;
    }
} else {
    $out .= " ";
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- tests/Canvas/CanvasTest.php`
Expected: PASS

- [ ] **Step 5: Run full test suite**

Run: `composer test`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add library/draw/Canvas.php tests/Canvas/CanvasTest.php
git commit -m "feat(draw): use shade chars when both halfblock pixels are dithered"
```

---

### Task 6: End-to-end gradient demo test

**Files:**
- Modify: `tests/Canvas/CanvasTest.php`

Verify that drawing a gradient with dithering enabled produces shade characters in the output.

- [ ] **Step 1: Write the test**

```php
public function test_gradient_with_dithering_produces_shade_chars(): void
{
    $canvas = Canvas::createBlank(80, 48, true);
    $canvas->fillColor(0, 0, new Color(1, 1));
    $canvas->setDithering(Dithering::Ordered4x4);

    $grad = new LinearGradient(0, 0, 0, 47, [
        new ColorStop(0.0, 255, 0, 0),
        new ColorStop(1.0, 0, 0, 255),
    ]);
    $canvas->drawPath(Path::rect(0, 0, 80, 48), $grad, null);

    $output = (string) $canvas;
    $this->assertStringContainsString('░', $output);
    $this->assertStringContainsString('▒', $output);
    $this->assertStringContainsString('▓', $output);
}

public function test_gradient_without_dithering_no_shade_chars(): void
{
    $canvas = Canvas::createBlank(80, 48, true);
    $canvas->fillColor(0, 0, new Color(1, 1));

    $grad = new LinearGradient(0, 0, 0, 47, [
        new ColorStop(0.0, 255, 0, 0),
        new ColorStop(1.0, 0, 0, 255),
    ]);
    $canvas->drawPath(Path::rect(0, 0, 80, 48), $grad, null);

    $output = (string) $canvas;
    $this->assertStringNotContainsString('░', $output);
    $this->assertStringNotContainsString('▒', $output);
    $this->assertStringNotContainsString('▓', $output);
}
```

- [ ] **Step 2: Run test**

Run: `composer test -- tests/Canvas/CanvasTest.php`
Expected: PASS

- [ ] **Step 3: Run full test suite**

Run: `composer test`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add tests/Canvas/CanvasTest.php
git commit -m "test(draw): end-to-end gradient shade char tests"
```
