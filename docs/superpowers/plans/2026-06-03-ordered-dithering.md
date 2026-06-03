# Ordered Dithering Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add ordered (4x4 Bayer matrix) dithering to gradient quantization to reduce color banding in IRC art.

**Architecture:** Dithering lives in `IrcPalette::nearestColor()` — adds position-dependent noise to RGB before palette matching. Canvas stores a default dithering mode; Paints can override per-shape. Scene tree inherits dithering through RenderContext.

**Tech Stack:** PHP 8.1+, PHPUnit 10, existing draw library

---

### Task 1: Create `Dithering` enum

**Files:**
- Create: `library/draw/Dithering.php`
- Test: `tests/Canvas/DitheringTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Canvas;

use draw\Dithering;
use PHPUnit\Framework\TestCase;

class DitheringTest extends TestCase
{
    public function test_enum_has_none_case(): void
    {
        $this->assertSame('None', Dithering::None->name);
    }

    public function test_enum_has_ordered4x4_case(): void
    {
        $this->assertSame('Ordered4x4', Dithering::Ordered4x4->name);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Canvas/DitheringTest.php`
Expected: FAIL — class `draw\Dithering` not found

- [ ] **Step 3: Write the enum**

```php
<?php

namespace draw;

enum Dithering
{
    case None;
    case Ordered4x4;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- tests/Canvas/DitheringTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add library/draw/Dithering.php tests/Canvas/DitheringTest.php
git commit -m "feat(draw): add Dithering enum with None and Ordered4x4 cases"
```

---

### Task 2: Add dithering to `IrcPalette::nearestColor`

**Files:**
- Modify: `library/draw/IrcPalette.php`
- Test: `tests/Canvas/IrcPaletteTest.php`

- [ ] **Step 1: Write the tests**

Add to `tests/Canvas/IrcPaletteTest.php`:

```php
use draw\Dithering;

public function test_nearestColor_defaults_to_no_dithering(): void
{
    $undithered = IrcPalette::nearestColor(128, 128, 128);
    $explicitNone = IrcPalette::nearestColor(128, 128, 128, Dithering::None, 5, 5);
    $this->assertSame($undithered, $explicitNone);
}

public function test_nearestColor_dithering_changes_result_for_some_pixels(): void
{
    $midR = 128;
    $midG = 64;
    $midB = 32;
    $none0 = IrcPalette::nearestColor($midR, $midG, $midB, Dithering::None, 0, 0);
    $dither0 = IrcPalette::nearestColor($midR, $midG, $midB, Dithering::Ordered4x4, 0, 0);
    $dither5 = IrcPalette::nearestColor($midR, $midG, $midB, Dithering::Ordered4x4, 5, 5);
    $anyDifferent = ($none0 !== $dither0) || ($none0 !== $dither5) || ($dither0 !== $dither5);
    $this->assertTrue($anyDifferent, 'Dithering should produce at least one different result');
}

public function test_nearestColor_dithering_clamps_to_valid_range(): void
{
    $result = IrcPalette::nearestColor(0, 0, 0, Dithering::Ordered4x4, 3, 3);
    $this->assertGreaterThanOrEqual(0, $result);
    $this->assertLessThanOrEqual(98, $result);

    $result = IrcPalette::nearestColor(255, 255, 255, Dithering::Ordered4x4, 0, 0);
    $this->assertGreaterThanOrEqual(0, $result);
    $this->assertLessThanOrEqual(98, $result);
}

public function test_nearestColor_dithering_is_position_dependent(): void
{
    $r = 100;
    $g = 100;
    $b = 100;
    $result1 = IrcPalette::nearestColor($r, $g, $b, Dithering::Ordered4x4, 0, 0);
    $result2 = IrcPalette::nearestColor($r, $g, $b, Dithering::Ordered4x4, 1, 0);
    $result3 = IrcPalette::nearestColor($r, $g, $b, Dithering::Ordered4x4, 2, 0);
    $result4 = IrcPalette::nearestColor($r, $g, $b, Dithering::Ordered4x4, 3, 0);
    $uniqueResults = array_unique([$result1, $result2, $result3, $result4]);
    $this->assertGreaterThan(1, count($uniqueResults), 'Different positions should produce varied results for a mid-range color');
}

public function test_nearestColor_dithering_wraps_at_matrix_size(): void
{
    $r = 100;
    $g = 100;
    $b = 100;
    $at4 = IrcPalette::nearestColor($r, $g, $b, Dithering::Ordered4x4, 4, 4);
    $at0 = IrcPalette::nearestColor($r, $g, $b, Dithering::Ordered4x4, 0, 0);
    $this->assertSame($at0, $at4, 'Dithering should wrap at 4x4 matrix size');
}

public function test_nearestColor_dithering_with_xy_zero_same_as_none_for_bayer_center(): void
{
    $r = 200;
    $g = 200;
    $b = 200;
    $at7 = IrcPalette::nearestColor($r, $g, $b, Dithering::Ordered4x4, 1, 1);
    $none = IrcPalette::nearestColor($r, $g, $b, Dithering::None, 1, 1);
    $this->assertSame($none, $at7, 'Bayer value 4 (index [1][1]) normalizes near 0, so should match no-dither for bright input');
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- tests/Canvas/IrcPaletteTest.php`
Expected: Some tests FAIL — `nearestColor` doesn't accept Dithering param yet

