# Gradient Paint Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add linear and radial gradient fills/strokes to the draw library via a `Paint` interface.

**Architecture:** Introduce a `Paint` interface with `getColorAt()` and `isSolid()`. `Color` implements it (fast path). New `LinearGradient` and `RadialGradient` classes compute per-pixel RGB from position, quantized to IRC via `IrcPalette::nearestColor()`. Each gradient class contains its own spread method and stop interpolation logic (small enough to keep inline). Canvas methods (`drawPath`, `drawPoint`, `drawLineInternal`, `fillPolygonScanlineMulti`) and `StrokeStyle` migrate from `Color` to `Paint` parameters.

**Tech Stack:** PHP 8.1+, PHPUnit 10, existing `IrcPalette` + `Itwmw\ColorDifference` for quantization.

---

### Task 1: Paint Interface

**Files:**
- Create: `library/draw/Paint.php`
- Test: `tests/Canvas/PaintTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Canvas;

use draw\Color;
use draw\Paint;
use PHPUnit\Framework\TestCase;

class PaintTest extends TestCase
{
    public function test_color_implements_paint(): void
    {
        $color = new Color(4, null);
        $this->assertInstanceOf(Paint::class, $color);
    }

    public function test_color_is_solid(): void
    {
        $color = new Color(4, null);
        $this->assertTrue($color->isSolid());
    }

    public function test_color_get_color_at_returns_rgb(): void
    {
        $color = new Color(0, null);
        $rgb = $color->getColorAt(0.0, 0.0);
        $this->assertSame([255, 255, 255], $rgb);
    }

    public function test_color_get_color_at_null_fg_returns_black(): void
    {
        $color = new Color(null, null);
        $rgb = $color->getColorAt(0.0, 0.0);
        $this->assertSame([0, 0, 0], $rgb);
    }

    public function test_color_get_color_at_consistent_across_positions(): void
    {
        $color = new Color(4, null);
        $rgb1 = $color->getColorAt(0.0, 0.0);
        $rgb2 = $color->getColorAt(100.0, 200.0);
        $this->assertSame($rgb1, $rgb2);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Canvas/PaintTest.php`
Expected: FATAL — class `draw\Paint` not found

- [ ] **Step 3: Create Paint interface**

Create `library/draw/Paint.php`:

```php
<?php

namespace draw;

interface Paint
{
    /**
     * @return array{int, int, int}
     */
    public function getColorAt(float $x, float $y): array;

    public function isSolid(): bool;
}
```

- [ ] **Step 4: Update Color to implement Paint**

In `library/draw/Color.php`, add `implements Paint` to the class declaration and add the two interface methods:

```php
class Color implements Paint
```

Add these methods before the existing `equals()` method:

```php
    public function isSolid(): bool
    {
        return true;
    }

    public function getColorAt(float $x, float $y): array
    {
        if ($this->fg === null) {
            return [0, 0, 0];
        }
        return IrcPalette::getRgb($this->fg);
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `composer test -- tests/Canvas/PaintTest.php`
Expected: 5 tests, all PASS

- [ ] **Step 6: Run full test suite**

Run: `composer test`
Expected: All existing tests + 5 new tests pass

- [ ] **Step 7: Commit**

```bash
git add library/draw/Paint.php library/draw/Color.php tests/Canvas/PaintTest.php
git commit -m "feat(draw): add Paint interface, Color implements it"
```

---

### Task 2: SpreadMethod Enum and ColorStop

**Files:**
- Create: `library/draw/SpreadMethod.php`
- Create: `library/draw/ColorStop.php`
- Test: `tests/Canvas/GradientTest.php` (create with validation tests)

- [ ] **Step 1: Write the failing tests**

Create `tests/Canvas/GradientTest.php`:

```php
<?php

namespace Tests\Canvas;

use draw\ColorStop;
use draw\SpreadMethod;
use PHPUnit\Framework\TestCase;

class GradientTest extends TestCase
{
    public function test_color_stop_valid_construction(): void
    {
        $stop = new ColorStop(0.0, 255, 0, 0);
        $this->assertSame(0.0, $stop->offset);
        $this->assertSame(255, $stop->r);
        $this->assertSame(0, $stop->g);
        $this->assertSame(0, $stop->b);
    }

