# gradientTransform + gradientUnits Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `gradientTransform`, `gradientUnits`, and viewBox coordinate mapping support to the SVG gradient pipeline, fixing a pre-existing coordinate space mismatch.

**Architecture:** Three-layer change: (1) `Transform::inverse()` for affine matrix inversion, (2) Canvas tracks inverse CTM so `getColorAt` receives user-space coords instead of pixel coords, (3) gradient classes store a composed `sampleTransform` (mapping gradient-local → user space) and apply its inverse in `getColorAt`. SVGParser computes the sampleTransform from gradientTransform, gradientUnits, bbox, and group transforms.

**Tech Stack:** PHP 8.1+, PHPUnit 10, existing draw\ library (Transform, Canvas, LinearGradient, RadialGradient, Path, SVGParser)

**Design spec:** `docs/superpowers/specs/2026-06-04-gradient-transform-units-design.md`

---

### Task 1: Transform::inverse()

**Files:**
- Modify: `library/draw/Transform.php`
- Modify: `tests/Canvas/TransformTest.php`

- [ ] **Step 1: Write failing tests for inverse()**

Add to `tests/Canvas/TransformTest.php`:

```php
public function test_inverse_of_identity_is_identity(): void
{
    $t = Transform::identity();
    $inv = $t->inverse();
    $this->assertTrue($inv->equals(Transform::identity()));
}

public function test_inverse_of_translate(): void
{
    $t = Transform::translate(10.0, 20.0);
    $inv = $t->inverse();
    [$x, $y] = $inv->apply(15.0, 25.0);
    $this->assertEqualsWithDelta(5.0, $x, 0.0001);
    $this->assertEqualsWithDelta(5.0, $y, 0.0001);
}

public function test_inverse_of_scale(): void
{
    $t = Transform::scale(2.0, 4.0);
    $inv = $t->inverse();
    [$x, $y] = $inv->apply(6.0, 12.0);
    $this->assertEqualsWithDelta(3.0, $x, 0.0001);
    $this->assertEqualsWithDelta(3.0, $y, 0.0001);
}

public function test_inverse_of_rotate(): void
{
    $t = Transform::rotate(M_PI / 4.0);
    $inv = $t->inverse();
    [$x, $y] = $t->apply(3.0, 7.0);
    [$rx, $ry] = $inv->apply($x, $y);
    $this->assertEqualsWithDelta(3.0, $rx, 0.0001);
    $this->assertEqualsWithDelta(7.0, $ry, 0.0001);
}

public function test_inverse_of_composed_transform(): void
{
    $t = Transform::translate(100.0, 200.0)
        ->multiply(Transform::rotate(deg2rad(86.5167)))
        ->multiply(Transform::scale(230.426));
    $inv = $t->inverse();
    [$x, $y] = $t->apply(5.0, 10.0);
    [$rx, $ry] = $inv->apply($x, $y);
    $this->assertEqualsWithDelta(5.0, $rx, 0.0001);
    $this->assertEqualsWithDelta(10.0, $ry, 0.0001);
}

public function test_inverse_roundtrip_preserves_point(): void
{
    $t = Transform::matrix(2.0, 1.0, 0.5, 3.0, 10.0, 20.0);
    $inv = $t->inverse();
    $composed = $t->multiply($inv);
    $this->assertTrue($composed->equals(Transform::identity()));
}

public function test_inverse_of_singular_matrix_throws(): void
{
    $t = Transform::matrix(0.0, 0.0, 0.0, 0.0, 0.0, 0.0);
    $this->expectException(\LogicException::class);
    $t->inverse();
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- tests/Canvas/TransformTest.php`
Expected: FAIL — `Call to undefined method draw\Transform::inverse()`

- [ ] **Step 3: Implement Transform::inverse()**

Add to `library/draw/Transform.php` after `getElements()`:

```php
public function inverse(): self
{
    $det = $this->a * $this->d - $this->b * $this->c;
    if (abs($det) < 1e-15) {
        throw new \LogicException('Cannot invert singular transform matrix');
    }
    return new self(
        $this->d / $det,
        -$this->b / $det,
        -$this->c / $det,
        $this->a / $det,
        ($this->c * $this->f - $this->d * $this->e) / $det,
        ($this->b * $this->e - $this->a * $this->f) / $det,
    );
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test -- tests/Canvas/TransformTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add library/draw/Transform.php tests/Canvas/TransformTest.php
git commit -m "Add Transform::inverse() for 2D affine matrix inversion"
```

---

### Task 2: GradientUnits Enum + Path::getBBox()

**Files:**
- Create: `library/draw/GradientUnits.php`
- Modify: `library/draw/Path.php`
- Modify: `tests/Canvas/PathTest.php` (or create if needed)

- [ ] **Step 1: Create GradientUnits enum**

Create `library/draw/GradientUnits.php`:

```php
<?php

namespace draw;

enum GradientUnits
{
    case ObjectBoundingBox;
    case UserSpaceOnUse;
}
```

- [ ] **Step 2: Write failing test for Path::getBBox()**

Add to the path test file. If `tests/Canvas/PathTest.php` does not exist, create it.

