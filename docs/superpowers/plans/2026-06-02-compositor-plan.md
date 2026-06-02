# Compositor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add source-over alpha compositing to the draw library via a separate Compositor class, with per-pixel fg/bg alpha channels and IRC palette RGB lookup/quantization.

**Architecture:** Pixel gains `fgAlpha`/`bgAlpha` floats. A new `IrcPalette` class maps IRC color codes (0–98) to/from RGB using the `Itwmw\ColorDifference` library. A new `Compositor` class blends a source canvas onto a destination canvas using source-over compositing in RGB space, quantizing results back to IRC colors. Existing rendering code is unchanged.

**Tech Stack:** PHP 8.1+, PHPUnit 10, `Itwmw\ColorDifference` (existing dependency)

**Spec:** `docs/superpowers/specs/2026-06-02-compositor-design.md`

---

### Task 1: Add fgAlpha and bgAlpha to Pixel

**Files:**
- Modify: `library/draw/Pixel.php`
- Test: `tests/Canvas/PixelTest.php` (create)

- [ ] **Step 1: Write failing tests for Pixel alpha**

Create `tests/Canvas/PixelTest.php`:

```php
<?php

namespace Tests\Canvas;

use draw\Pixel;
use PHPUnit\Framework\TestCase;

class PixelTest extends TestCase
{
    public function test_default_alpha_is_fully_opaque(): void
    {
        $p = new Pixel();
        $this->assertSame(1.0, $p->fgAlpha);
        $this->assertSame(1.0, $p->bgAlpha);
    }

    public function test_alpha_can_be_set_and_read(): void
    {
        $p = new Pixel();
        $p->fgAlpha = 0.5;
        $p->bgAlpha = 0.25;
        $this->assertSame(0.5, $p->fgAlpha);
        $this->assertSame(0.25, $p->bgAlpha);
    }

    public function test_existing_properties_unchanged(): void
    {
        $p = new Pixel();
        $this->assertNull($p->fg);
        $this->assertNull($p->bg);
        $this->assertSame(' ', $p->text);

        $p->fg = 4;
        $p->bg = 12;
        $p->text = '█';
        $this->assertSame(4, $p->fg);
        $this->assertSame(12, $p->bg);
        $this->assertSame('█', $p->text);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Canvas/PixelTest.php`
Expected: FAIL — `Undefined property: draw\Pixel::$fgAlpha` (or similar)

- [ ] **Step 3: Add alpha properties to Pixel**

In `library/draw/Pixel.php`, add two public float properties:

```php
<?php

namespace draw;

class Pixel
{
    public ?int $fg = null;
    public ?int $bg = null;
    public float $fgAlpha = 1.0;
    public float $bgAlpha = 1.0;
    public string $text = ' ';

    public function __toString(): string
    {
        //can't do colors here because it doesnt know whats before it
        return $this->text;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- tests/Canvas/PixelTest.php`
Expected: PASS

- [ ] **Step 5: Run full test suite to confirm no regressions**

Run: `composer test`
Expected: All existing tests still pass (alpha defaults to 1.0, so behavior is unchanged)

- [ ] **Step 6: Commit**

```bash
git add library/draw/Pixel.php tests/Canvas/PixelTest.php
git commit -m "Add fgAlpha/bgAlpha to Pixel for compositor support"
```

---

### Task 2: Create IrcPalette — RGB lookup and nearest-color matching

**Files:**
- Create: `library/draw/IrcPalette.php`
- Test: `tests/Canvas/IrcPaletteTest.php` (create)

- [ ] **Step 1: Write failing tests for IrcPalette**

Create `tests/Canvas/IrcPaletteTest.php`:

```php
<?php

namespace Tests\Canvas;

use draw\IrcPalette;
use PHPUnit\Framework\TestCase;

class IrcPaletteTest extends TestCase
{
    public function test_getRgb_returns_correct_values_for_known_colors(): void
    {
        // Index 0: white
        $this->assertSame([255, 255, 255], IrcPalette::getRgb(0));
        // Index 1: black
        $this->assertSame([0, 0, 0], IrcPalette::getRgb(1));
        // Index 4: red
        $this->assertSame([255, 0, 0], IrcPalette::getRgb(4));
    }

    public function test_getRgb_returns_extended_colors(): void
    {
        // Index 16: #470000
        $this->assertSame([0x47, 0x00, 0x00], IrcPalette::getRgb(16));
        // Index 52: #b50000
        $this->assertSame([0xb5, 0x00, 0x00], IrcPalette::getRgb(52));
    }

    public function test_getRgb_returns_grayscale(): void
    {
        // Index 88: #000000 (black again)
        $this->assertSame([0, 0, 0], IrcPalette::getRgb(88));
        // Index 98: #ffffff (white again)
        $this->assertSame([255, 255, 255], IrcPalette::getRgb(98));
    }

    public function test_getRgb_throws_on_invalid_code(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IrcPalette::getRgb(99);
    }

    public function test_getRgb_throws_on_negative_code(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IrcPalette::getRgb(-1);
    }

    public function test_nearestColor_finds_exact_match(): void
    {
        // Pure red should match index 4 (255,0,0)
        $this->assertSame(4, IrcPalette::nearestColor(255, 0, 0));
    }

    public function test_nearestColor_finds_close_match(): void
    {
        // Slightly off-white should still find white (index 0)
        $this->assertSame(0, IrcPalette::nearestColor(254, 254, 254));
    }

    public function test_nearestColor_finds_black(): void
    {
        // Very dark should find black (index 1)
        $this->assertSame(1, IrcPalette::nearestColor(1, 1, 1));
    }

    public function test_nearestColor_finds_mid_gray(): void
    {
        // Index 14 is #7F7F7F (grey)
        $this->assertSame(14, IrcPalette::nearestColor(127, 127, 127));
    }

    public function test_getColor_returns_color_object(): void
    {
        $color = IrcPalette::getColor(4);
        $rgb = $color->getRgb();
        $this->assertSame(255, $rgb->R);
        $this->assertSame(0, $rgb->G);
        $this->assertSame(0, $rgb->B);
    }

    public function test_palette_has_99_entries(): void
    {
        // Verify we can access index 0 through 98 without error
        $lastRgb = IrcPalette::getRgb(98);
        $this->assertSame([255, 255, 255], $lastRgb);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Canvas/IrcPaletteTest.php`
Expected: FAIL — `Class "draw\IrcPalette" not found`

- [ ] **Step 3: Implement IrcPalette**

Create `library/draw/IrcPalette.php`:

```php
<?php

namespace draw;

use Itwmw\ColorDifference\Color;
use Itwmw\ColorDifference\Lib\RGB;

class IrcPalette
{
    private static ?array $hexPalette = null;

    /** @var array<int, Color>|null */
    private static ?array $colorPalette = null;

    /** @var array<int, array{int, int, int}>|null */
    private static ?array $rgbPalette = null;

    /**
     * @return array<int, string>
     */
    private static function getHexPalette(): array
    {
        if (self::$hexPalette === null) {
            self::$hexPalette = [
                '#FFFFFF', '#000000', '#00007F', '#009300',
                '#FF0000', '#7F0000', '#9C009C', '#FC7F00',
                '#FFFF00', '#00FC00', '#009393', '#00FFFF',
                '#0000FC', '#FF00FF', '#7F7F7F', '#D2D2D2',
                '#470000', '#472100', '#474700', '#324700',
                '#004700', '#00472c', '#004747', '#002747',
                '#000047', '#2e0047', '#470047', '#47002a',
                '#740000', '#743a00', '#747400', '#517400',
                '#007400', '#007449', '#007474', '#004074',
                '#000074', '#4b0074', '#740074', '#740045',
                '#b50000', '#b56300', '#b5b500', '#7db500',
                '#00b500', '#00b571', '#00b5b5', '#0063b5',
                '#0000b5', '#7500b5', '#b500b5', '#b5006b',
                '#ff0000', '#ff8c00', '#ffff00', '#b2ff00',
                '#00ff00', '#00ffa0', '#00ffff', '#008cff',
                '#0000ff', '#a500ff', '#ff00ff', '#ff0098',
                '#ff5959', '#ffb459', '#ffff71', '#cfff60',
                '#6fff6f', '#65ffc9', '#6dffff', '#59b4ff',
                '#5959ff', '#c459ff', '#ff66ff', '#ff59bc',
                '#ff9c9c', '#ffd39c', '#ffff9c', '#e2ff9c',
                '#9cff9c', '#9cffdb', '#9cffff', '#9cd3ff',
                '#9c9cff', '#dc9cff', '#ff9cff', '#ff94d3',
                '#000000', '#131313', '#282828', '#363636',
                '#4d4d4d', '#656565', '#818181', '#9f9f9f',
                '#bcbcbc', '#e2e2e2', '#ffffff',
            ];
        }
        return self::$hexPalette;
    }

    /**
     * @return array{int, int, int}
     */
    public static function getRgb(int $ircCode): array
    {
        if ($ircCode < 0 || $ircCode > 98) {
            throw new \InvalidArgumentException("IRC color code must be 0-98, got $ircCode");
        }
        if (self::$rgbPalette === null) {
            self::buildRgbPalette();
        }
        return self::$rgbPalette[$ircCode];
    }

    public static function getColor(int $ircCode): Color
    {
        if ($ircCode < 0 || $ircCode > 98) {
            throw new \InvalidArgumentException("IRC color code must be 0-98, got $ircCode");
        }
        if (self::$colorPalette === null) {
            self::buildColorPalette();
        }
        return self::$colorPalette[$ircCode];
    }

    public static function nearestColor(int $r, int $g, int $b): int
    {
        if (self::$colorPalette === null) {
            self::buildColorPalette();
        }
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
        return $bestIdx;
    }

    private static function buildRgbPalette(): void
    {
        self::$rgbPalette = [];
        foreach (self::getHexPalette() as $idx => $hex) {
            $r = hexdec(substr($hex, 1, 2));
            $g = hexdec(substr($hex, 3, 2));
            $b = hexdec(substr($hex, 5, 2));
            self::$rgbPalette[$idx] = [(int) $r, (int) $g, (int) $b];
        }
    }

    private static function buildColorPalette(): void
    {
        self::$colorPalette = [];
        foreach (self::getHexPalette() as $idx => $hex) {
            self::$colorPalette[$idx] = new Color($hex);
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- tests/Canvas/IrcPaletteTest.php`
Expected: PASS

- [ ] **Step 5: Run full test suite to confirm no regressions**

Run: `composer test`
Expected: All existing tests still pass

- [ ] **Step 6: Commit**

```bash
git add library/draw/IrcPalette.php tests/Canvas/IrcPaletteTest.php
git commit -m "Add IrcPalette: IRC color code to RGB lookup and nearest-color matching"
```

---

### Task 3: Create Compositor — source-over blending

**Files:**
- Create: `library/draw/Compositor.php`
- Test: `tests/Canvas/CompositorTest.php` (create)

- [ ] **Step 1: Write failing tests for Compositor**

Create `tests/Canvas/CompositorTest.php`:

```php
<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\Color;
use draw\Compositor;
use draw\Path;
use draw\StrokeStyle;
use PHPUnit\Framework\TestCase;

class CompositorTest extends TestCase
{
    public function test_blend_throws_on_size_mismatch(): void
    {
        $dst = Canvas::createBlank(10, 10);
        $src = Canvas::createBlank(5, 5);
        $this->expectException(\InvalidArgumentException::class);
        Compositor::blend($dst, $src);
    }

    public function test_blend_full_opacity_same_as_overlay(): void
    {
        $dst = Canvas::createBlank(10, 10);
        $src = Canvas::createBlank(10, 10);

        $src->drawPoint(5, 5, new Color(4, null));

        Compositor::blend($dst, $src, 1.0);

        $this->assertSame(4, $dst->data[5][5]->fg);
    }

    public function test_blend_skips_empty_source_pixels(): void
    {
        $dst = Canvas::createBlank(10, 10);
        $dst->drawPoint(5, 5, new Color(4, null));

        $src = Canvas::createBlank(10, 10);

        Compositor::blend($dst, $src, 0.5);

        // dst pixel should be unchanged since src has nothing there
        $this->assertSame(4, $dst->data[5][5]->fg);
    }

    public function test_blend_copies_to_empty_destination(): void
    {
        $dst = Canvas::createBlank(10, 10);
        $src = Canvas::createBlank(10, 10);

        $src->drawPoint(5, 5, new Color(4, null));

        Compositor::blend($dst, $src, 0.5);

        // dst was empty, so src color copies directly regardless of opacity
        $this->assertSame(4, $dst->data[5][5]->fg);
    }

    public function test_blend_half_opacity_blends_colors(): void
    {
        $dst = Canvas::createBlank(10, 10);
        $src = Canvas::createBlank(10, 10);

        // dst: white (index 0 = #FFFFFF), src: black (index 1 = #000000)
        $dst->drawPoint(5, 5, new Color(0, null));
        $src->drawPoint(5, 5, new Color(1, null));

        Compositor::blend($dst, $src, 0.5);

        // Result should be ~#7F7F7F which is grey (index 14 = #7F7F7F)
        $result = $dst->data[5][5]->fg;
        $rgb = \draw\IrcPalette::getRgb($result);
        // Allow some tolerance since quantization may not land exactly
        $this->assertGreaterThan(100, $rgb[0], "R should be mid-range");
        $this->assertLessThan(170, $rgb[0], "R should be mid-range");
    }

    public function test_blend_zero_opacity_leaves_destination_unchanged(): void
    {
        $dst = Canvas::createBlank(10, 10);
        $src = Canvas::createBlank(10, 10);

        $dst->drawPoint(5, 5, new Color(4, null));
        $src->drawPoint(5, 5, new Color(1, null));

        Compositor::blend($dst, $src, 0.0);

        $this->assertSame(4, $dst->data[5][5]->fg);
    }

    public function test_blend_full_opacity_overwrites_destination(): void
    {
        $dst = Canvas::createBlank(10, 10);
        $src = Canvas::createBlank(10, 10);

        $dst->drawPoint(5, 5, new Color(4, null));
        $src->drawPoint(5, 5, new Color(0, null));

        Compositor::blend($dst, $src, 1.0);

        $this->assertSame(0, $dst->data[5][5]->fg);
    }

    public function test_blend_with_rendered_path(): void
    {
        $main = Canvas::createBlank(20, 10);
        $temp = Canvas::createBlank(20, 10);

        // Fill the main canvas with a color
        $fill = new Color(0, null);
        $main->drawPath(
            Path::rect(0, 0, 20, 10),
            $fill,
            null
        );

        // Draw a stroke on temp
        $stroke = new StrokeStyle(new Color(4, null), width: 3.0);
        $temp->drawPath(Path::line(2, 5, 17, 5), null, $stroke);

        // Composite at 50% opacity
        Compositor::blend($main, $temp, 0.5);

        // The stroke area should now be a blend of white and red
        // Not pure red (4) and not pure white (0)
        $strokePixel = $main->data[5][5];
        $this->assertNotNull($strokePixel->fg);
        $this->assertNotSame(4, $strokePixel->fg, "Should not be pure red after 50% blend");
        $this->assertNotSame(0, $strokePixel->fg, "Should not be pure white after 50% blend");
    }

    public function test_blend_resets_dst_alpha_to_one(): void
    {
        $dst = Canvas::createBlank(10, 10);
        $src = Canvas::createBlank(10, 10);

        $src->drawPoint(5, 5, new Color(4, null));

        Compositor::blend($dst, $src, 0.5);

        $this->assertSame(1.0, $dst->data[5][5]->fgAlpha);
    }

    public function test_blend_handles_bg_independently(): void
    {
        $dst = Canvas::createBlank(10, 10);
        $src = Canvas::createBlank(10, 10);

        $dst->drawPoint(5, 5, new Color(null, 0));
        $src->drawPoint(5, 5, new Color(4, 1));

        Compositor::blend($dst, $src, 1.0);

        // fg: dst was null, so src fg copies directly
        $this->assertSame(4, $dst->data[5][5]->fg);
        // bg: dst was white(0), src was black(1), full opacity → black
        $this->assertSame(1, $dst->data[5][5]->bg);
    }

    public function test_blend_copies_text_from_source(): void
    {
        $dst = Canvas::createBlank(10, 10);
        $src = Canvas::createBlank(10, 10);

        $p = new \draw\Pixel();
        $p->fg = 4;
        $p->text = '█';
        $src->data[5][5] = $p;

        Compositor::blend($dst, $src, 1.0);

        $this->assertSame('█', $dst->data[5][5]->text);
    }

    public function test_blend_does_not_overwrite_text_with_space(): void
    {
        $dst = Canvas::createBlank(10, 10);
        $src = Canvas::createBlank(10, 10);

        $dp = new \draw\Pixel();
        $dp->fg = 0;
        $dp->text = '█';
        $dst->data[5][5] = $dp;

        $sp = new \draw\Pixel();
        $sp->fg = 4;
        $sp->text = ' ';
        $src->data[5][5] = $sp;

        Compositor::blend($dst, $src, 1.0);

        // src text is ' ' (default), so dst text should be preserved
        $this->assertSame('█', $dst->data[5][5]->text);
    }

    public function test_blend_multiple_pixels(): void
    {
        $dst = Canvas::createBlank(10, 10);
        $src = Canvas::createBlank(10, 10);

        // Draw a filled rect on src
        $src->drawPath(
            Path::rect(2, 2, 5, 5),
            new Color(4, null),
            null
        );

        // Draw a filled rect on dst
        $dst->drawPath(
            Path::rect(0, 0, 10, 10),
            new Color(0, null),
            null
        );

        Compositor::blend($dst, $src, 0.5);

        // Inside the overlap area: blended color (not pure red, not pure white)
        $innerPixel = $dst->data[4][4];
        $this->assertNotSame(4, $innerPixel->fg);
        $this->assertNotSame(0, $innerPixel->fg);

        // Outside the src rect: dst unchanged
        $outerPixel = $dst->data[0][0];
        $this->assertSame(0, $outerPixel->fg);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Canvas/CompositorTest.php`