- [ ] **Step 3: Add dithering to `IrcPalette`**

In `library/draw/IrcPalette.php`, change the `nearestColor` method signature and add the Bayer matrix + dithering logic:

```php
private const BAYER_4X4 = [
    [ 0,  8,  2, 10],
    [12,  4, 14,  6],
    [ 3, 11,  1,  9],
    [15,  7, 13,  5],
];

private const DITHER_STRENGTH = 16.0;

public static function nearestColor(int $r, int $g, int $b, Dithering $mode = Dithering::None, int $x = 0, int $y = 0): int
{
    if ($mode === Dithering::Ordered4x4) {
        $bayer = self::BAYER_4X4[$y & 3][$x & 3];
        $offset = ($bayer - 7.5) / 8.0 * self::DITHER_STRENGTH;
        $r = (int) max(0, min(255, round($r + $offset)));
        $g = (int) max(0, min(255, round($g + $offset)));
        $b = (int) max(0, min(255, round($b + $offset)));
    }

    $key = ($r << 16) | ($g << 8) | $b;
    if (isset(self::$nearestCache[$key])) {
        return self::$nearestCache[$key];
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
    return $bestIdx;
}
```

Keep the existing `getRgb`, `getColor`, `validateCode`, `buildRgbPalette`, `buildColorPalette`, `getHexPalette` methods unchanged.

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test -- tests/Canvas/IrcPaletteTest.php`
Expected: ALL PASS

- [ ] **Step 5: Run full test suite**

Run: `composer test`
Expected: ALL PASS — existing `nearestColor` calls don't pass dithering params, so default `None` preserves behavior

- [ ] **Step 6: Commit**

```bash
git add library/draw/IrcPalette.php tests/Canvas/IrcPaletteTest.php
git commit -m "feat(draw): add ordered 4x4 dithering to IrcPalette::nearestColor"
```

---

### Task 3: Add `getDithering()` to `Paint` interface and `Color`

**Files:**
- Modify: `library/draw/Paint.php`
- Modify: `library/draw/Color.php`
- Test: `tests/Canvas/PaintTest.php`

- [ ] **Step 1: Write the tests**

Add to `tests/Canvas/PaintTest.php`:

```php
use draw\Color;
use draw\Dithering;