```php
<?php

namespace Tests\Canvas;

use draw\Path;
use PHPUnit\Framework\TestCase;

class PathTest extends TestCase
{
    public function test_getBBox_empty_path_returns_null(): void
    {
        $path = new Path();
        $this->assertNull($path->getBBox());
    }

    public function test_getBBox_rect(): void
    {
        $path = Path::rect(10.0, 20.0, 30.0, 40.0);
        $bbox = $path->getBBox();
        $this->assertNotNull($bbox);
        $this->assertEqualsWithDelta(10.0, $bbox['x'], 0.001);
        $this->assertEqualsWithDelta(20.0, $bbox['y'], 0.001);
        $this->assertEqualsWithDelta(30.0, $bbox['w'], 0.001);
        $this->assertEqualsWithDelta(40.0, $bbox['h'], 0.001);
    }

    public function test_getBBox_circle(): void
    {
        $path = Path::circle(50.0, 50.0, 25.0);
        $bbox = $path->getBBox();
        $this->assertNotNull($bbox);
        $this->assertEqualsWithDelta(25.0, $bbox['x'], 0.5);
        $this->assertEqualsWithDelta(25.0, $bbox['y'], 0.5);
        $this->assertEqualsWithDelta(50.0, $bbox['w'], 0.5);
        $this->assertEqualsWithDelta(50.0, $bbox['h'], 0.5);
    }

    public function test_getBBox_triangle(): void
    {
        $path = Path::polygon([[0.0, 0.0], [10.0, 0.0], [5.0, 10.0]]);
        $bbox = $path->getBBox();
        $this->assertNotNull($bbox);
        $this->assertEqualsWithDelta(0.0, $bbox['x'], 0.001);
        $this->assertEqualsWithDelta(0.0, $bbox['y'], 0.001);
        $this->assertEqualsWithDelta(10.0, $bbox['w'], 0.001);
        $this->assertEqualsWithDelta(10.0, $bbox['h'], 0.001);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `composer test -- tests/Canvas/PathTest.php`
Expected: FAIL — `Call to undefined method draw\Path::getBBox()`

- [ ] **Step 4: Implement Path::getBBox()**

Add to `library/draw/Path.php` after `getTransform()`:

```php
/**
 * @return array{x: float, y: float, w: float, h: float}|null
 */
