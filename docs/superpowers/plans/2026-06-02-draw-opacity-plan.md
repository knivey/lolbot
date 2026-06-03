# Draw Opacity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add stroke-opacity, fill-opacity, and element-level opacity to drawPath using the Compositor's render-to-temp-canvas pattern.

**Architecture:** StrokeStyle gains an `opacity` property. `drawPath` gains `$fillOpacity` and `$opacity` params. When any opacity < 1.0, drawPath renders to a temp canvas and composites. When all opacities are 1.0, the existing fast path runs unchanged.

**Tech Stack:** PHP 8.1+, PHPUnit 10, existing draw library (Canvas, Compositor, IrcPalette)

**Spec:** `docs/superpowers/specs/2026-06-02-draw-opacity-design.md`

---

### Task 1: Add opacity property to StrokeStyle

**Files:**
- Modify: `library/draw/StrokeStyle.php`
- Test: `tests/Canvas/StrokeStyleTest.php` (create)

- [ ] **Step 1: Write failing tests for StrokeStyle opacity**

Create `tests/Canvas/StrokeStyleTest.php`:

```php
<?php

namespace Tests\Canvas;

use draw\Color;
use draw\LineCap;
use draw\LineJoin;
use draw\StrokeStyle;
use PHPUnit\Framework\TestCase;

class StrokeStyleTest extends TestCase
{
    public function test_default_opacity_is_one(): void
    {
        $s = new StrokeStyle(new Color(4, null));
        $this->assertSame(1.0, $s->opacity);
    }

    public function test_opacity_can_be_set(): void
    {
        $s = new StrokeStyle(new Color(4, null), opacity: 0.5);
        $this->assertSame(0.5, $s->opacity);
    }

    public function test_opacity_is_clamped_below_zero(): void
    {
        $s = new StrokeStyle(new Color(4, null), opacity: -0.5);
        $this->assertSame(0.0, $s->opacity);
    }

    public function test_opacity_is_clamped_above_one(): void
    {
        $s = new StrokeStyle(new Color(4, null), opacity: 1.5);
        $this->assertSame(1.0, $s->opacity);
    }

    public function test_zero_opacity_is_valid(): void
    {
        $s = new StrokeStyle(new Color(4, null), opacity: 0.0);
        $this->assertSame(0.0, $s->opacity);
    }

    public function test_existing_properties_unchanged(): void
    {
        $s = new StrokeStyle(
            new Color(4, null),
            width: 3.0,
            dashArray: [4.0, 2.0],
            dashOffset: 1.0,
            lineCap: LineCap::Round,
            lineJoin: LineJoin::Bevel,
            miterLimit: 2.0,
        );
        $this->assertSame(4, $s->color->fg);
        $this->assertSame(3.0, $s->width);
        $this->assertSame([4.0, 2.0], $s->dashArray);
        $this->assertSame(1.0, $s->dashOffset);
        $this->assertSame(LineCap::Round, $s->lineCap);
        $this->assertSame(LineJoin::Bevel, $s->lineJoin);
        $this->assertSame(2.0, $s->miterLimit);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Canvas/StrokeStyleTest.php`
Expected: FAIL — `Unknown named parameter $opacity`

- [ ] **Step 3: Add opacity to StrokeStyle**

The current `library/draw/StrokeStyle.php` is:

```php
<?php

namespace draw;

class StrokeStyle
{
    public function __construct(
        public readonly Color $color,
        public readonly float $width = 1.0,
        public readonly ?array $dashArray = null,
        public readonly float $dashOffset = 0.0,
        public readonly LineCap $lineCap = LineCap::Butt,
        public readonly LineJoin $lineJoin = LineJoin::Miter,
        public readonly float $miterLimit = 4.0
    ) {
        if ($dashArray !== null) {
            foreach ($dashArray as $v) {
                if ($v < 0) {
                    throw new \InvalidArgumentException('dashArray values must be >= 0');
                }
            }
        }
    }
}
```

Replace with:

```php
<?php

namespace draw;

class StrokeStyle
{
    public function __construct(
        public readonly Color $color,
        public readonly float $width = 1.0,
        public readonly ?array $dashArray = null,
        public readonly float $dashOffset = 0.0,
        public readonly LineCap $lineCap = LineCap::Butt,
        public readonly LineJoin $lineJoin = LineJoin::Miter,
        public readonly float $miterLimit = 4.0,
        public readonly float $opacity = 1.0,
    ) {
        if ($dashArray !== null) {
            foreach ($dashArray as $v) {
                if ($v < 0) {
                    throw new \InvalidArgumentException('dashArray values must be >= 0');
                }
            }
        }
    }
}
```