    public function test_color_stop_offset_below_zero_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ColorStop(-0.1, 0, 0, 0);
    }

    public function test_color_stop_offset_above_one_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ColorStop(1.1, 0, 0, 0);
    }

    public function test_color_stop_r_below_zero_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ColorStop(0.5, -1, 0, 0);
    }

    public function test_color_stop_r_above_255_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ColorStop(0.5, 256, 0, 0);
    }

    public function test_color_stop_g_below_zero_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ColorStop(0.5, 0, -1, 0);
    }

    public function test_color_stop_g_above_255_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ColorStop(0.5, 0, 256, 0);
    }

    public function test_color_stop_b_below_zero_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ColorStop(0.5, 0, 0, -1);
    }

    public function test_color_stop_b_above_255_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ColorStop(0.5, 0, 0, 256);
    }

    public function test_color_stop_boundary_values(): void
    {
        $s1 = new ColorStop(0.0, 0, 0, 0);
        $this->assertSame(0.0, $s1->offset);
        $s2 = new ColorStop(1.0, 255, 255, 255);
        $this->assertSame(1.0, $s2->offset);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Canvas/GradientTest.php`
Expected: FATAL — class `draw\ColorStop` not found

- [ ] **Step 3: Create SpreadMethod enum**

Create `library/draw/SpreadMethod.php`:

```php
<?php

namespace draw;

enum SpreadMethod
{
    case Pad;
    case Reflect;
    case Repeat;
}
```

- [ ] **Step 4: Create ColorStop class**

Create `library/draw/ColorStop.php`:

```php
<?php

namespace draw;

class ColorStop
{
    public function __construct(
        public readonly float $offset,
        public readonly int $r,
        public readonly int $g,
        public readonly int $b,
    ) {
        if ($offset < 0.0 || $offset > 1.0) {
            throw new \InvalidArgumentException("ColorStop offset must be 0.0-1.0, got $offset");
        }
        if ($r < 0 || $r > 255) {
            throw new \InvalidArgumentException("ColorStop r must be 0-255, got $r");
        }
        if ($g < 0 || $g > 255) {
            throw new \InvalidArgumentException("ColorStop g must be 0-255, got $g");
        }
        if ($b < 0 || $b > 255) {
            throw new \InvalidArgumentException("ColorStop b must be 0-255, got $b");
        }
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `composer test -- tests/Canvas/GradientTest.php`
Expected: 11 tests, all PASS

- [ ] **Step 6: Run full test suite**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 7: Commit**

```bash
git add library/draw/SpreadMethod.php library/draw/ColorStop.php tests/Canvas/GradientTest.php
git commit -m "feat(draw): add SpreadMethod enum and ColorStop value object"
```

---

### Task 3: LinearGradient

**Files:**
- Create: `library/draw/LinearGradient.php`
- Modify: `tests/Canvas/GradientTest.php` (add linear gradient tests)
- Modify: `tests/Canvas/PaintTest.php` (add LinearGradient interface tests)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Canvas/GradientTest.php` (add imports at top: `use draw\LinearGradient;`):

```php
    public function test_linear_gradient_fewer_than_two_stops_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
        ]);
    }

    public function test_linear_gradient_empty_stops_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LinearGradient(0.0, 0.0, 10.0, 0.0, []);
    }

    public function test_linear_gradient_horizontal_start_color(): void
    {
        $g = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);
        $rgb = $g->getColorAt(0.0, 5.0);
        $this->assertSame([255, 0, 0], $rgb);
    }

    public function test_linear_gradient_horizontal_end_color(): void
    {
        $g = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);
        $rgb = $g->getColorAt(10.0, 5.0);
        $this->assertSame([0, 0, 255], $rgb);
    }

    public function test_linear_gradient_horizontal_midpoint(): void
    {
        $g = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(0.0, 0, 0, 0),
            new ColorStop(1.0, 200, 200, 200),
        ]);
        $rgb = $g->getColorAt(5.0, 5.0);
        $this->assertSame([100, 100, 100], $rgb);
    }

    public function test_linear_gradient_vertical(): void
    {
        $g = new LinearGradient(0.0, 0.0, 0.0, 10.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 255, 0),
        ]);
        $rgb = $g->getColorAt(5.0, 5.0);
        $this->assertSame([128, 128, 0], $rgb);
    }

    public function test_linear_gradient_degenerate_vector_returns_first_stop(): void
    {
        $g = new LinearGradient(5.0, 5.0, 5.0, 5.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);
        $rgb = $g->getColorAt(0.0, 0.0);
        $this->assertSame([255, 0, 0], $rgb);
    }

    public function test_linear_gradient_stops_are_sorted(): void
    {
        $g = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(1.0, 0, 0, 255),
            new ColorStop(0.0, 255, 0, 0),
        ]);
        $rgb = $g->getColorAt(0.0, 5.0);
        $this->assertSame([255, 0, 0], $rgb);
        $rgb = $g->getColorAt(10.0, 5.0);
        $this->assertSame([0, 0, 255], $rgb);
    }

    public function test_linear_gradient_three_stops(): void
    {
        $g = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(0.5, 0, 255, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);
        $rgb = $g->getColorAt(2.5, 5.0);
        $this->assertSame([128, 128, 0], $rgb);
    }

    public function test_linear_gradient_duplicate_offset_sharp_edge(): void
    {
        $g = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(0.5, 0, 0, 0),
            new ColorStop(0.5, 255, 255, 255),
            new ColorStop(1.0, 0, 0, 255),
        ]);
        $before = $g->getColorAt(4.9, 5.0);
        $this->assertSame([0, 0, 0], $before);
        $after = $g->getColorAt(5.0, 5.0);
        $this->assertSame([255, 255, 255], $after);
    }

    public function test_linear_gradient_pad_before_start(): void
    {
        $g = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ], SpreadMethod::Pad);
        $rgb = $g->getColorAt(-5.0, 5.0);
        $this->assertSame([255, 0, 0], $rgb);
    }

    public function test_linear_gradient_pad_after_end(): void
    {
        $g = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ], SpreadMethod::Pad);
        $rgb = $g->getColorAt(15.0, 5.0);
        $this->assertSame([0, 0, 255], $rgb);
    }

    public function test_linear_gradient_reflect_t_1_3(): void
    {
        $g = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(0.0, 0, 0, 0),
            new ColorStop(1.0, 100, 0, 0),
        ], SpreadMethod::Reflect);
        $rgb = $g->getColorAt(13.0, 5.0);
        $this->assertSame([70, 0, 0], $rgb);
    }

    public function test_linear_gradient_reflect_t_neg_0_2(): void
    {
        $g = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(0.0, 0, 0, 0),
            new ColorStop(1.0, 100, 0, 0),
        ], SpreadMethod::Reflect);
        $rgb = $g->getColorAt(-2.0, 5.0);
        $this->assertSame([20, 0, 0], $rgb);
    }

    public function test_linear_gradient_repeat_t_1_3(): void
    {
        $g = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(0.0, 0, 0, 0),
            new ColorStop(1.0, 100, 0, 0),
        ], SpreadMethod::Repeat);
        $rgb = $g->getColorAt(13.0, 5.0);
        $this->assertSame([30, 0, 0], $rgb);
    }

    public function test_linear_gradient_repeat_t_neg_0_3(): void
    {
        $g = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(0.0, 0, 0, 0),
            new ColorStop(1.0, 100, 0, 0),
        ], SpreadMethod::Repeat);
        $rgb = $g->getColorAt(-3.0, 5.0);
        $this->assertSame([70, 0, 0], $rgb);
    }