public function getBBox(float $tolerance = 0.5): ?array
{
    $subpaths = $this->flatten($tolerance);
    $minX = PHP_FLOAT_MAX;
    $minY = PHP_FLOAT_MAX;
    $maxX = PHP_FLOAT_MIN;
    $maxY = PHP_FLOAT_MIN;
    $found = false;
    foreach ($subpaths as $sp) {
        foreach ($sp['vertices'] as $v) {
            $found = true;
            if ($v[0] < $minX) $minX = $v[0];
            if ($v[1] < $minY) $minY = $v[1];
            if ($v[0] > $maxX) $maxX = $v[0];
            if ($v[1] > $maxY) $maxY = $v[1];
        }
    }
    if (!$found) {
        return null;
    }
    return ['x' => $minX, 'y' => $minY, 'w' => $maxX - $minX, 'h' => $maxY - $minY];
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `composer test -- tests/Canvas/PathTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add library/draw/GradientUnits.php library/draw/Path.php tests/Canvas/PathTest.php
git commit -m "Add GradientUnits enum and Path::getBBox() for bounding box computation"
```

---

### Task 3: Gradient Classes — sampleTransform Support

**Files:**
- Modify: `library/draw/LinearGradient.php`
- Modify: `library/draw/RadialGradient.php`
- Modify: `tests/Canvas/GradientTest.php`

- [ ] **Step 1: Write failing tests for sampleTransform**

Add to `tests/Canvas/GradientTest.php`:

```php
public function test_linear_gradient_with_sample_transform_translate(): void
{
    $stops = [new ColorStop(0.0, 255, 0, 0), new ColorStop(1.0, 0, 0, 255)];
    $sampleTransform = Transform::translate(100.0, 0.0);
    $g = new LinearGradient(0.0, 5.0, 10.0, 5.0, $stops, SpreadMethod::Pad, null, $sampleTransform);
    $rgb = $g->getColorAt(105.0, 5.0);
    $this->assertEqualsWithDelta(255, $rgb[0], 2);
    $this->assertEqualsWithDelta(0, $rgb[1], 2);
    $this->assertEqualsWithDelta(0, $rgb[2], 2);
    $rgb = $g->getColorAt(110.0, 5.0);
    $this->assertEqualsWithDelta(0, $rgb[0], 2);
    $this->assertEqualsWithDelta(0, $rgb[1], 2);
    $this->assertEqualsWithDelta(255, $rgb[2], 2);
}

public function test_linear_gradient_without_sample_transform_unchanged(): void
{
    $stops = [new ColorStop(0.0, 255, 0, 0), new ColorStop(1.0, 0, 0, 255)];
    $g = new LinearGradient(0.0, 5.0, 10.0, 5.0, $stops);
    $rgb = $g->getColorAt(5.0, 5.0);
    $this->assertEqualsWithDelta(128, $rgb[0], 2);
}

public function test_radial_gradient_with_sample_transform(): void
{
    $stops = [new ColorStop(0.0, 255, 255, 255), new ColorStop(1.0, 0, 0, 0)];
    $sampleTransform = Transform::translate(50.0, 50.0);
    $g = new RadialGradient(10.0, 10.0, 10.0, $stops, null, null, SpreadMethod::Pad, null, $sampleTransform);
    $rgb = $g->getColorAt(60.0, 60.0);
    $this->assertEqualsWithDelta(255, $rgb[0], 2);
    $rgb = $g->getColorAt(70.0, 60.0);
    $this->assertEqualsWithDelta(0, $rgb[0], 5);
}

public function test_radial_gradient_with_sample_transform_composed(): void
{
    $stops = [new ColorStop(0.0, 255, 0, 0), new ColorStop(1.0, 0, 0, 255)];
    $gradientTransform = Transform::translate(109.0, 16.0)
        ->multiply(Transform::rotate(deg2rad(86.5167)))
        ->multiply(Transform::scale(230.426));
    $g = new RadialGradient(0.0, 0.0, 1.0, $stops, null, null, SpreadMethod::Pad, null, $gradientTransform);
    $center = $gradientTransform->apply(0.0, 0.0);
    $rgb = $g->getColorAt($center[0], $center[1]);
    $this->assertEqualsWithDelta(255, $rgb[0], 2);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- tests/Canvas/GradientTest.php`
Expected: FAIL — `Unknown named parameter $sampleTransform`

- [ ] **Step 3: Add sampleTransform to LinearGradient**

Modify `library/draw/LinearGradient.php`. Add a private field and constructor parameter:

```php
<?php

namespace draw;

class LinearGradient implements Paint
{
    /** @var ColorStop[] */
    public readonly array $stops;
    private ?Transform $sampleTransformInverse = null;

    /**
     * @param array<ColorStop> $stops
     */
    public function __construct(
        public readonly float $x1,
        public readonly float $y1,
        public readonly float $x2,
        public readonly float $y2,
        array $stops,
        public readonly SpreadMethod $spreadMethod = SpreadMethod::Pad,
        public readonly ?Dithering $dithering = null,
        ?Transform $sampleTransform = null,
    ) {
        if (count($stops) < 2) {
            throw new \InvalidArgumentException('LinearGradient requires at least 2 stops');
        }
        usort($stops, fn(ColorStop $a, ColorStop $b) => $a->offset <=> $b->offset);
        $this->stops = $stops;
        if ($sampleTransform !== null) {
            $this->sampleTransformInverse = $sampleTransform->inverse();
        }
    }

    use GradientMath;

    public function isSolid(): bool
    {
        return false;
    }

    public function getColorAt(float $x, float $y): array
    {
        if ($this->sampleTransformInverse !== null) {
            [$x, $y] = $this->sampleTransformInverse->apply($x, $y);
        }
        $dx = $this->x2 - $this->x1;
        $dy = $this->y2 - $this->y1;
        $lenSq = $dx * $dx + $dy * $dy;
        if ($lenSq == 0.0) {
            return [$this->stops[0]->r, $this->stops[0]->g, $this->stops[0]->b];
        }
        $t = (($x - $this->x1) * $dx + ($y - $this->y1) * $dy) / $lenSq;
        $t = $this->applySpread($t);
        return $this->interpolateStops($t);
    }

    public function getDithering(): ?Dithering
    {
        return $this->dithering;
    }

    public function withSampleTransform(?Transform $sampleTransform): self
    {
        return new self(
            $this->x1, $this->y1, $this->x2, $this->y2,
            $this->stops,
            $this->spreadMethod,
            $this->dithering,
            $sampleTransform,
        );
    }
}
```

- [ ] **Step 4: Add sampleTransform to RadialGradient**

Modify `library/draw/RadialGradient.php`:

```php
<?php

namespace draw;

class RadialGradient implements Paint
{
    /** @var ColorStop[] */
    public readonly array $stops;
    public readonly float $fx;
    public readonly float $fy;
    private ?Transform $sampleTransformInverse = null;

    /**
     * @param array<ColorStop> $stops
     */
    public function __construct(
        public readonly float $cx,
        public readonly float $cy,
        public readonly float $r,
        array $stops,
        ?float $fx = null,
        ?float $fy = null,
        public readonly SpreadMethod $spreadMethod = SpreadMethod::Pad,
        public readonly ?Dithering $dithering = null,
        ?Transform $sampleTransform = null,
    ) {
        if ($r <= 0) {
            throw new \InvalidArgumentException("RadialGradient radius must be > 0, got $r");
        }
        if (count($stops) < 2) {
            throw new \InvalidArgumentException('RadialGradient requires at least 2 stops');
        }
        usort($stops, fn(ColorStop $a, ColorStop $b) => $a->offset <=> $b->offset);
        $this->stops = $stops;
        $this->fx = $fx ?? $cx;
        $this->fy = $fy ?? $cy;
        $fdx = $this->fx - $cx;
        $fdy = $this->fy - $cy;
        if (sqrt($fdx * $fdx + $fdy * $fdy) >= $r) {
            throw new \InvalidArgumentException('Focal point must be inside the circle');
        }
        if ($sampleTransform !== null) {
            $this->sampleTransformInverse = $sampleTransform->inverse();
        }
    }

    use GradientMath;

    public function isSolid(): bool
    {
        return false;
    }

    public function getColorAt(float $x, float $y): array
    {
        if ($this->sampleTransformInverse !== null) {
            [$x, $y] = $this->sampleTransformInverse->apply($x, $y);
        }
        $dx = $x - $this->fx;
        $dy = $y - $this->fy;
        $dist = sqrt($dx * $dx + $dy * $dy);
        $t = $dist / $this->r;
        $t = $this->applySpread($t);
        return $this->interpolateStops($t);
    }

    public function getDithering(): ?Dithering
    {
        return $this->dithering;
    }

    public function withSampleTransform(?Transform $sampleTransform): self
    {
        $origFx = ($this->fx !== $this->cx) ? $this->fx : null;
        $origFy = ($this->fy !== $this->cy) ? $this->fy : null;
        return new self(
            $this->cx, $this->cy, $this->r,
            $this->stops,
            $origFx, $origFy,
            $this->spreadMethod,
            $this->dithering,
            $sampleTransform,
        );
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `composer test -- tests/Canvas/GradientTest.php`
Expected: PASS

- [ ] **Step 6: Run full test suite to ensure no regressions**

Run: `composer test`
Expected: PASS (all 438+ tests)

- [ ] **Step 7: Commit**

```bash
git add library/draw/LinearGradient.php library/draw/RadialGradient.php tests/Canvas/GradientTest.php
git commit -m "Add sampleTransform to LinearGradient and RadialGradient for gradient coordinate mapping"
```

---

### Task 4: Canvas Inverse CTM

**Files:**
- Modify: `library/draw/Canvas.php`
- Modify: `tests/Canvas/CanvasTest.php` (or create gradient+transform test)

- [ ] **Step 1: Write failing test for inverse CTM in gradient sampling**

Create `tests/Canvas/CanvasGradientTransformTest.php`:

```php
<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\ColorStop;
use draw\LinearGradient;
use draw\Transform;
use PHPUnit\Framework\TestCase;

class CanvasGradientTransformTest extends TestCase
{
    public function test_gradient_with_identity_ctm_samples_pixel_coords(): void
    {
        $stops = [new ColorStop(0.0, 255, 0, 0), new ColorStop(1.0, 0, 0, 255)];
        $gradient = new LinearGradient(0.0, 5.0, 10.0, 5.0, $stops);
        $canvas = Canvas::createBlank(10, 10);
        $canvas->drawPoint(5, 5, $gradient);
        $pixel = $canvas->getPixel(5, 5);
        $this->assertNotNull($pixel->fg);
    }

    public function test_gradient_with_viewbox_transform_samples_user_coords(): void
    {
        $stops = [new ColorStop(0.0, 255, 0, 0), new ColorStop(1.0, 0, 0, 255)];
        $gradient = new LinearGradient(0.0, 50.0, 100.0, 50.0, $stops);
        $canvas = Canvas::createBlank(10, 10);
        $canvas->save();
        $canvas->scale(0.1, 0.1);
        $canvas->drawPoint(5, 5, $gradient);
        $canvas->restore();
        $pixel = $canvas->getPixel(5, 5);
        $this->assertNotNull($pixel->fg);
    }
}
```

- [ ] **Step 2: Add inverseCtm to Canvas**

Modify `library/draw/Canvas.php`. Add the inverse CTM field alongside the existing `$ctm`:

After `private Transform $ctm;` (line 16), add:

```php
private Transform $inverseCtm;
```

In `__construct()` after `$this->ctm = Transform::identity();` (line 38), add:

```php
$this->inverseCtm = Transform::identity();
```

Update `save()`:

```php
public function save(): void
{
    $this->transformStack[] = [$this->ctm, $this->inverseCtm];
}
```

Update `restore()`:

```php
public function restore(): void
{
    if (count($this->transformStack) === 0) {
        throw new \LogicException('Cannot restore: transform stack is empty');
    }
    $saved = array_pop($this->transformStack);
    $this->ctm = $saved[0];
    $this->inverseCtm = $saved[1];
}
```

Update `setTransform()`:

```php
public function setTransform(Transform $t): void
{
    $this->ctm = $t;
    $this->inverseCtm = $t->inverse();
}
```

Update `concatTransform()`:

```php
public function concatTransform(Transform $t): void
{
    $this->ctm = $this->ctm->multiply($t);
    $this->inverseCtm = $t->inverse()->multiply($this->inverseCtm);
}
```

- [ ] **Step 3: Apply inverse CTM at getColorAt call sites**

There are 3 getColorAt call sites. Wrap each to apply inverse CTM when non-identity.

**Site 1: `drawPoint()` around line 293.** Find the `else` branch that calls `getColorAt`:

```php
                } else {
                    $effectiveDithering = $paint->getDithering() ?? $this->dithering;
                    [$r, $g, $b] = $paint->getColorAt((float) $x, (float) $y);
```

Change to:

```php
                } else {
                    $effectiveDithering = $paint->getDithering() ?? $this->dithering;
                    $sx = (float) $x;
                    $sy = (float) $y;
                    if (!$this->isIdentity($this->ctm)) {
                        [$sx, $sy] = $this->inverseCtm->apply($sx, $sy);
                    }
                    [$r, $g, $b] = $paint->getColorAt($sx, $sy);
```

**Site 2: `drawLineInternal()` around line 405.** Same pattern — find the `else` branch calling `getColorAt`:

```php
                    [$r, $g, $b] = $paint->getColorAt((float) $x, (float) $y);
```

Change to:

```php
                    $sx = (float) $x;
                    $sy = (float) $y;
                    if (!$this->isIdentity($this->ctm)) {
                        [$sx, $sy] = $this->inverseCtm->apply($sx, $sy);
                    }
                    [$r, $g, $b] = $paint->getColorAt($sx, $sy);
```

**Site 3: `fillPolygonScanlineMulti()` around line 700.** Find the `else` branch calling `getColorAt`:

```php
                            [$r, $g, $b] = $paint->getColorAt((float) $xx, (float) $Y);
```

Change to:

```php
                            $sx = (float) $xx;
                            $sy = (float) $Y;
                            if (!$this->isIdentity($this->ctm)) {
                                [$sx, $sy] = $this->inverseCtm->apply($sx, $sy);
                            }
                            [$r, $g, $b] = $paint->getColorAt($sx, $sy);
```

- [ ] **Step 4: Run tests to verify**

Run: `composer test -- tests/Canvas/CanvasGradientTransformTest.php`
Expected: PASS

- [ ] **Step 5: Run full test suite for regressions**

Run: `composer test`
Expected: PASS — when CTM is identity (all existing tests), inverseCtm is identity, so getColorAt coords are unchanged.

- [ ] **Step 6: Commit**

```bash
git add library/draw/Canvas.php tests/Canvas/CanvasGradientTransformTest.php
git commit -m "Add inverse CTM to Canvas for user-space gradient sampling"
```

---

### Task 5: SVGParser — gradientTransform + userSpaceOnUse

**Files:**
- Modify: `library/draw/SVGParser.php`
- Modify: `tests/Canvas/SVGParserTest.php`

- [ ] **Step 1: Write failing tests**

Add to `tests/Canvas/SVGParserTest.php`:

```php
public function test_linear_gradient_with_gradientTransform(): void
{
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g1" x1="0" y1="0" x2="1" y2="0" gradientTransform="translate(0, 5)"><stop offset="0" stop-color="#ff0000"/><stop offset="1" stop-color="#0000ff"/></linearGradient></defs><rect x="0" y="0" width="20" height="10" fill="url(#g1)"/></svg>';
    $doc = SVGParser::parseString($svg);
    $canvas = Canvas::createBlank(20, 10);
    $doc->render($canvas);
    $this->assertNotNull($canvas->data[5][5]->fg);
    $this->assertNotNull($canvas->data[5][15]->fg);
}

public function test_radial_gradient_with_gradientTransform(): void
{
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><radialGradient id="rg" cx="0" cy="0" r="1" gradientTransform="translate(10, 10) scale(10)"><stop offset="0" stop-color="white"/><stop offset="1" stop-color="black"/></radialGradient></defs><rect x="0" y="0" width="20" height="20" fill="url(#rg)"/></svg>';
    $doc = SVGParser::parseString($svg);
    $canvas = Canvas::createBlank(20, 20);
    $doc->render($canvas);
    $this->assertNotNull($canvas->data[10][10]->fg);
}

public function test_gradient_with_userSpaceOnUse(): void
{
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g1" x1="0" y1="5" x2="20" y2="5" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#ff0000"/><stop offset="1" stop-color="#0000ff"/></linearGradient></defs><rect x="0" y="0" width="20" height="10" fill="url(#g1)"/></svg>';
    $doc = SVGParser::parseString($svg);
    $canvas = Canvas::createBlank(20, 10);
    $doc->render($canvas);
    $this->assertNotNull($canvas->data[5][5]->fg);
}

public function test_gradient_with_viewBox_and_gradientTransform(): void
{
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><defs><radialGradient id="rg" cx="0" cy="0" r="1" gradientTransform="translate(100, 100) scale(100)"><stop offset="0" stop-color="#ffcc00"/><stop offset="1" stop-color="#0066ff"/></radialGradient></defs><rect x="0" y="0" width="200" height="200" fill="url(#rg)"/></svg>';
    $doc = SVGParser::parseString($svg);
    $canvas = Canvas::createBlank(20, 20);
    $doc->render($canvas);
    $this->assertNotNull($canvas->data[10][10]->fg);
    $this->assertNotNull($canvas->data[5][5]->fg);
}

public function test_gradient_default_gradientUnits_is_objectBoundingBox(): void
{
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g1" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#ff0000"/><stop offset="1" stop-color="#0000ff"/></linearGradient></defs><rect x="0" y="0" width="20" height="10" fill="url(#g1)"/></svg>';
    $doc = SVGParser::parseString($svg);
    $canvas = Canvas::createBlank(20, 10);
    $doc->render($canvas);
    $this->assertNotNull($canvas->data[5][5]->fg);
    $this->assertNotNull($canvas->data[5][15]->fg);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- tests/Canvas/SVGParserTest.php --filter test_linear_gradient_with_gradientTransform`
Expected: Some tests may pass already (no assertion failure), but the gradientTransform is not actually being applied yet. The key test that should show wrong behavior is `test_gradient_with_viewBox_and_gradientTransform`.

- [ ] **Step 3: Modify parseGradientElement to parse gradientTransform and gradientUnits**

In `library/draw/SVGParser.php`, update `parseGradientElement()` (starting at line 563):

```php
private static function parseGradientElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger): void
{
    $id = (string)($el['id'] ?? '');
    if ($id === '') {
        return;
    }

    $stops = self::parseGradientStops($el, $styles);
    if (count($stops) < 2) {
        return;
    }

    $spread = match (strtolower((string)($el['spreadMethod'] ?? 'pad'))) {
        'reflect' => SpreadMethod::Reflect,
        'repeat' => SpreadMethod::Repeat,
        default => SpreadMethod::Pad,
    };

    $gradientUnits = match (strtolower((string)($el['gradientUnits'] ?? 'objectBoundingBox'))) {
        'userspaceonuse' => GradientUnits::UserSpaceOnUse,
        default => GradientUnits::ObjectBoundingBox,
    };

    $gradientTransform = null;
    $gtStr = (string)($el['gradientTransform'] ?? '');
    if ($gtStr !== '') {
        $gradientTransform = self::parseTransform($gtStr);
    }

    $name = $el->getName();
    if ($name === 'linearGradient') {
        $x1 = self::parseGradientCoord($el, 'x1', 0.0);
        $y1 = self::parseGradientCoord($el, 'y1', 0.0);
        $x2 = self::parseGradientCoord($el, 'x2', 1.0);
        $y2 = self::parseGradientCoord($el, 'y2', 0.0);
        $defs[$id] = [
            'gradient' => new LinearGradient($x1, $y1, $x2, $y2, $stops, $spread),
            'units' => $gradientUnits,
            'transform' => $gradientTransform,
        ];
    } elseif ($name === 'radialGradient') {
        $cx = self::parseGradientCoord($el, 'cx', 0.5);
        $cy = self::parseGradientCoord($el, 'cy', 0.5);
        $r = self::parseGradientCoord($el, 'r', 0.5);
        $fx = self::parseOptionalGradientCoord($el, 'fx');
        $fy = self::parseOptionalGradientCoord($el, 'fy');
        $defs[$id] = [
            'gradient' => new RadialGradient($cx, $cy, $r, $stops, $fx, $fy, $spread),
            'units' => $gradientUnits,
            'transform' => $gradientTransform,
        ];
    }
}
```

Note: This changes the defs storage format from `$defs[$id] = Paint` to `$defs[$id] = ['gradient' => Paint, 'units' => GradientUnits, 'transform' => ?Transform]`. All code that reads from defs must be updated.

- [ ] **Step 4: Update parsePaintAttr and parseStrokeAttr for new defs format**

In `parsePaintAttr()` (line 679), update the `url(#...)` resolution:

Change:
```php
        if (preg_match('/^url\(#(.+)\)$/', $val, $m)) {
            $id = $m[1];
            if (isset($defs[$id])) {
                return $defs[$id];
            }
```

To:
```php
        if (preg_match('/^url\(#(.+)\)$/', $val, $m)) {
            $id = $m[1];
            if (isset($defs[$id])) {
                $entry = $defs[$id];
                if ($entry instanceof Paint) {
                    return $entry;
                }
                return $entry['gradient'];
            }
```

Same change in `parseStrokeAttr()` (line 720):

Change:
```php
            if (isset($defs[$id])) {
                $paint = $defs[$id];
```

To:
```php
            if (isset($defs[$id])) {
                $entry = $defs[$id];
                $paint = ($entry instanceof Paint) ? $entry : $entry['gradient'];
```

Also update `parseDefsElement()` — the line that stores non-gradient defs elements (`$defs[$id] = $child;` at line 556). This stores raw SimpleXMLElement objects, which are not Paint instances. These are already handled by the `instanceof Paint` check above, so no change needed there.

- [ ] **Step 5: Add gradient paint resolution with sampleTransform**

Add a new helper method to SVGParser. This resolves a gradient paint with the appropriate sampleTransform. Add it after `parsePaintAttr`:

```php
private static function resolveGradientPaint(
    \SimpleXMLElement $el,
    string $attr,
    array &$defs,
    array $styles,
    ?Path $path,
    ?LoggerInterface $logger,
): ?Paint {
    $val = self::getEffectiveAttr($el, $attr, $styles);
    if ($val === 'none') {
        return new NoPaint();
    }
    if ($val === '') {
        if ($attr === 'fill') {
            $rgb = [0, 0, 0];
            $code = IrcPalette::nearestColor($rgb[0], $rgb[1], $rgb[2]);
            return new Color($code, null);
        }
        return null;
    }

    if (preg_match('/^url\(#(.+)\)$/', $val, $m)) {
        $id = $m[1];
        if (!isset($defs[$id])) {
            $logger?->warning("SVG reference not found: #{$id}");
            return new NoPaint();
        }
        $entry = $defs[$id];
        if ($entry instanceof Paint) {
            return $entry;
        }
        $gradient = $entry['gradient'];
        $units = $entry['units'];
        $gradientTransform = $entry['transform'];

        if ($units === GradientUnits::ObjectBoundingBox && $path !== null) {
            $bbox = $path->getBBox();
            if ($bbox !== null && ($bbox['w'] > 0 || $bbox['h'] > 0)) {
                $bboxTransform = Transform::translate($bbox['x'], $bbox['y'])
                    ->multiply(Transform::scale($bbox['w'], $bbox['h']));
                $sampleTransform = $gradientTransform !== null
                    ? $bboxTransform->multiply($gradientTransform)
                    : $bboxTransform;
                return $gradient->withSampleTransform($sampleTransform);
            }
        }

        if ($gradientTransform !== null) {
            return $gradient->withSampleTransform($gradientTransform);
        }
        return $gradient;
    }

    $rgb = SvgColor::parse($val);
    if ($rgb === null) {
        return null;
    }
    $code = IrcPalette::nearestColor($rgb[0], $rgb[1], $rgb[2]);
    return new Color($code, null);
}
```

- [ ] **Step 6: Update buildShape to use resolveGradientPaint**

Modify `buildShape()` to pass the Path to paint resolution:

```php
private static function buildShape(Path $path, \SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger): SceneNode
{
    $display = self::getEffectiveAttr($el, 'display', $styles);
    if ($display === 'none') {
        return new Group();
    }

    $fill = self::resolveGradientPaint($el, 'fill', $defs, $styles, $path, $logger);
    $stroke = self::parseStrokeAttr($el, $defs, $styles, $logger);
    $transform = self::parseOptionalTransform($el, $styles);
    $opacity = self::parseFloatAttr($el, 'opacity', $styles);
    $fillOpacity = self::parseFloatAttr($el, 'fill-opacity', $styles);
    $fillRule = self::parseFillRuleAttr($el, $styles);

    return new Shape(
        path: $path,
        fill: $fill,
        stroke: $stroke,
        transform: $transform,
        opacity: $opacity,
        fillOpacity: $fillOpacity,
        fillRule: $fillRule,
    );
}
```

Note: `parseStrokeAttr` also resolves gradient paints from defs. For stroke gradients with objectBoundingBox, the same bbox logic applies. For now, the existing `parseStrokeAttr` handles the defs format via the `instanceof Paint` check, but won't apply sampleTransform for stroke gradients. This can be enhanced later — stroke gradients are extremely rare in IRC art SVGs.

- [ ] **Step 7: Add GradientUnits import to SVGParser**

Add to the imports at the top of `SVGParser.php`:

```php
use draw\GradientUnits;
```

- [ ] **Step 8: Run tests**

Run: `composer test -- tests/Canvas/SVGParserTest.php`
Expected: PASS — all existing gradient tests still pass (objectBoundingBox with default sampleTransform now gets bboxTransform instead of raw 0-1 coords)

If any existing tests fail, it's because the defs format changed or objectBoundingBox handling changed. Debug and fix.

- [ ] **Step 9: Run full test suite**

Run: `composer test`
Expected: PASS

- [ ] **Step 10: Commit**

```bash
git add library/draw/SVGParser.php tests/Canvas/SVGParserTest.php
git commit -m "Parse gradientTransform and gradientUnits in SVG gradients with sampleTransform resolution"
```

---

### Task 6: Reference Transform for Nested Groups

**Files:**
- Modify: `library/draw/SVGParser.php`
- Modify: `tests/Canvas/SVGParserTest.php`

- [ ] **Step 1: Write failing test**

Add to `tests/Canvas/SVGParserTest.php`:

```php
public function test_userSpaceOnUse_gradient_inside_transformed_group(): void
{
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g1" x1="0" y1="0" x2="20" y2="0" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#ff0000"/><stop offset="1" stop-color="#0000ff"/></linearGradient></defs><g transform="translate(10, 0)"><rect x="0" y="0" width="20" height="10" fill="url(#g1)"/></g></svg>';
    $doc = SVGParser::parseString($svg);
    $canvas = Canvas::createBlank(30, 10);
    $doc->render($canvas);
    $this->assertNotNull($canvas->data[5][15]->fg);
    $this->assertNotNull($canvas->data[5][25]->fg);
}
```

- [ ] **Step 2: Thread transform stack through parse methods**

Add a `Transform $parentTransform` parameter to the parse methods that recurse into children.

Update `parseElement()` signature:

```php
private static function parseElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger, Transform $parentTransform): SceneNode
```

Update each match arm to pass `$parentTransform`:
- `'svg' => self::parseSvgElement($el, $defs, $styles, $logger, $parentTransform),`
- `'g' => self::parseGroupElement($el, $defs, $styles, $logger, $parentTransform),`
- For shape elements (`path`, `rect`, `circle`, etc.): pass `$parentTransform` to `buildShape()`
- For `defs`, `linearGradient`, `radialGradient`, `style`, `default`: no change needed (they don't use transforms)

Update `parseSvgElement()`:

```php
private static function parseSvgElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger, Transform $parentTransform): Group
{
    $group = new Group();
    foreach (self::svgChildren($el) as $child) {
        $group->addChild(self::parseElement($child, $defs, $styles, $logger, $parentTransform));
    }
    return $group;
}
```

Update `parseGroupElement()`:

```php
private static function parseGroupElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger, Transform $parentTransform): Group
{
    $fill = self::parsePaintAttr($el, 'fill', $defs, $styles, $logger);
    $stroke = self::parseStrokeAttr($el, $defs, $styles, $logger);
    $transform = self::parseOptionalTransform($el, $styles);
    $opacity = self::parseFloatAttr($el, 'opacity', $styles);
    $fillOpacity = self::parseFloatAttr($el, 'fill-opacity', $styles);
    $fillRule = self::parseFillRuleAttr($el, $styles);

    $childTransform = $parentTransform;
    if ($transform !== null) {
        $childTransform = $parentTransform->multiply($transform);
    }

    $group = new Group(
        fill: $fill,
        stroke: $stroke,
        transform: $transform,
        opacity: $opacity,
        fillOpacity: $fillOpacity,
        fillRule: $fillRule,
    );

    foreach (self::svgChildren($el) as $child) {
        $group->addChild(self::parseElement($child, $defs, $styles, $logger, $childTransform));
    }

    return $group;
}
```

Update each shape parse method to pass `$parentTransform` to `buildShape()`. For example, `parsePathElement()`:

```php
private static function parsePathElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger, Transform $parentTransform): SceneNode
{
    $d = (string)($el['d'] ?? '');
    $path = self::parseDString($d);
    return self::buildShape($path, $el, $defs, $styles, $logger, $parentTransform);
}
```

Do the same for `parseRectElement`, `parseCircleElement`, `parseEllipseElement`, `parseLineElement`, `parsePolylineElement`, `parsePolygonElement` — add `Transform $parentTransform` param and pass to `buildShape()`.

Update the top-level `parse()` call to pass `Transform::identity()` as the initial parent transform. In the `parse()` method, find where `parseSvgElement` is called and pass `Transform::identity()`:

```php
$root = self::parseSvgElement($xml, $defs, $styles, $logger, Transform::identity());
```

- [ ] **Step 3: Update buildShape to use parentTransform for sampleTransform**

Update `buildShape()` signature:

```php
private static function buildShape(Path $path, \SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger, Transform $parentTransform): SceneNode
```

Update `resolveGradientPaint` to accept and use `$parentTransform`:

```php
private static function resolveGradientPaint(
    \SimpleXMLElement $el,
    string $attr,
    array &$defs,
    array $styles,
    ?Path $path,
    Transform $parentTransform,
    ?LoggerInterface $logger,
): ?Paint {
```

In `resolveGradientPaint`, for userSpaceOnUse gradients, compose the parentTransform into the sampleTransform. Find the section where userSpaceOnUse is handled (after the objectBoundingBox block):

```php
        if ($gradientTransform !== null) {
            $sampleTransform = $parentTransform->multiply($gradientTransform);
            return $gradient->withSampleTransform($sampleTransform);
        }
        if ($parentTransform->equals(Transform::identity()) === false) {
            return $gradient->withSampleTransform($parentTransform);
        }
        return $gradient;
```

And for objectBoundingBox, compose parentTransform too:

```php
        if ($units === GradientUnits::ObjectBoundingBox && $path !== null) {
            $bbox = $path->getBBox();
            if ($bbox !== null && ($bbox['w'] > 0 || $bbox['h'] > 0)) {
                $bboxTransform = Transform::translate($bbox['x'], $bbox['y'])
                    ->multiply(Transform::scale($bbox['w'], $bbox['h']));
                $sampleTransform = $gradientTransform !== null
                    ? $bboxTransform->multiply($gradientTransform)
                    : $bboxTransform;
                return $gradient->withSampleTransform($sampleTransform);
            }
        }
```

Note: For objectBoundingBox, the parentTransform is NOT composed into the sampleTransform because objectBoundingBox coordinates are always relative to the shape's own bounding box in its local coordinate system, and the Canvas inverse CTM already maps back through all group transforms.

Update the call in `buildShape()`:

```php
    $fill = self::resolveGradientPaint($el, 'fill', $defs, $styles, $path, $parentTransform, $logger);
```

- [ ] **Step 4: Run tests**

Run: `composer test`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add library/draw/SVGParser.php tests/Canvas/SVGParserTest.php
git commit -m "Track parent transform for nested group gradient coordinate mapping"
```

---

### Task 7: Update Roadmap and Run Final Verification

**Files:**
- Modify: `docs/superpowers/specs/2026-06-02-draw-library-svg-roadmap.md`

- [ ] **Step 1: Run full test suite**

Run: `composer test`
Expected: PASS — all tests including new ones

- [ ] **Step 2: Run static analysis**

Run: `composer phpstan`
Expected: PASS (no errors in changed files)

- [ ] **Step 3: Update roadmap**

In `docs/superpowers/specs/2026-06-02-draw-library-svg-roadmap.md`, find the Gradient section under Tier 2 and add items for `gradientTransform` and `gradientUnits`. Mark them as done if the roadmap tracks completion status.

- [ ] **Step 4: Final commit**

```bash
git add docs/superpowers/specs/2026-06-02-draw-library-svg-roadmap.md
git commit -m "Update roadmap: gradientTransform + gradientUnits support complete"
```