Expected: FAIL — `Class "draw\Compositor" not found`

- [ ] **Step 3: Implement Compositor**

Create `library/draw/Compositor.php`:

```php
<?php

namespace draw;

class Compositor
{
    public static function blend(Canvas $dst, Canvas $src, float $opacity = 1.0): void
    {
        if ($src->w !== $dst->w || $src->h !== $dst->h) {
            throw new \InvalidArgumentException(
                "Canvas size mismatch: {$dst->w}x{$dst->h} vs {$src->w}x{$src->h}"
            );
        }

        $opacity = max(0.0, min(1.0, $opacity));

        for ($y = 0; $y < $dst->h; $y++) {
            for ($x = 0; $x < $dst->w; $x++) {
                $dp = $dst->data[$y][$x];
                $sp = $src->data[$y][$x];

                $hasChange = false;

                if ($sp->fg !== null) {
                    $effectiveAlpha = $sp->fgAlpha * $opacity;
                    if ($effectiveAlpha < 0.001) {
                        // skip
                    } elseif ($dp->fg === null) {
                        $dp->fg = $sp->fg;
                        $dp->fgAlpha = 1.0;
                        $hasChange = true;
                    } else {
                        $srcRgb = IrcPalette::getRgb($sp->fg);
                        $dstRgb = IrcPalette::getRgb($dp->fg);
                        $r = (int) round($srcRgb[0] * $effectiveAlpha + $dstRgb[0] * (1.0 - $effectiveAlpha));
                        $g = (int) round($srcRgb[1] * $effectiveAlpha + $dstRgb[1] * (1.0 - $effectiveAlpha));
                        $b = (int) round($srcRgb[2] * $effectiveAlpha + $dstRgb[2] * (1.0 - $effectiveAlpha));
                        $r = max(0, min(255, $r));
                        $g = max(0, min(255, $g));
                        $b = max(0, min(255, $b));
                        $dp->fg = IrcPalette::nearestColor($r, $g, $b);
                        $dp->fgAlpha = 1.0;
                        $hasChange = true;
                    }
                }

                if ($sp->bg !== null) {
                    $effectiveAlpha = $sp->bgAlpha * $opacity;
                    if ($effectiveAlpha < 0.001) {
                        // skip
                    } elseif ($dp->bg === null) {
                        $dp->bg = $sp->bg;
                        $dp->bgAlpha = 1.0;
                        $hasChange = true;
                    } else {
                        $srcRgb = IrcPalette::getRgb($sp->bg);
                        $dstRgb = IrcPalette::getRgb($dp->bg);
                        $r = (int) round($srcRgb[0] * $effectiveAlpha + $dstRgb[0] * (1.0 - $effectiveAlpha));
                        $g = (int) round($srcRgb[1] * $effectiveAlpha + $dstRgb[1] * (1.0 - $effectiveAlpha));
                        $b = (int) round($srcRgb[2] * $effectiveAlpha + $dstRgb[2] * (1.0 - $effectiveAlpha));
                        $r = max(0, min(255, $r));
                        $g = max(0, min(255, $g));
                        $b = max(0, min(255, $b));
                        $dp->bg = IrcPalette::nearestColor($r, $g, $b);
                        $dp->bgAlpha = 1.0;
                        $hasChange = true;
                    }
                }

                if ($hasChange && $sp->text !== ' ') {
                    $dp->text = $sp->text;
                }
            }
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- tests/Canvas/CompositorTest.php`
Expected: PASS