```

Append to `tests/Canvas/PaintTest.php` (add imports at top: `use draw\LinearGradient; use draw\ColorStop;`):

```php
    public function test_linear_gradient_is_not_solid(): void
    {
        $g = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);
        $this->assertFalse($g->isSolid());
    }

    public function test_linear_gradient_get_color_at_returns_valid_rgb(): void
    {
        $g = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);
        $rgb = $g->getColorAt(5.0, 5.0);
        $this->assertCount(3, $rgb);
        foreach ($rgb as $c) {
            $this->assertGreaterThanOrEqual(0, $c);
            $this->assertLessThanOrEqual(255, $c);
        }
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- tests/Canvas/GradientTest.php tests/Canvas/PaintTest.php`
Expected: FATAL — class `draw\LinearGradient` not found

- [ ] **Step 3: Implement LinearGradient**

Create `library/draw/LinearGradient.php`:

```php
<?php

namespace draw;

class LinearGradient implements Paint
{
    /** @var ColorStop[] */
    public readonly array $stops;

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
    ) {
        if (count($stops) < 2) {
            throw new \InvalidArgumentException('LinearGradient requires at least 2 stops');
        }
        usort($stops, fn(ColorStop $a, ColorStop $b) => $a->offset <=> $b->offset);
        $this->stops = $stops;
    }

    public function isSolid(): bool
    {
        return false;
    }

    public function getColorAt(float $x, float $y): array
    {
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

    private function applySpread(float $t): float
    {
        return match ($this->spreadMethod) {
            SpreadMethod::Pad => max(0.0, min(1.0, $t)),
            SpreadMethod::Repeat => $t - floor($t),
            SpreadMethod::Reflect => $this->reflect($t),
        };
    }

    private function reflect(float $t): float
    {
        $t = abs($t);
        $f = floor($t);
        $frac = $t - $f;
        return ((int) $f) % 2 === 1 ? 1.0 - $frac : $frac;
    }

    /**
     * @return array{int, int, int}
     */
    private function interpolateStops(float $t): array
    {
        if ($t <= $this->stops[0]->offset) {
            return [$this->stops[0]->r, $this->stops[0]->g, $this->stops[0]->b];
        }
        $last = count($this->stops) - 1;
        if ($t >= $this->stops[$last]->offset) {
            return [$this->stops[$last]->r, $this->stops[$last]->g, $this->stops[$last]->b];
        }
        for ($i = 0; $i < $last; $i++) {
            $a = $this->stops[$i];
            $b = $this->stops[$i + 1];
            if ($t >= $a->offset && $t <= $b->offset) {
                if ($a->offset == $b->offset) {
                    return [$b->r, $b->g, $b->b];
                }
                $localT = ($t - $a->offset) / ($b->offset - $a->offset);
                return [
                    (int) round(max(0, min(255, $a->r + ($b->r - $a->r) * $localT))),
                    (int) round(max(0, min(255, $a->g + ($b->g - $a->g) * $localT))),
                    (int) round(max(0, min(255, $a->b + ($b->b - $a->b) * $localT))),
                ];
            }
        }
        return [$this->stops[$last]->r, $this->stops[$last]->g, $this->stops[$last]->b];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test -- tests/Canvas/GradientTest.php tests/Canvas/PaintTest.php`
Expected: All PASS

- [ ] **Step 5: Run full test suite**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 6: Commit**

```bash
git add library/draw/LinearGradient.php tests/Canvas/GradientTest.php tests/Canvas/PaintTest.php
git commit -m "feat(draw): add LinearGradient with spread methods and stop interpolation"
```

---

### Task 4: RadialGradient

**Files:**
- Create: `library/draw/RadialGradient.php`
- Modify: `tests/Canvas/GradientTest.php` (add radial gradient tests)
- Modify: `tests/Canvas/PaintTest.php` (add RadialGradient interface tests)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Canvas/GradientTest.php` (add import: `use draw\RadialGradient;`):

```php
    public function test_radial_gradient_fewer_than_two_stops_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RadialGradient(5.0, 5.0, 10.0, [
            new ColorStop(0.0, 255, 0, 0),
        ]);
    }

    public function test_radial_gradient_zero_radius_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RadialGradient(5.0, 5.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);
    }

    public function test_radial_gradient_negative_radius_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RadialGradient(5.0, 5.0, -1.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);
    }

    public function test_radial_gradient_focal_point_outside_circle_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RadialGradient(5.0, 5.0, 5.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ], fx: 15.0, fy: 5.0);
    }

    public function test_radial_gradient_center_returns_first_stop(): void
    {
        $g = new RadialGradient(5.0, 5.0, 10.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);
        $rgb = $g->getColorAt(5.0, 5.0);
        $this->assertSame([255, 0, 0], $rgb);
    }

    public function test_radial_gradient_edge_returns_last_stop(): void
    {
        $g = new RadialGradient(5.0, 5.0, 10.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);
        $rgb = $g->getColorAt(15.0, 5.0);
        $this->assertSame([0, 0, 255], $rgb);
    }

    public function test_radial_gradient_midpoint(): void
    {
        $g = new RadialGradient(0.0, 0.0, 10.0, [
            new ColorStop(0.0, 0, 0, 0),
            new ColorStop(1.0, 200, 0, 0),
        ]);
        $rgb = $g->getColorAt(5.0, 0.0);
        $this->assertSame([100, 0, 0], $rgb);
    }

    public function test_radial_gradient_focal_point_offset(): void
    {
        $g = new RadialGradient(5.0, 5.0, 10.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ], fx: 0.0, fy: 5.0);
        $rgb = $g->getColorAt(0.0, 5.0);
        $this->assertSame([255, 0, 0], $rgb);
    }

    public function test_radial_gradient_pad_beyond_radius(): void
    {
        $g = new RadialGradient(5.0, 5.0, 10.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ], spreadMethod: SpreadMethod::Pad);
        $rgb = $g->getColorAt(20.0, 5.0);
        $this->assertSame([0, 0, 255], $rgb);
    }

    public function test_radial_gradient_repeat_beyond_radius(): void
    {
        $g = new RadialGradient(0.0, 0.0, 10.0, [
            new ColorStop(0.0, 0, 0, 0),
            new ColorStop(1.0, 100, 0, 0),
        ], spreadMethod: SpreadMethod::Repeat);
        $rgb = $g->getColorAt(13.0, 0.0);
        $this->assertSame([30, 0, 0], $rgb);
    }

    public function test_radial_gradient_reflect_beyond_radius(): void
    {
        $g = new RadialGradient(0.0, 0.0, 10.0, [
            new ColorStop(0.0, 0, 0, 0),
            new ColorStop(1.0, 100, 0, 0),
        ], spreadMethod: SpreadMethod::Reflect);
        $rgb = $g->getColorAt(13.0, 0.0);
        $this->assertSame([70, 0, 0], $rgb);
    }
```

Append to `tests/Canvas/PaintTest.php` (add import: `use draw\RadialGradient;`):

```php
    public function test_radial_gradient_is_not_solid(): void
    {
        $g = new RadialGradient(5.0, 5.0, 10.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);
        $this->assertFalse($g->isSolid());
    }

    public function test_radial_gradient_get_color_at_returns_valid_rgb(): void
    {
        $g = new RadialGradient(5.0, 5.0, 10.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);
        $rgb = $g->getColorAt(20.0, 20.0);
        $this->assertCount(3, $rgb);
        foreach ($rgb as $c) {
            $this->assertGreaterThanOrEqual(0, $c);
            $this->assertLessThanOrEqual(255, $c);
        }
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- tests/Canvas/GradientTest.php tests/Canvas/PaintTest.php`
Expected: FATAL — class `draw\RadialGradient` not found

- [ ] **Step 3: Implement RadialGradient**

Create `library/draw/RadialGradient.php`:

```php
<?php

namespace draw;

class RadialGradient implements Paint
{
    /** @var ColorStop[] */
    public readonly array $stops;
    public readonly float $fx;
    public readonly float $fy;

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
    }

    public function isSolid(): bool
    {
        return false;
    }

    public function getColorAt(float $x, float $y): array
    {
        $dx = $x - $this->fx;
        $dy = $y - $this->fy;
        $dist = sqrt($dx * $dx + $dy * $dy);
        $t = $dist / $this->r;
        $t = $this->applySpread($t);
        return $this->interpolateStops($t);
    }

    private function applySpread(float $t): float
    {
        return match ($this->spreadMethod) {
            SpreadMethod::Pad => max(0.0, min(1.0, $t)),
            SpreadMethod::Repeat => $t - floor($t),
            SpreadMethod::Reflect => $this->reflect($t),
        };
    }

    private function reflect(float $t): float
    {
        $t = abs($t);
        $f = floor($t);
        $frac = $t - $f;
        return ((int) $f) % 2 === 1 ? 1.0 - $frac : $frac;
    }

    /**
     * @return array{int, int, int}
     */
    private function interpolateStops(float $t): array
    {
        if ($t <= $this->stops[0]->offset) {
            return [$this->stops[0]->r, $this->stops[0]->g, $this->stops[0]->b];
        }
        $last = count($this->stops) - 1;
        if ($t >= $this->stops[$last]->offset) {
            return [$this->stops[$last]->r, $this->stops[$last]->g, $this->stops[$last]->b];
        }
        for ($i = 0; $i < $last; $i++) {
            $a = $this->stops[$i];
            $b = $this->stops[$i + 1];
            if ($t >= $a->offset && $t <= $b->offset) {
                if ($a->offset == $b->offset) {
                    return [$b->r, $b->g, $b->b];
                }
                $localT = ($t - $a->offset) / ($b->offset - $a->offset);
                return [
                    (int) round(max(0, min(255, $a->r + ($b->r - $a->r) * $localT))),
                    (int) round(max(0, min(255, $a->g + ($b->g - $a->g) * $localT))),
                    (int) round(max(0, min(255, $a->b + ($b->b - $a->b) * $localT))),
                ];
            }
        }
        return [$this->stops[$last]->r, $this->stops[$last]->g, $this->stops[$last]->b];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test -- tests/Canvas/GradientTest.php tests/Canvas/PaintTest.php`
Expected: All PASS

- [ ] **Step 5: Run full test suite**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 6: Commit**

```bash
git add library/draw/RadialGradient.php tests/Canvas/GradientTest.php tests/Canvas/PaintTest.php
git commit -m "feat(draw): add RadialGradient with focal point and spread methods"
```

---

### Task 5: Migrate StrokeStyle and Canvas to Paint

**Files:**
- Modify: `library/draw/StrokeStyle.php` (lines 9-10, 18)
- Modify: `library/draw/Canvas.php` (lines 219, 297, 339-346, 394, 419-420, 440, 466, 476, 499, 514, 566-577)
- Modify: `tests/Canvas/StrokeStyleTest.php` (line 60)
- Modify: `tests/Canvas/CanvasTest.php` (no changes needed — `Color` implements `Paint`, positional args work)

This is the core migration. All `Color` type hints become `Paint`, and the rendering pipeline gains per-pixel gradient support.

- [ ] **Step 1: Update StrokeStyle**

In `library/draw/StrokeStyle.php`, change line 10 from:
```php
        public readonly Color $color,
```
to:
```php
        public readonly Paint $paint,
```

- [ ] **Step 2: Update StrokeStyleTest**

In `tests/Canvas/StrokeStyleTest.php`, change line 60 from:
```php
        $this->assertSame(4, $s->color->fg);
```
to:
```php
        $this->assertSame(4, $s->paint->fg);
```

- [ ] **Step 3: Update Canvas::drawPoint**

In `library/draw/Canvas.php`, change line 219 from:
```php
    public function drawPoint(int|float $x, int|float $y, Color $color, string $text = ''): void
```
to:
```php
    public function drawPoint(int|float $x, int|float $y, Paint $paint, string $text = ''): void
```

Change lines 227-228 from:
```php
            $this->data[$y][$x]->fg = $color->fg;
            $this->data[$y][$x]->bg = $color->bg;
```
to:
```php
            if ($paint->isSolid() && $paint instanceof Color) {
                $this->data[$y][$x]->fg = $paint->fg;
                $this->data[$y][$x]->bg = $paint->bg;
            } else {
                [$r, $g, $b] = $paint->getColorAt((float) $x, (float) $y);
                $this->data[$y][$x]->fg = IrcPalette::nearestColor($r, $g, $b);
                $this->data[$y][$x]->bg = null;
            }
```

- [ ] **Step 4: Update Canvas::drawLineInternal**

In `library/draw/Canvas.php`, change line 297 from:
```php
    private function drawLineInternal(int $startX, int $startY, int $endX, int $endY, Color $color, string $text = ''): void
```
to:
```php
    private function drawLineInternal(int $startX, int $startY, int $endX, int $endY, Paint $paint, string $text = ''): void
```

Change lines 309-310 from:
```php
                $this->data[$y][$x]->fg = $color->fg;
                $this->data[$y][$x]->bg = $color->bg;
```
to:
```php
                if ($paint->isSolid() && $paint instanceof Color) {
                    $this->data[$y][$x]->fg = $paint->fg;
                    $this->data[$y][$x]->bg = $paint->bg;
                } else {
                    [$r, $g, $b] = $paint->getColorAt((float) $x, (float) $y);
                    $this->data[$y][$x]->fg = IrcPalette::nearestColor($r, $g, $b);
                    $this->data[$y][$x]->bg = null;
                }
```

- [ ] **Step 5: Update Canvas::drawPath signature**

In `library/draw/Canvas.php`, change lines 339-347 from:
```php
    public function drawPath(
        Path $path,
        ?Color $fillColor,
        ?StrokeStyle $stroke,
        string $text = '',
        FillRule $fillRule = FillRule::NonZero,
        float $fillOpacity = 1.0,
        float $opacity = 1.0,
    ): void {
```
to:
```php
    public function drawPath(
        Path $path,
        ?Paint $fill,
        ?StrokeStyle $stroke,
        string $text = '',
        FillRule $fillRule = FillRule::NonZero,
        float $fillOpacity = 1.0,
        float $opacity = 1.0,
    ): void {
```

Change lines 352-353 from:
```php
        if ($fillColor === null && $stroke === null) {
```
to:
```php
        if ($fill === null && $stroke === null) {
```

- [ ] **Step 6: Update Canvas::renderFill**

In `library/draw/Canvas.php`, change the `renderFill` method signature (line 394) from:
```php
    private function renderFill(Canvas $target, array $snappedSubpaths, ?Color $fillColor, string $text, FillRule $fillRule, float $fillOpacity): void
```
to:
```php
    private function renderFill(Canvas $target, array $snappedSubpaths, ?Paint $fill, string $text, FillRule $fillRule, float $fillOpacity): void
```

Change lines 396-398 from:
```php
        if ($fillColor === null) {
            return;
        }
```
to:
```php
        if ($fill === null) {
            return;
        }
```

Change lines 407-411 from:
```php
            if ($fillOpacity < 1.0) {
                $temp = Canvas::createBlank($target->w, $target->h, $target->halfblocks);
                $temp->fillPolygonScanlineMulti($polygonArrays, $fillColor, $text, $fillRule);
                Compositor::blend($target, $temp, $fillOpacity);
            } else {
                $target->fillPolygonScanlineMulti($polygonArrays, $fillColor, $text, $fillRule);
```
to:
```php
            if ($fillOpacity < 1.0) {
                $temp = Canvas::createBlank($target->w, $target->h, $target->halfblocks);
                $temp->fillPolygonScanlineMulti($polygonArrays, $fill, $text, $fillRule);
                Compositor::blend($target, $temp, $fillOpacity);
            } else {
                $target->fillPolygonScanlineMulti($polygonArrays, $fill, $text, $fillRule);
```

- [ ] **Step 7: Update Canvas::renderStroke calls to use `$stroke->paint`**

In `library/draw/Canvas.php`, `strokeSubpath` calls `$stroke->color` in 3 places. Change line 466 from:
```php
                        $stroke->color,
```
to:
```php
                        $stroke->paint,
```

Change line 476 from:
```php
                        $stroke->color,
```
to:
```php
                        $stroke->paint,
```

Change line 499 from:
```php
                $this->fillPolygonScanlineMulti([$polygon], $stroke->color, $text, FillRule::NonZero);
```
to:
```php
                $this->fillPolygonScanlineMulti([$polygon], $stroke->paint, $text, FillRule::NonZero);
```

- [ ] **Step 8: Update Canvas::fillPolygonScanlineMulti signature and body**

In `library/draw/Canvas.php`, change line 514 from:
```php
    private function fillPolygonScanlineMulti(array $subpaths, Color $color, string $text, FillRule $fillRule): void
```
to:
```php
    private function fillPolygonScanlineMulti(array $subpaths, Paint $paint, string $text, FillRule $fillRule): void
```

Change the `$fillSpan` closure (lines 566-578) from:
```php
            $fillSpan = function (float $x0, float $x1) use ($Y, $color, $text): void {
                $xL = (int) ceil($x0);
                $xR = (int) floor($x1);
                for ($xx = $xL; $xx <= $xR; $xx++) {
                    if (isset($this->data[$Y][$xx])) {
                        $this->data[$Y][$xx]->fg = $color->fg;
                        $this->data[$Y][$xx]->bg = $color->bg;
                        if ($text != '') {
                            $this->data[$Y][$xx]->text = $text;
                        }
                    }
                }
            };
```
to:
```php
            $fillSpan = function (float $x0, float $x1) use ($Y, $paint, $text): void {
                $xL = (int) ceil($x0);
                $xR = (int) floor($x1);
                for ($xx = $xL; $xx <= $xR; $xx++) {
                    if (isset($this->data[$Y][$xx])) {
                        if ($paint->isSolid() && $paint instanceof Color) {
                            $this->data[$Y][$xx]->fg = $paint->fg;
                            $this->data[$Y][$xx]->bg = $paint->bg;
                        } else {
                            [$r, $g, $b] = $paint->getColorAt((float) $xx, (float) $Y);
                            $this->data[$Y][$xx]->fg = IrcPalette::nearestColor($r, $g, $b);
                            $this->data[$Y][$xx]->bg = null;
                        }
                        if ($text != '') {
                            $this->data[$Y][$xx]->text = $text;
                        }
                    }
                }
            };
```

- [ ] **Step 9: Update renderFill/renderStroke call sites in drawPath**

In `drawPath()`, update the renderFill calls to use `$fill` instead of `$fillColor`. The lines inside `drawPath` that read:

Line 382:
```php
            $this->renderFill($temp, $snappedSubpaths, $fillColor, $text, $fillRule, $fillOpacity);
```
→ change `$fillColor` to `$fill`:
```php
            $this->renderFill($temp, $snappedSubpaths, $fill, $text, $fillRule, $fillOpacity);
```

Line 386:
```php
            $this->renderFill($this, $snappedSubpaths, $fillColor, $text, $fillRule, $fillOpacity);
```
→ change `$fillColor` to `$fill`:
```php
            $this->renderFill($this, $snappedSubpaths, $fill, $text, $fillRule, $fillOpacity);
```

- [ ] **Step 10: Run full test suite**

Run: `composer test`
Expected: All existing tests pass (Color implements Paint, positional args unchanged)

- [ ] **Step 11: Commit**

```bash
git add library/draw/StrokeStyle.php library/draw/Canvas.php tests/Canvas/StrokeStyleTest.php
git commit -m "refactor(draw): migrate StrokeStyle and Canvas from Color to Paint interface"
```

---

### Task 6: Integration Tests

**Files:**
- Modify: `tests/Canvas/CanvasTest.php` (add gradient integration tests)

- [ ] **Step 1: Write integration tests**

Append to `tests/Canvas/CanvasTest.php` (add imports: `use draw\LinearGradient; use draw\RadialGradient; use draw\ColorStop; use draw\SpreadMethod;`):

```php
    public function test_draw_path_with_linear_gradient_fill(): void
    {
        $canvas = Canvas::createBlank(20, 5);
        $gradient = new LinearGradient(0.0, 0.0, 19.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);

        $path = Path::rect(0.0, 0.0, 20.0, 5.0);
        $canvas->drawPath($path, $gradient, null);

        $this->assertNotNull($canvas->data[2][0]->fg);
        $this->assertNotNull($canvas->data[2][19]->fg);
        $this->assertNotSame(
            $canvas->data[2][0]->fg,
            $canvas->data[2][19]->fg,
            'Left and right pixels should have different colors in a horizontal gradient'
        );
    }

    public function test_draw_path_with_radial_gradient_fill(): void
    {
        $canvas = Canvas::createBlank(21, 21);
        $gradient = new RadialGradient(10.0, 10.0, 10.0, [
            new ColorStop(0.0, 255, 255, 255),
            new ColorStop(1.0, 0, 0, 0),
        ]);

        $path = Path::rect(0.0, 0.0, 21.0, 21.0);
        $canvas->drawPath($path, $gradient, null);

        $this->assertNotNull($canvas->data[10][10]->fg);
        $this->assertNotNull($canvas->data[10][0]->fg);
        $this->assertNotSame(
            $canvas->data[10][10]->fg,
            $canvas->data[10][0]->fg,
            'Center and edge pixels should have different colors in a radial gradient'
        );
    }

    public function test_draw_path_with_gradient_stroke_width_1(): void
    {
        $canvas = Canvas::createBlank(20, 5);
        $gradient = new LinearGradient(0.0, 0.0, 19.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);

        $path = Path::line(0.0, 2.0, 19.0, 2.0);
        $canvas->drawPath($path, null, new StrokeStyle($gradient));

        $this->assertNotNull($canvas->data[2][0]->fg);
        $this->assertNotNull($canvas->data[2][19]->fg);
        $this->assertNotSame(
            $canvas->data[2][0]->fg,
            $canvas->data[2][19]->fg,
            'Start and end pixels should have different colors in a gradient stroke'
        );
    }

    public function test_draw_path_with_gradient_stroke_width_gt_1(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $gradient = new LinearGradient(0.0, 0.0, 19.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);

        $path = Path::line(0.0, 5.0, 19.0, 5.0);
        $canvas->drawPath($path, null, new StrokeStyle($gradient, width: 3.0));

        $this->assertNotNull($canvas->data[4][0]->fg);
        $this->assertNotNull($canvas->data[4][19]->fg);
        $this->assertNotSame(
            $canvas->data[4][0]->fg,
            $canvas->data[4][19]->fg,
            'Start and end of thick stroke should have different colors in a gradient'
        );
    }

    public function test_draw_path_gradient_and_opacity(): void
    {
        $canvas = Canvas::createBlank(20, 5);
        $gradient = new LinearGradient(0.0, 0.0, 19.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);

        $path = Path::rect(0.0, 0.0, 20.0, 5.0);
        $canvas->drawPath($path, $gradient, null, '', FillRule::NonZero, 0.5);

        $this->assertNotNull($canvas->data[2][10]->fg);
    }

    public function test_draw_point_with_gradient(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $gradient = new LinearGradient(0.0, 0.0, 9.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);

        $canvas->drawPoint(5, 5, $gradient);

        $this->assertNotNull($canvas->data[5][5]->fg);
        $this->assertNull($canvas->data[5][5]->bg);
    }
```

- [ ] **Step 2: Run full test suite**

Run: `composer test`
Expected: All existing tests + 6 new integration tests pass

- [ ] **Step 3: Commit**

```bash
git add tests/Canvas/CanvasTest.php
git commit -m "test(draw): add gradient integration tests for fill, stroke, and drawPoint"
```

---

### Task 7: Cleanup and Verification

**Files:**
- Modify: `library/draw/Color.php` (remove old gradient stubs)

- [ ] **Step 1: Remove old gradient stubs from Color**

In `library/draw/Color.php`, remove the empty methods `setGradiant()` and `advanceGradiant()` (lines 68-80):

```php
    //thinking this can be like an array of colors with a step size?
    /**
     * @param array<int|Color> $colors
     */
    public function setGradiant(array $colors): void
    {

    }

    public function advanceGradiant(): void
    {

    }
```

- [ ] **Step 2: Run full test suite**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 3: Run static analysis**

Run: `composer phpstan`
Expected: No new errors (pre-existing errors in other parts of codebase are acceptable)

- [ ] **Step 4: Commit**

```bash
git add library/draw/Color.php
git commit -m "chore(draw): remove old gradient stubs from Color class"
```