Note: clamping will be done in the drawPath method, not in StrokeStyle, to keep
StrokeStyle as a simple data holder consistent with how it treats other properties.

Update the test to remove the clamping tests since we decided not to clamp in
StrokeStyle. Replace `tests/Canvas/StrokeStyleTest.php` with:

```php
<?php

namespace Tests\Canvas;

use draw\Color;
use draw\LineCap;
use draw\LineJoin;
use draw\StrokeStyle;
use PHPUnit\Framework\TestCase;

class StrokeStyleTest extends TestCase
{
    public function test_default_opacity_is_one(): void
    {
        $s = new StrokeStyle(new Color(4, null));
        $this->assertSame(1.0, $s->opacity);
    }

    public function test_opacity_can_be_set(): void
    {
        $s = new StrokeStyle(new Color(4, null), opacity: 0.5);
        $this->assertSame(0.5, $s->opacity);
    }

    public function test_zero_opacity_is_valid(): void
    {
        $s = new StrokeStyle(new Color(4, null), opacity: 0.0);
        $this->assertSame(0.0, $s->opacity);
    }

    public function test_existing_properties_unchanged(): void
    {
        $s = new StrokeStyle(
            new Color(4, null),
            width: 3.0,
            dashArray: [4.0, 2.0],
            dashOffset: 1.0,
            lineCap: LineCap::Round,
            lineJoin: LineJoin::Bevel,
            miterLimit: 2.0,
        );
        $this->assertSame(4, $s->color->fg);
        $this->assertSame(3.0, $s->width);
        $this->assertSame([4.0, 2.0], $s->dashArray);
        $this->assertSame(1.0, $s->dashOffset);
        $this->assertSame(LineCap::Round, $s->lineCap);
        $this->assertSame(LineJoin::Bevel, $s->lineJoin);
        $this->assertSame(2.0, $s->miterLimit);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- tests/Canvas/StrokeStyleTest.php`
Expected: PASS

- [ ] **Step 5: Run full test suite**

Run: `composer test`
Expected: All tests pass (opacity defaults to 1.0, backward compatible)

- [ ] **Step 6: Commit**

```bash
git add library/draw/StrokeStyle.php tests/Canvas/StrokeStyleTest.php
git commit -m "Add opacity property to StrokeStyle"
```

---

### Task 2: Add fillOpacity and opacity params to drawPath

**Files:**
- Modify: `library/draw/Canvas.php:336-388`

- [ ] **Step 1: Write failing tests for drawPath opacity**

Add these tests to `tests/Canvas/CanvasTest.php` (append before the closing `}`):