- [ ] **Step 5: Run full test suite to confirm no regressions**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 6: Run static analysis**

Run: `composer phpstan`
Expected: No errors

- [ ] **Step 7: Commit**

```bash
git add library/draw/Compositor.php tests/Canvas/CompositorTest.php
git commit -m "Add Compositor: source-over alpha blending for IRC canvas"
```

---

### Task 4: Update roadmap — compositor milestone complete

**Files:**
- Modify: `docs/superpowers/specs/2026-06-02-draw-library-svg-roadmap.md`

- [ ] **Step 1: Add compositor as completed in milestone list**

In the roadmap milestone list, update the stroke-opacity note on milestone 5 and add a note that the compositor infrastructure is done. Find this line:

```
5. ~~**StrokeStyle** — width, dash, caps, joins (strokes > 1px)~~ **DONE** (stroke-opacity deferred; no compositing infrastructure yet)
```

Replace with:

```
5. ~~**StrokeStyle** — width, dash, caps, joins (strokes > 1px)~~ **DONE** (stroke-opacity deferred; pending StrokeStyle.opacity addition)
```

And find the milestone list end, after milestone 12 add:

```
13. **Compositor / opacity** — alpha compositing infrastructure (Compositor, IrcPalette, Pixel alpha) — **DONE**
```

Actually, insert it as a new completed milestone. Find this line:

```
5. ~~**StrokeStyle** — width, dash, caps, joins (strokes > 1px)~~ **DONE** (stroke-opacity deferred; no compositing infrastructure yet)
6. **Gradient Paint** — linear, radial, color stops, stop interpolation
```

Replace with:

```
5. ~~**StrokeStyle** — width, dash, caps, joins (strokes > 1px)~~ **DONE** (stroke-opacity deferred; pending StrokeStyle.opacity addition)
5.5. ~~**Compositor / opacity** — Pixel alpha, IrcPalette, Compositor (source-over blending in RGB)~~ **DONE**
6. **Gradient Paint** — linear, radial, color stops, stop interpolation
```

Also in the Tier 2 Opacity section, find:

```
**Opacity:**
- `opacity`, `fill-opacity`, `stroke-opacity`
- Render to an offscreen buffer, then composite with alpha
```

Replace with:

```
**Opacity:**
- `opacity`, `fill-opacity`, `stroke-opacity`
- Render to an offscreen buffer, then composite with alpha (Compositor class built)
```

- [ ] **Step 2: Commit**

```bash
git add docs/superpowers/specs/2026-06-02-draw-library-svg-roadmap.md
git commit -m "Update roadmap: compositor infrastructure complete"
```