public function test_color_getDithering_returns_null(): void
{
    $color = new Color(0, null);
    $this->assertNull($color->getDithering());
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Canvas/PaintTest.php`
Expected: FAIL — method `getDithering` not found

- [ ] **Step 3: Add `getDithering` to Paint interface**

In `library/draw/Paint.php`, add:

```php
public function getDithering(): ?Dithering;
```

Add the import at the top if needed (it's in the same namespace, so no import needed).

- [ ] **Step 4: Implement in Color**

In `library/draw/Color.php`, add:

```php
public function getDithering(): ?Dithering
{
    return null;
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `composer test -- tests/Canvas/PaintTest.php`
Expected: PASS

- [ ] **Step 6: Run full test suite**

Run: `composer test`
Expected: FAIL — LinearGradient and RadialGradient don't implement `getDithering` yet. That's expected; next task fixes it.

- [ ] **Step 7: Commit (partial — Paint interface + Color only)**

```bash
git add library/draw/Paint.php library/draw/Color.php tests/Canvas/PaintTest.php
git commit -m "feat(draw): add getDithering() to Paint interface, Color returns null"
```

---

### Task 4: Add dithering to `LinearGradient` and `RadialGradient`

**Files:**
- Modify: `library/draw/LinearGradient.php`
- Modify: `library/draw/RadialGradient.php`
- Test: `tests/Canvas/GradientTest.php`

- [ ] **Step 1: Write the tests**

Add to `tests/Canvas/GradientTest.php`:

```php
use draw\Dithering;

public function test_linear_gradient_getDithering_defaults_to_null(): void
{
    $stops = [
        new ColorStop(0.0, 255, 0, 0),
        new ColorStop(1.0, 0, 0, 255),
    ];
    $grad = new LinearGradient(0, 0, 80, 0, $stops);
    $this->assertNull($grad->getDithering());
}

public function test_linear_gradient_getDithering_returns_override(): void
{
    $stops = [
        new ColorStop(0.0, 255, 0, 0),
        new ColorStop(1.0, 0, 0, 255),
    ];
    $grad = new LinearGradient(0, 0, 80, 0, $stops, dithering: Dithering::Ordered4x4);
    $this->assertSame(Dithering::Ordered4x4, $grad->getDithering());
}

public function test_linear_gradient_getDithering_returns_none_when_explicit(): void
{
    $stops = [
        new ColorStop(0.0, 255, 0, 0),
        new ColorStop(1.0, 0, 0, 255),
    ];
    $grad = new LinearGradient(0, 0, 80, 0, $stops, dithering: Dithering::None);
    $this->assertSame(Dithering::None, $grad->getDithering());
}

public function test_radial_gradient_getDithering_defaults_to_null(): void
{
    $stops = [
        new ColorStop(0.0, 255, 0, 0),
        new ColorStop(1.0, 0, 0, 255),
    ];
    $grad = new RadialGradient(40, 24, 20, $stops);
    $this->assertNull($grad->getDithering());
}

public function test_radial_gradient_getDithering_returns_override(): void
{
    $stops = [
        new ColorStop(0.0, 255, 0, 0),
        new ColorStop(1.0, 0, 0, 255),
    ];
    $grad = new RadialGradient(40, 24, 20, $stops, dithering: Dithering::Ordered4x4);
    $this->assertSame(Dithering::Ordered4x4, $grad->getDithering());
}

public function test_radial_gradient_getDithering_with_focal_and_spread(): void
{
    $stops = [
        new ColorStop(0.0, 255, 0, 0),
        new ColorStop(1.0, 0, 0, 255),
    ];
    $grad = new RadialGradient(40, 24, 20, $stops, fx: 38, fy: 22, spreadMethod: SpreadMethod::Reflect, dithering: Dithering::Ordered4x4);
    $this->assertSame(Dithering::Ordered4x4, $grad->getDithering());
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- tests/Canvas/GradientTest.php`
Expected: FAIL — `getDithering` not defined on gradient classes

- [ ] **Step 3: Add `dithering` param and `getDithering()` to LinearGradient**

In `library/draw/LinearGradient.php`, add the constructor param and method:

```php
public function __construct(
    public readonly float $x1,
    public readonly float $y1,
    public readonly float $x2,
    public readonly float $y2,
    array $stops,
    public readonly SpreadMethod $spreadMethod = SpreadMethod::Pad,
    public readonly ?Dithering $dithering = null,
) {
```

```php
public function getDithering(): ?Dithering
{
    return $this->dithering;
}
```

- [ ] **Step 4: Add `dithering` param and `getDithering()` to RadialGradient**

In `library/draw/RadialGradient.php`, add the constructor param and method:

```php
public function __construct(
    public readonly float $cx,
    public readonly float $cy,
    public readonly float $r,
    array $stops,
    ?float $fx = null,
    ?float $fy = null,
    public readonly SpreadMethod $spreadMethod = SpreadMethod::Pad,
    public readonly ?Dithering $dithering = null,
) {
```

```php
public function getDithering(): ?Dithering
{
    return $this->dithering;
}
```

- [ ] **Step 5: Run full test suite**

Run: `composer test`
Expected: ALL PASS

- [ ] **Step 6: Commit**

```bash
git add library/draw/LinearGradient.php library/draw/RadialGradient.php tests/Canvas/GradientTest.php
git commit -m "feat(draw): add dithering override to LinearGradient and RadialGradient"
```

---

### Task 5: Add dithering mode to Canvas

**Files:**
- Modify: `library/draw/Canvas.php`
- Test: `tests/Canvas/CanvasTest.php`

- [ ] **Step 1: Write the tests**

Add to `tests/Canvas/CanvasTest.php`:

```php
use draw\Dithering;
use draw\LinearGradient;
use draw\ColorStop;
use draw\StrokeStyle;

public function test_canvas_setDithering_getDithering_roundtrip(): void
{
    $canvas = Canvas::createBlank(10, 10);
    $this->assertSame(Dithering::None, $canvas->getDithering());
    $canvas->setDithering(Dithering::Ordered4x4);
    $this->assertSame(Dithering::Ordered4x4, $canvas->getDithering());
}

public function test_canvas_dithering_affects_gradient_fill(): void
{
    $stops = [
        new ColorStop(0.0, 200, 100, 50),
        new ColorStop(1.0, 50, 100, 200),
    ];
    $gradient = new LinearGradient(0, 0, 9, 0, $stops);

    $none = Canvas::createBlank(10, 1);
    $none->drawPath(
        Path::rect(0, 0, 10, 1),
        $gradient,
        null,
    );

    $dithered = Canvas::createBlank(10, 1);
    $dithered->setDithering(Dithering::Ordered4x4);
    $dithered->drawPath(
        Path::rect(0, 0, 10, 1),
        $gradient,
        null,
    );

    $different = false;
    for ($x = 0; $x < 10; $x++) {
        if ($none->data[0][$x]->fg !== $dithered->data[0][$x]->fg) {
            $different = true;
            break;
        }
    }
    $this->assertTrue($different, 'Dithered gradient should produce different colors than undithered');
}

public function test_canvas_paint_dithering_overrides_canvas_default(): void
{
    $stops = [
        new ColorStop(0.0, 200, 100, 50),
        new ColorStop(1.0, 50, 100, 200),
    ];
    $gradientNone = new LinearGradient(0, 0, 9, 0, $stops, dithering: Dithering::None);
    $gradientDithered = new LinearGradient(0, 0, 9, 0, $stops, dithering: Dithering::Ordered4x4);

    $canvasDitherDefault = Canvas::createBlank(10, 1);
    $canvasDitherDefault->setDithering(Dithering::Ordered4x4);
    $canvasDitherDefault->drawPath(
        Path::rect(0, 0, 10, 1),
        $gradientNone,
        null,
    );

    $canvasNoneDefault = Canvas::createBlank(10, 1);
    $canvasNoneDefault->drawPath(
        Path::rect(0, 0, 10, 1),
        $gradientDithered,
        null,
    );

    $different = false;
    for ($x = 0; $x < 10; $x++) {
        if ($canvasDitherDefault->data[0][$x]->fg !== $canvasNoneDefault->data[0][$x]->fg) {
            $different = true;
            break;
        }
    }
    $this->assertTrue($different, 'Paint dithering override should differ from canvas default');
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- tests/Canvas/CanvasTest.php`
Expected: FAIL — `setDithering`/`getDithering` not found on Canvas

- [ ] **Step 3: Add dithering property and methods to Canvas**

In `library/draw/Canvas.php`, add the property and methods:

```php
private Dithering $dithering = Dithering::None;
```

Add after `setDithering` import at top (no import needed, same namespace):

```php
public function setDithering(Dithering $mode): void
{
    $this->dithering = $mode;
}

public function getDithering(): Dithering
{
    return $this->dithering;
}
```

- [ ] **Step 4: Update the 3 quantization call sites**

At each site where `IrcPalette::nearestColor($r, $g, $b)` is called, determine the effective dithering mode and pass it through.

For `drawPoint` (~line 231), `drawLineInternal` (~line 320), and `fillPolygonScanlineMulti` fill span (~line 588), the paint is available. Resolve the effective mode:

```php
$effectiveDithering = $paint->getDithering() ?? $this->dithering;
```

Then change each `IrcPalette::nearestColor($r, $g, $b)` call to:

```php
IrcPalette::nearestColor($r, $g, $b, $effectiveDithering, $x, $y)
```

Using the appropriate `$x` and `$y` variables at each site:
- `drawPoint`: `$x`, `$y`
- `drawLineInternal`: `$x`, `$y`
- `fillPolygonScanlineMulti` fill span: `$xx`, `$Y`

- [ ] **Step 5: Handle temp canvases**

When `drawPath` creates temp canvases (lines 393, 419, 437 for opacity compositing), the temp canvas must inherit the dithering mode. After each `Canvas::createBlank(...)` call, add:

```php
$temp->setDithering($this->dithering);
```

This applies to 3 locations:
1. `drawPath` method — the `$opacity < 1.0` branch (~line 393)
2. `renderFill` — the `$fillOpacity < 1.0` branch (~line 419)
3. `renderStroke` — the `$strokeOpacity < 1.0` branch (~line 437)

For `renderFill` and `renderStroke`, these are private methods that receive the Canvas as `$target`. They create temp canvases and need the dithering from the *original* canvas. Since `$this` is the original canvas in all cases, use `$this->dithering`:

```php
$temp->setDithering($this->dithering);
```

- [ ] **Step 6: Run full test suite**

Run: `composer test`
Expected: ALL PASS

- [ ] **Step 7: Commit**

```bash
git add library/draw/Canvas.php tests/Canvas/CanvasTest.php
git commit -m "feat(draw): add dithering mode to Canvas, pass through to quantization"
```

---

### Task 6: Add dithering to Scene Tree (RenderContext, Shape, Group)

**Files:**
- Modify: `library/draw/RenderContext.php`
- Modify: `library/draw/Shape.php`
- Modify: `library/draw/Group.php`
- Test: `tests/Canvas/SceneTreeTest.php`

- [ ] **Step 1: Write the tests**

Add to `tests/Canvas/SceneTreeTest.php`:

```php
use draw\Dithering;

public function test_render_context_merge_dithering_child_overrides_parent(): void
{
    $parent = RenderContext::defaults();
    $child = $parent->merge(dithering: Dithering::Ordered4x4);
    $this->assertSame(Dithering::Ordered4x4, $child->dithering);
}

public function test_render_context_merge_dithering_null_inherits_parent(): void
{
    $parent = new RenderContext(
        fill: new Color(0, null),
        stroke: null,
        transform: Transform::identity(),
        opacity: 1.0,
        fillOpacity: 1.0,
        fillRule: FillRule::NonZero,
        dithering: Dithering::Ordered4x4,
    );
    $child = $parent->merge(dithering: null);
    $this->assertSame(Dithering::Ordered4x4, $child->dithering);
}

public function test_render_context_defaults_has_no_dithering(): void
{
    $ctx = RenderContext::defaults();
    $this->assertNull($ctx->dithering);
}

public function test_render_context_merge_dithering_none_overrides_parent(): void
{
    $parent = new RenderContext(
        fill: new Color(0, null),
        stroke: null,
        transform: Transform::identity(),
        opacity: 1.0,
        fillOpacity: 1.0,
        fillRule: FillRule::NonZero,
        dithering: Dithering::Ordered4x4,
    );
    $child = $parent->merge(dithering: Dithering::None);
    $this->assertSame(Dithering::None, $child->dithering);
}

public function test_shape_with_dithering(): void
{
    $stops = [
        new ColorStop(0.0, 200, 100, 50),
        new ColorStop(1.0, 50, 100, 200),
    ];
    $gradient = new LinearGradient(0, 0, 19, 0, $stops);
    $path = Path::rect(0, 0, 20, 4);
    $shape = new Shape($path, fill: $gradient, dithering: Dithering::Ordered4x4);

    $canvas = Canvas::createBlank(20, 4);
    $canvas->setDithering(Dithering::None);
    $shape->render($canvas, RenderContext::defaults());

    $canvasDithered = Canvas::createBlank(20, 4);
    $canvasDithered->setDithering(Dithering::Ordered4x4);
    $shape->render($canvasDithered, RenderContext::defaults());

    $different = false;
    for ($y = 0; $y < 4; $y++) {
        for ($x = 0; $x < 20; $x++) {
            if ($canvas->data[$y][$x]->fg !== $canvasDithered->data[$y][$x]->fg) {
                $different = true;
                break 2;
            }
        }
    }
    $this->assertTrue($different, 'Shape with dithering override should produce different output');
}

public function test_group_inherits_dithering_to_children(): void
{
    $stops = [
        new ColorStop(0.0, 200, 100, 50),
        new ColorStop(1.0, 50, 100, 200),
    ];
    $gradient = new LinearGradient(0, 0, 9, 0, $stops);
    $path = Path::rect(0, 0, 10, 2);
    $child = new Shape($path, fill: $gradient);
    $group = new Group(dithering: Dithering::Ordered4x4);
    $group->addChild($child);

    $canvas = Canvas::createBlank(10, 2);
    $group->render($canvas, RenderContext::defaults());

    $canvasNoDither = Canvas::createBlank(10, 2);
    $child->render($canvasNoDither, RenderContext::defaults());

    $different = false;
    for ($y = 0; $y < 2; $y++) {
        for ($x = 0; $x < 10; $x++) {
            if ($canvas->data[$y][$x]->fg !== $canvasNoDither->data[$y][$x]->fg) {
                $different = true;
                break 2;
            }
        }
    }
    $this->assertTrue($different, 'Group dithering should propagate to child shapes');
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- tests/Canvas/SceneTreeTest.php`
Expected: FAIL — RenderContext doesn't have `dithering` property

- [ ] **Step 3: Add `dithering` to RenderContext**

In `library/draw/RenderContext.php`:

```php
public function __construct(
    public readonly Paint $fill,
    public readonly ?StrokeStyle $stroke,
    public readonly Transform $transform,
    public readonly float $opacity,
    public readonly float $fillOpacity,
    public readonly FillRule $fillRule,
    public readonly ?Dithering $dithering = null,
) {
}
```

Update `defaults()`:

```php
return new self(
    fill: new Color(0, null),
    stroke: null,
    transform: Transform::identity(),
    opacity: 1.0,
    fillOpacity: 1.0,
    fillRule: FillRule::NonZero,
    dithering: null,
);
```

Update `merge()` to accept and merge dithering (child non-null overrides parent):

```php
public function merge(
    ?Paint $fill = null,
    ?StrokeStyle $stroke = null,
    ?Transform $transform = null,
    ?float $opacity = null,
    ?float $fillOpacity = null,
    ?FillRule $fillRule = null,
    ?Dithering $dithering = null,
): self {
    return new self(
        fill: $fill ?? $this->fill,
        stroke: $stroke ?? $this->stroke,
        transform: $transform !== null
            ? $this->transform->multiply($transform)
            : $this->transform,
        opacity: $opacity !== null
            ? $this->opacity * $opacity
            : $this->opacity,
        fillOpacity: $fillOpacity !== null
            ? $this->fillOpacity * $fillOpacity
            : $this->fillOpacity,
        fillRule: $fillRule ?? $this->fillRule,
        dithering: $dithering ?? $this->dithering,
    );
}
```

- [ ] **Step 4: Add `dithering` to Shape**

In `library/draw/Shape.php`, add the constructor param:

```php
public function __construct(
    public readonly Path $path,
    public readonly ?Paint $fill = null,
    public readonly ?StrokeStyle $stroke = null,
    public readonly ?Transform $transform = null,
    public readonly ?float $opacity = null,
    public readonly ?float $fillOpacity = null,
    public readonly ?FillRule $fillRule = null,
    public readonly ?Dithering $dithering = null,
) {
}
```

In `render()`, pass dithering to `merge()`:

```php
$effective = $ctx->merge(
    fill: $this->fill,
    stroke: $this->stroke,
    opacity: $this->opacity,
    fillOpacity: $this->fillOpacity,
    fillRule: $this->fillRule,
    dithering: $this->dithering,
);
```

Before calling `$canvas->drawPath(...)`, apply the resolved dithering to the canvas:

```php
$prevDithering = $canvas->getDithering();
$resolvedDithering = $effective->dithering ?? $prevDithering;
$canvas->setDithering($resolvedDithering);

$canvas->drawPath(
    $this->path,
    $effective->fill,
    $effective->stroke,
    '',
    $effective->fillRule,
    $effective->fillOpacity,
    $effective->opacity,
);

$canvas->setDithering($prevDithering);
```

- [ ] **Step 5: Add `dithering` to Group**

In `library/draw/Group.php`, add the constructor param:

```php
public function __construct(
    public readonly ?Paint $fill = null,
    public readonly ?StrokeStyle $stroke = null,
    public readonly ?Transform $transform = null,
    public readonly ?float $opacity = null,
    public readonly ?float $fillOpacity = null,
    public readonly ?FillRule $fillRule = null,
    public readonly ?Dithering $dithering = null,
) {
}
```

In `render()`, pass dithering to `merge()`:

```php
$childCtx = $ctx->merge(
    fill: $this->fill,
    stroke: $this->stroke,
    opacity: $this->opacity,
    fillOpacity: $this->fillOpacity,
    fillRule: $this->fillRule,
    dithering: $this->dithering,
);
```

- [ ] **Step 6: Run full test suite**

Run: `composer test`
Expected: ALL PASS

- [ ] **Step 7: Commit**

```bash
git add library/draw/RenderContext.php library/draw/Shape.php library/draw/Group.php tests/Canvas/SceneTreeTest.php
git commit -m "feat(draw): add dithering to RenderContext, Shape, and Group"
```

---

### Task 7: Add dithered gradient demo command

**Files:**
- Modify: `artbot_scripts/drawing.php`

- [ ] **Step 1: Add `dithered` demo command**

In the `@demo` command handler in `artbot_scripts/drawing.php`, add a new `dithered` demo alongside the existing ones (gradient, opacity, linework, topo). This demo renders the same gradient side-by-side with and without dithering so the user can see the difference.

Find the demo command switch/array and add:

```php
'dithered' => function (Canvas $canvas) {
    $stops = [
        new ColorStop(0.0, 200, 50, 50),
        new ColorStop(0.3, 50, 200, 50),
        new ColorStop(0.6, 50, 50, 200),
        new ColorStop(1.0, 200, 200, 50),
    ];

    $topHalf = Canvas::createBlank(80, 24);
    $bottomHalf = Canvas::createBlank(80, 24);

    $topHalf->drawPath(
        Path::rect(0, 0, 80, 24),
        new LinearGradient(0, 0, 80, 0, $stops),
        null,
    );

    $bottomHalf->setDithering(Dithering::Ordered4x4);
    $bottomHalf->drawPath(
        Path::rect(0, 0, 80, 24),
        new LinearGradient(0, 0, 80, 0, $stops),
        null,
    );

    for ($y = 0; $y < 24; $y++) {
        for ($x = 0; $x < 80; $x++) {
            $canvas->data[$y][$x] = $topHalf->data[$y][$x];
            $canvas->data[$y + 24][$x] = $bottomHalf->data[$y][$x];
        }
    }
},
```

- [ ] **Step 2: Verify the demo file loads without syntax errors**

Run: `php -l artbot_scripts/drawing.php`
Expected: No syntax errors

- [ ] **Step 3: Run full test suite**

Run: `composer test`
Expected: ALL PASS

- [ ] **Step 4: Commit**

```bash
git add artbot_scripts/drawing.php
git commit -m "feat(demo): add dithered gradient demo command"
```