```php
    public function test_draw_path_fill_opacity_renders_at_half(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $canvas->drawPath(
            Path::rect(0, 0, 20, 10),
            new Color(0, null),
            null,
            '',
            FillRule::NonZero,
            0.5
        );

        $pixel = $canvas->data[5][5];
        $this->assertNotNull($pixel->fg);
        $this->assertNotSame(0, $pixel->fg, "50% fill on blank canvas should copy color directly");
    }

    public function test_draw_path_fill_opacity_zero_is_noop(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $canvas->drawPath(
            Path::rect(0, 0, 20, 10),
            new Color(4, null),
            null,
            '',
            FillRule::NonZero,
            0.0
        );

        for ($y = 0; $y < 10; $y++) {
            for ($x = 0; $x < 20; $x++) {
                $this->assertNull($canvas->data[$y][$x]->fg, "Pixel ($x,$y) should be empty at 0 opacity");
            }
        }
    }

    public function test_draw_path_stroke_opacity_renders_at_half(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $stroke = new StrokeStyle(new Color(4, null), opacity: 0.5);
        $canvas->drawPath(Path::line(2, 5, 17, 5), null, $stroke);

        $pixel = $canvas->data[5][10];
        $this->assertNotNull($pixel->fg);
        $this->assertSame(4, $pixel->fg, "50% stroke on blank canvas copies directly");
    }

    public function test_draw_path_stroke_opacity_zero_is_noop(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $stroke = new StrokeStyle(new Color(4, null), opacity: 0.0);
        $canvas->drawPath(Path::line(2, 5, 17, 5), null, $stroke);

        for ($x = 2; $x <= 17; $x++) {
            $this->assertNull($canvas->data[5][$x]->fg, "Pixel $x should be empty at 0 stroke opacity");
        }
    }

    public function test_draw_path_element_opacity_blends(): void
    {
        $canvas = Canvas::createBlank(20, 10);

        $canvas->drawPath(
            Path::rect(0, 0, 20, 10),
            new Color(0, null),
            null
        );

        $canvas->drawPath(
            Path::rect(2, 2, 10, 5),
            new Color(4, null),
            null,
            '',
            FillRule::NonZero,
            1.0,
            0.5
        );

        $blended = $canvas->data[4][5];
        $this->assertNotSame(4, $blended->fg, "Should not be pure red after 50% element opacity");
        $this->assertNotSame(0, $blended->fg, "Should not be pure white after 50% element opacity");

        $outside = $canvas->data[0][0];
        $this->assertSame(0, $outside->fg, "Outside overlap should be unchanged");
    }

    public function test_draw_path_element_opacity_zero_is_noop(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $canvas->drawPath(
            Path::rect(2, 2, 10, 5),
            new Color(4, null),
            null,
            '',
            FillRule::NonZero,
            1.0,
            0.0
        );

        $this->assertNull($canvas->data[4][5]->fg, "Should be empty at 0 element opacity");
    }

    public function test_draw_path_fill_and_stroke_combined_opacities(): void
    {
        $canvas = Canvas::createBlank(20, 10);

        $canvas->drawPath(
            Path::rect(0, 0, 20, 10),
            new Color(0, null),
            null
        );

        $stroke = new StrokeStyle(new Color(4, null), width: 3.0, opacity: 0.5);
        $canvas->drawPath(
            Path::rect(2, 2, 10, 5),
            new Color(9, null),
            $stroke,
            '',
            FillRule::NonZero,
            0.7,
            1.0
        );

        $fillPixel = $canvas->data[4][5];
        $this->assertNotSame(9, $fillPixel->fg, "Fill should be blended, not raw");
        $this->assertNotSame(0, $fillPixel->fg, "Fill should not be pure white bg");

        $strokePixel = $canvas->data[2][5];
        $this->assertNotSame(4, $strokePixel->fg, "Stroke should be blended, not raw");
    }

    public function test_draw_path_opacity_full_is_same_as_default(): void
    {
        $c1 = Canvas::createBlank(20, 10);
        $c2 = Canvas::createBlank(20, 10);

        $fill = new Color(4, null);
        $stroke = new StrokeStyle(new Color(0, null));

        $c1->drawPath(Path::rect(2, 2, 10, 5), $fill, $stroke);
        $c2->drawPath(Path::rect(2, 2, 10, 5), $fill, $stroke, '', FillRule::NonZero, 1.0, 1.0);

        for ($y = 0; $y < 10; $y++) {
            for ($x = 0; $x < 20; $x++) {
                $this->assertSame(
                    $c1->data[$y][$x]->fg,
                    $c2->data[$y][$x]->fg,
                    "Pixel ($x,$y) should match with explicit 1.0 opacity"
                );
            }
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Canvas/CanvasTest.php`
Expected: FAIL — `Unknown named parameter $opacity` or too few arguments

- [ ] **Step 3: Update drawPath signature and add opacity logic**

Replace the `drawPath` method in `library/draw/Canvas.php` (lines 336-388) with:

```php
    /**
     * @param Path $path The path to render.
     * @param ?Color $fillColor Fill color, or null for no fill.
     * @param ?StrokeStyle $stroke Stroke style, or null for no outline.
     * @param string $text Optional text for rendered pixels.
     * @param FillRule $fillRule Fill rule for scanline conversion.
     * @param float $fillOpacity Opacity for the fill (0.0–1.0).
     * @param float $opacity Element-level opacity applied to everything (0.0–1.0).
     */
    public function drawPath(
        Path $path,
        ?Color $fillColor,
        ?StrokeStyle $stroke,
        string $text = '',
        FillRule $fillRule = FillRule::NonZero,
        float $fillOpacity = 1.0,
        float $opacity = 1.0,
    ): void {
        $subpaths = $path->flatten();
        if (count($subpaths) === 0) {
            return;
        }
        if ($fillColor === null && $stroke === null) {
            return;
        }

        $effective = $this->ctm;
        $pathTransform = $path->getTransform();
        if ($pathTransform !== null) {
            $effective = $effective->multiply($pathTransform);
        }

        $needTransform = !$this->isIdentity($effective);

        $snappedSubpaths = [];
        foreach ($subpaths as $sp) {
            $snapped = [];
            foreach ($sp['vertices'] as $v) {
                if ($needTransform) {
                    $v = $effective->apply($v[0], $v[1]);
                }
                $snapped[] = [(int) round($v[0]), (int) round($v[1])];
            }
            $snappedSubpaths[] = ['vertices' => $snapped, 'closed' => $sp['closed']];
        }

        $fillOpacity = max(0.0, min(1.0, $fillOpacity));
        $opacity = max(0.0, min(1.0, $opacity));
        $strokeOpacity = $stroke !== null ? max(0.0, min(1.0, $stroke->opacity)) : 1.0;

        if ($opacity < 1.0) {
            $temp = Canvas::createBlank($this->w, $this->h, $this->halfblocks);
            $this->renderFill($temp, $snappedSubpaths, $fillColor, $text, $fillRule);
            $this->renderStroke($temp, $snappedSubpaths, $stroke, $text);
            Compositor::blend($this, $temp, $opacity);
        } else {
            if ($fillColor !== null && $fillOpacity < 1.0) {
                $temp = Canvas::createBlank($this->w, $this->h, $this->halfblocks);
                $this->renderFill($temp, $snappedSubpaths, $fillColor, $text, $fillRule);
                Compositor::blend($this, $temp, $fillOpacity);
            } else {
                $this->renderFill($this, $snappedSubpaths, $fillColor, $text, $fillRule);
            }

            if ($stroke !== null && $strokeOpacity < 1.0) {
                $temp = Canvas::createBlank($this->w, $this->h, $this->halfblocks);
                $this->renderStroke($temp, $snappedSubpaths, $stroke, $text);
                Compositor::blend($this, $temp, $strokeOpacity);
            } else {
                $this->renderStroke($this, $snappedSubpaths, $stroke, $text);
            }
        }
    }

    /**
     * @param array<int, array{vertices: array<int, array{int, int}>, closed: bool}> $snappedSubpaths
     */
    private function renderFill(Canvas $target, array $snappedSubpaths, ?Color $fillColor, string $text, FillRule $fillRule): void
    {
        if ($fillColor === null) {
            return;
        }
        $polygonArrays = [];
        foreach ($snappedSubpaths as $sp) {
            if (count($sp['vertices']) >= 3) {
                $polygonArrays[] = $sp['vertices'];
            }
        }
        if (count($polygonArrays) > 0) {
            $target->fillPolygonScanlineMulti($polygonArrays, $fillColor, $text, $fillRule);
        }
    }

    /**
     * @param array<int, array{vertices: array<int, array{int, int}>, closed: bool}> $snappedSubpaths
     */
    private function renderStroke(Canvas $target, array $snappedSubpaths, ?StrokeStyle $stroke, string $text): void
    {
        if ($stroke === null) {
            return;
        }
        foreach ($snappedSubpaths as $sp) {
            $target->strokeSubpath($sp, $stroke, $text);
        }
    }
```

Note: `fillPolygonScanlineMulti` and `strokeSubpath` are currently private methods.
Since `renderFill` and `renderStroke` call them on a `$target` canvas that may be
`$this` or a temp canvas, these methods must be callable on any Canvas instance.
They are already instance methods that operate on `$this->data`, so they work
correctly when called on either canvas. Since they are private, the calls from
`renderFill`/`renderStroke` (also on Canvas) work because PHP private methods are
accessible within the same class.

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test`
Expected: All tests pass (existing + new opacity tests)

- [ ] **Step 5: Run static analysis on changed files**

Run: `composer phpstan 2>&1 | grep -i "Canvas"`
Expected: No new errors in Canvas.php (pre-existing errors in other files are expected)

- [ ] **Step 6: Commit**

```bash
git add library/draw/Canvas.php tests/Canvas/CanvasTest.php
git commit -m "Add fillOpacity and element opacity to drawPath with temp-canvas compositing"
```

---

### Task 3: Update roadmap

**Files:**
- Modify: `docs/superpowers/specs/2026-06-02-draw-library-svg-roadmap.md`

- [ ] **Step 1: Update milestone 5 and add opacity note**

Find:

```
5. ~~**StrokeStyle** — width, dash, caps, joins (strokes > 1px)~~ **DONE** (stroke-opacity deferred; pending StrokeStyle.opacity addition)
6. ~~**Compositor / opacity** — Pixel alpha, IrcPalette, Compositor (source-over blending in RGB)~~ **DONE**
```

Replace with:

```
5. ~~**StrokeStyle** — width, dash, caps, joins (strokes > 1px), stroke-opacity~~ **DONE**
6. ~~**Compositor / opacity** — Pixel alpha, IrcPalette, Compositor, fill-opacity, element opacity~~ **DONE**
```

- [ ] **Step 2: Commit**

```bash
git add docs/superpowers/specs/2026-06-02-draw-library-svg-roadmap.md
git commit -m "Update roadmap: stroke/fill/element opacity complete"
```
