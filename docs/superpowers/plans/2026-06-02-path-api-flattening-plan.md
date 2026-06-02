# Path API and Flattening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Path class with SVG-style path segments (MoveTo, LineTo, CubicBezier, QuadraticBezier, EllipticalArc, ClosePath), recursive De Casteljau Bézier flattening, arc-to-cubic conversion, and multi-subpath Canvas integration.

**Architecture:** Segment type system with interface + 6 concrete classes. Path class tracks current point and subpath state, resolves smooth-curve shorthand at insertion time. Canvas::drawPath() flattens the path and renders via a multi-subpath scanline fill + Bresenham outline.

**Tech Stack:** PHP 8.1+, PHPUnit 10, PSR-4 autoloading (`draw\` → `library/draw/`)

**Spec:** `docs/superpowers/specs/2026-06-02-path-api-flattening-design.md`

---

### Task 1: PathSegment interface + trivial segments (MoveTo, LineTo, ClosePath)

**Files:**
- Create: `library/draw/PathSegment.php`
- Create: `library/draw/MoveTo.php`
- Create: `library/draw/LineTo.php`
- Create: `library/draw/ClosePath.php`
- Create: `tests/Canvas/PathSegmentTest.php`

- [ ] **Step 1: Create PathSegment interface**

```php
<?php
namespace draw;

interface PathSegment
{
    /**
     * Flatten this segment into polygon vertices.
     *
     * @param float $startX Current point X when this segment begins.
     * @param float $startY Current point Y when this segment begins.
     * @param float $tolerance Maximum deviation from true curve, in canvas units.
     * @return array<int, array{float, float}> Vertices produced by this segment
     *         (excluding the start point, which the caller already has).
     */
    public function flatten(float $startX, float $startY, float $tolerance): array;

    /**
     * Returns the endpoint of this segment (where the cursor lands).
     *
     * @return array{float, float}
     */
    public function endPoint(): array;
}
```

- [ ] **Step 2: Create MoveTo segment**

```php
<?php
namespace draw;

class MoveTo implements PathSegment
{
    public function __construct(
        private float $x,
        private float $y
    ) {
    }

    public function flatten(float $startX, float $startY, float $tolerance): array
    {
        return [];
    }

    /** @return array{float, float} */
    public function endPoint(): array
    {
        return [$this->x, $this->y];
    }
}
```

- [ ] **Step 3: Create LineTo segment**

```php
<?php
namespace draw;

class LineTo implements PathSegment
{
    public function __construct(
        private float $x,
        private float $y
    ) {
    }

    public function flatten(float $startX, float $startY, float $tolerance): array
    {
        return [[$this->x, $this->y]];
    }

    /** @return array{float, float} */
    public function endPoint(): array
    {
        return [$this->x, $this->y];
    }
}
```

- [ ] **Step 4: Create ClosePath segment**

ClosePath stores the subpath start point so `endPoint()` can return it. Path
creates ClosePath instances with the current subpath start coordinates.

```php
<?php
namespace draw;

class ClosePath implements PathSegment
{
    public function __construct(
        private float $returnX,
        private float $returnY
    ) {
    }

    public function flatten(float $startX, float $startY, float $tolerance): array
    {
        return [];
    }

    /** @return array{float, float} */
    public function endPoint(): array
    {
        return [$this->returnX, $this->returnY];
    }
}
```

- [ ] **Step 5: Write tests for trivial segments**

```php
<?php
namespace Tests\Canvas;

use draw\ClosePath;
use draw\LineTo;
use draw\MoveTo;
use PHPUnit\Framework\TestCase;

class PathSegmentTest extends TestCase
{
    public function test_move_to_flatten_returns_empty(): void
    {
        $seg = new MoveTo(10.0, 20.0);
        $this->assertSame([], $seg->flatten(0.0, 0.0, 0.5));
    }

    public function test_move_to_end_point(): void
    {
        $seg = new MoveTo(10.0, 20.0);
        $this->assertSame([10.0, 20.0], $seg->endPoint());
    }

    public function test_line_to_flatten_returns_endpoint(): void
    {
        $seg = new LineTo(15.0, 25.0);
        $result = $seg->flatten(5.0, 5.0, 0.5);
        $this->assertCount(1, $result);
        $this->assertSame([15.0, 25.0], $result[0]);
    }

    public function test_line_to_end_point(): void
    {
        $seg = new LineTo(15.0, 25.0);
        $this->assertSame([15.0, 25.0], $seg->endPoint());
    }

    public function test_close_path_flatten_returns_empty(): void
    {
        $seg = new ClosePath(10.0, 20.0);
        $this->assertSame([], $seg->flatten(30.0, 40.0, 0.5));
    }

    public function test_close_path_end_point_returns_subpath_start(): void
    {
        $seg = new ClosePath(10.0, 20.0);
        $this->assertSame([10.0, 20.0], $seg->endPoint());
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `composer test`
Expected: 6 tests PASS

- [ ] **Step 7: Commit**

```bash
git add library/draw/PathSegment.php library/draw/MoveTo.php library/draw/LineTo.php library/draw/ClosePath.php tests/Canvas/PathSegmentTest.php
git commit -m "Add PathSegment interface with MoveTo, LineTo, ClosePath segments"
```

---

### Task 2: QuadraticBezier segment

**Files:**
- Create: `library/draw/QuadraticBezier.php`
- Modify: `tests/Canvas/PathSegmentTest.php`

- [ ] **Step 1: Write failing tests for QuadraticBezier**

Add to `tests/Canvas/PathSegmentTest.php`:

```php
use draw\QuadraticBezier;

// Add inside the class:

    public function test_quadratic_bezier_end_point(): void
    {
        $seg = new QuadraticBezier(5.0, 10.0, 20.0, 30.0);
        $this->assertSame([20.0, 30.0], $seg->endPoint());
    }

    public function test_quadratic_bezier_straight_line_flattens_to_one_vertex(): void
    {
        // Control point on the line from (0,0) to (10,0) → perfectly flat
        $seg = new QuadraticBezier(5.0, 0.0, 10.0, 0.0);
        $result = $seg->flatten(0.0, 0.0, 0.5);
        $this->assertCount(1, $result);
        $this->assertSame([10.0, 0.0], $result[0]);
    }

    public function test_quadratic_bezier_curved_produces_multiple_vertices(): void
    {
        // Control point above the line → curved
        $seg = new QuadraticBezier(5.0, 10.0, 10.0, 0.0);
        $result = $seg->flatten(0.0, 0.0, 0.5);
        $this->assertGreaterThan(1, count($result), 'Curved quadratic should produce multiple vertices');
        // Last vertex must be the endpoint
        $last = $result[count($result) - 1];
        $this->assertEqualsWithDelta(10.0, $last[0], 0.001);
        $this->assertEqualsWithDelta(0.0, $last[1], 0.001);
    }

    public function test_quadratic_bezier_vertices_within_tolerance(): void
    {
        // Quarter-circle-like curve
        $seg = new QuadraticBezier(0.0, 10.0, 10.0, 0.0);
        $result = $seg->flatten(0.0, 0.0, 0.5);
        // Every vertex should be within tolerance of the true curve.
        // We check by verifying the curve passes through each vertex at some t.
        // For a quadratic: B(t) = (1-t)^2*P0 + 2*(1-t)*t*P1 + t^2*P2
        $p0 = [0.0, 0.0];
        $p1 = [0.0, 10.0];
        $p2 = [10.0, 0.0];
        foreach ($result as $vertex) {
            // Find t that gives this x coordinate: x = 2*(1-t)*t*0 + t^2*10
            // => t = sqrt(x/10)
            $t = sqrt($vertex[0] / 10.0);
            $t = max(0.0, min(1.0, $t));
            $expectedY = 2 * (1 - $t) * $t * 10.0;
            $this->assertEqualsWithDelta(
                $expectedY,
                $vertex[1],
                0.5,
                "Vertex ({$vertex[0]}, {$vertex[1]}) deviates from curve at t=$t"
            );
        }
    }

    public function test_quadratic_bezier_higher_tolerance_produces_fewer_vertices(): void
    {
        $seg = new QuadraticBezier(0.0, 10.0, 10.0, 0.0);
        $fine = $seg->flatten(0.0, 0.0, 0.1);
        $coarse = $seg->flatten(0.0, 0.0, 2.0);
        $this->assertGreaterThan(
            count($coarse),
            count($fine),
            'Finer tolerance should produce more vertices'
        );
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test`
Expected: FAIL — `draw\QuadraticBezier` not found

- [ ] **Step 3: Implement QuadraticBezier**

```php
<?php
namespace draw;

class QuadraticBezier implements PathSegment
{
    public function __construct(
        private float $cpx,
        private float $cpy,
        private float $x,
        private float $y
    ) {
    }

    public function flatten(float $startX, float $startY, float $tolerance): array
    {
        $result = [];
        $this->flattenRecursive(
            $startX, $startY,
            $this->cpx, $this->cpy,
            $this->x, $this->y,
            $tolerance, 0, $result
        );
        return $result;
    }

    /** @return array{float, float} */
    public function endPoint(): array
    {
        return [$this->x, $this->y];
    }

    /**
     * @param array<int, array{float, float}> $result
     */
    private function flattenRecursive(
        float $p0x, float $p0y,
        float $p1x, float $p1y,
        float $p2x, float $p2y,
        float $tolerance,
        int $depth,
        array &$result
    ): void {
        if ($depth > 20) {
            $result[] = [$p2x, $p2y];
            return;
        }

        // Flatness: perpendicular distance from P1 to line P0→P2
        $dx = $p2x - $p0x;
        $dy = $p2y - $p0y;
        $len2 = $dx * $dx + $dy * $dy;

        if ($len2 > 0.0) {
            $d = abs(($p1x - $p0x) * $dy - ($p1y - $p0y) * $dx) / sqrt($len2);
        } else {
            $d = sqrt(($p1x - $p0x) ** 2 + ($p1y - $p0y) ** 2);
        }

        if ($d <= $tolerance) {
            $result[] = [$p2x, $p2y];
            return;
        }

        // De Casteljau subdivision at t=0.5
        $q0x = ($p0x + $p1x) / 2;
        $q0y = ($p0y + $p1y) / 2;
        $q1x = ($p1x + $p2x) / 2;
        $q1y = ($p1y + $p2y) / 2;
        $sx = ($q0x + $q1x) / 2;
        $sy = ($q0y + $q1y) / 2;

        $this->flattenRecursive($p0x, $p0y, $q0x, $q0y, $sx, $sy, $tolerance, $depth + 1, $result);
        $this->flattenRecursive($sx, $sy, $q1x, $q1y, $p2x, $p2y, $tolerance, $depth + 1, $result);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test`
Expected: 11 tests PASS

- [ ] **Step 5: Commit**

```bash
git add library/draw/QuadraticBezier.php tests/Canvas/PathSegmentTest.php
git commit -m "Add QuadraticBezier segment with De Casteljau flattening"
```

---

### Task 3: CubicBezier segment

**Files:**
- Create: `library/draw/CubicBezier.php`
- Modify: `tests/Canvas/PathSegmentTest.php`

- [ ] **Step 1: Write failing tests for CubicBezier**

Add to `tests/Canvas/PathSegmentTest.php`:

```php
use draw\CubicBezier;

// Add inside the class:

    public function test_cubic_bezier_end_point(): void
    {
        $seg = new CubicBezier(3.0, 4.0, 7.0, 8.0, 10.0, 15.0);
        $this->assertSame([10.0, 15.0], $seg->endPoint());
    }

    public function test_cubic_bezier_straight_line_flattens_to_one_vertex(): void
    {
        // Both control points on the line from (0,0) to (10,0)
        $seg = new CubicBezier(3.0, 0.0, 7.0, 0.0, 10.0, 0.0);
        $result = $seg->flatten(0.0, 0.0, 0.5);
        $this->assertCount(1, $result);
        $this->assertSame([10.0, 0.0], $result[0]);
    }

    public function test_cubic_bezier_curved_produces_multiple_vertices(): void
    {
        // Control points pull the curve upward
        $seg = new CubicBezier(2.0, 10.0, 8.0, 10.0, 10.0, 0.0);
        $result = $seg->flatten(0.0, 0.0, 0.5);
        $this->assertGreaterThan(1, count($result));
        $last = $result[count($result) - 1];
        $this->assertEqualsWithDelta(10.0, $last[0], 0.001);
        $this->assertEqualsWithDelta(0.0, $last[1], 0.001);
    }

    public function test_cubic_bezier_vertices_within_tolerance(): void
    {
        // Symmetric S-curve
        $seg = new CubicBezier(0.0, 10.0, 10.0, 0.0, 10.0, 10.0);
        $result = $seg->flatten(0.0, 0.0, 0.5);
        // Verify each vertex is near the true cubic curve.
        // B(t) = (1-t)^3*P0 + 3*(1-t)^2*t*P1 + 3*(1-t)*t^2*P2 + t^3*P3
        $p0 = [0.0, 0.0];
        $p1 = [0.0, 10.0];
        $p2 = [10.0, 0.0];
        $p3 = [10.0, 10.0];
        foreach ($result as $vertex) {
            // Solve for t numerically: x = 3*(1-t)^2*t*0 + 3*(1-t)*t^2*10 + t^3*10
            // x = 30*t^2*(1-t) + 10*t^3 = 30*t^2 - 30*t^3 + 10*t^3 = 30*t^2 - 20*t^3
            // 20*t^3 - 30*t^2 + x = 0  →  solve with binary search
            $tx = $vertex[0];
            $lo = 0.0;
            $hi = 1.0;
            for ($i = 0; $i < 50; $i++) {
                $mid = ($lo + $hi) / 2;
                $xAtMid = 30 * $mid * $mid - 20 * $mid * $mid * $mid;
                if ($xAtMid < $tx) {
                    $lo = $mid;
                } else {
                    $hi = $mid;
                }
            }
            $t = ($lo + $hi) / 2;
            $expectedY = 3 * (1 - $t) * (1 - $t) * $t * 10.0 + 3 * (1 - $t) * $t * $t * 0.0 + $t * $t * $t * 10.0;
            $this->assertEqualsWithDelta(
                $expectedY,
                $vertex[1],
                0.5,
                "Vertex ({$vertex[0]}, {$vertex[1]}) deviates from curve at t=$t"
            );
        }
    }

    public function test_cubic_bezier_higher_tolerance_produces_fewer_vertices(): void
    {
        $seg = new CubicBezier(0.0, 10.0, 10.0, 0.0, 10.0, 10.0);
        $fine = $seg->flatten(0.0, 0.0, 0.1);
        $coarse = $seg->flatten(0.0, 0.0, 2.0);
        $this->assertGreaterThan(count($coarse), count($fine));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test`
Expected: FAIL — `draw\CubicBezier` not found

- [ ] **Step 3: Implement CubicBezier**

```php
<?php
namespace draw;

class CubicBezier implements PathSegment
{
    public function __construct(
        private float $c1x,
        private float $c1y,
        private float $c2x,
        private float $c2y,
        private float $x,
        private float $y
    ) {
    }

    public function flatten(float $startX, float $startY, float $tolerance): array
    {
        $result = [];
        $this->flattenRecursive(
            $startX, $startY,
            $this->c1x, $this->c1y,
            $this->c2x, $this->c2y,
            $this->x, $this->y,
            $tolerance, 0, $result
        );
        return $result;
    }

    /** @return array{float, float} */
    public function endPoint(): array
    {
        return [$this->x, $this->y];
    }

    /**
     * @param array<int, array{float, float}> $result
     */
    private function flattenRecursive(
        float $p0x, float $p0y,
        float $p1x, float $p1y,
        float $p2x, float $p2y,
        float $p3x, float $p3y,
        float $tolerance,
        int $depth,
        array &$result
    ): void {
        if ($depth > 20) {
            $result[] = [$p3x, $p3y];
            return;
        }

        // Flatness: perpendicular distances from P1 and P2 to line P0→P3
        $dx = $p3x - $p0x;
        $dy = $p3y - $p0y;
        $len2 = $dx * $dx + $dy * $dy;

        if ($len2 > 0.0) {
            $d1 = abs(($p1x - $p0x) * $dy - ($p1y - $p0y) * $dx) / sqrt($len2);
            $d2 = abs(($p2x - $p0x) * $dy - ($p2y - $p0y) * $dx) / sqrt($len2);
        } else {
            $d1 = sqrt(($p1x - $p0x) ** 2 + ($p1y - $p0y) ** 2);
            $d2 = sqrt(($p2x - $p0x) ** 2 + ($p2y - $p0y) ** 2);
        }

        if ($d1 <= $tolerance && $d2 <= $tolerance) {
            $result[] = [$p3x, $p3y];
            return;
        }

        // De Casteljau subdivision at t=0.5
        $q0x = ($p0x + $p1x) / 2;
        $q0y = ($p0y + $p1y) / 2;
        $q1x = ($p1x + $p2x) / 2;
        $q1y = ($p1y + $p2y) / 2;
        $q2x = ($p2x + $p3x) / 2;
        $q2y = ($p2y + $p3y) / 2;
        $r0x = ($q0x + $q1x) / 2;
        $r0y = ($q0y + $q1y) / 2;
        $r1x = ($q1x + $q2x) / 2;
        $r1y = ($q1y + $q2y) / 2;
        $sx = ($r0x + $r1x) / 2;
        $sy = ($r0y + $r1y) / 2;

        $this->flattenRecursive($p0x, $p0y, $q0x, $q0y, $r0x, $r0y, $sx, $sy, $tolerance, $depth + 1, $result);
        $this->flattenRecursive($sx, $sy, $r1x, $r1y, $q2x, $q2y, $p3x, $p3y, $tolerance, $depth + 1, $result);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test`
Expected: 16 tests PASS

- [ ] **Step 5: Commit**

```bash
git add library/draw/CubicBezier.php tests/Canvas/PathSegmentTest.php
git commit -m "Add CubicBezier segment with De Casteljau flattening"
```

---

### Task 4: EllipticalArc segment

**Files:**
- Create: `library/draw/EllipticalArc.php`
- Modify: `tests/Canvas/PathSegmentTest.php`

- [ ] **Step 1: Write failing tests for EllipticalArc**

Add to `tests/Canvas/PathSegmentTest.php`:

```php
use draw\EllipticalArc;

// Add inside the class:

    public function test_arc_end_point(): void
    {
        $seg = new EllipticalArc(10.0, 10.0, 0.0, false, true, 20.0, 0.0);
        $this->assertSame([20.0, 0.0], $seg->endPoint());
    }

    public function test_arc_start_equals_end_returns_empty(): void
    {
        $seg = new EllipticalArc(10.0, 10.0, 0.0, false, true, 0.0, 0.0);
        $this->assertSame([], $seg->flatten(0.0, 0.0, 0.5));
    }

    public function test_arc_zero_radii_flattens_to_line(): void
    {
        $seg = new EllipticalArc(0.0, 0.0, 0.0, false, true, 20.0, 0.0);
        $result = $seg->flatten(0.0, 0.0, 0.5);
        $this->assertCount(1, $result);
        $this->assertSame([20.0, 0.0], $result[0]);
    }

    public function test_arc_semicircle_produces_multiple_vertices(): void
    {
        // Semicircle from (0,0) to (20,0), rx=10, large-arc=false, sweep=true
        $seg = new EllipticalArc(10.0, 10.0, 0.0, false, true, 20.0, 0.0);
        $result = $seg->flatten(0.0, 0.0, 0.5);
        $this->assertGreaterThan(2, count($result), 'Semicircle should produce several vertices');
        // First vertex should be near the start, last should be the endpoint
        $last = $result[count($result) - 1];
        $this->assertEqualsWithDelta(20.0, $last[0], 0.01);
        $this->assertEqualsWithDelta(0.0, $last[1], 0.01);
    }

    public function test_arc_vertices_on_circle_within_tolerance(): void
    {
        // Quarter arc of a circle radius 10 centered at (10, 0)
        // From (0,0) to (10,10), sweep clockwise
        $seg = new EllipticalArc(10.0, 10.0, 0.0, false, true, 10.0, 10.0);
        $result = $seg->flatten(0.0, 0.0, 0.5);
        // Every vertex should be approximately on the circle (x-10)^2 + y^2 = 100
        foreach ($result as $vertex) {
            $dist = sqrt(($vertex[0] - 10.0) ** 2 + $vertex[1] ** 2);
            $this->assertEqualsWithDelta(
                10.0,
                $dist,
                0.5,
                "Vertex ({$vertex[0]}, {$vertex[1]}) is not on the circle"
            );
        }
    }

    public function test_arc_large_arc_flag(): void
    {
        // Small arc vs large arc between the same endpoints should produce
        // different vertex counts (large arc is longer)
        $small = new EllipticalArc(10.0, 10.0, 0.0, false, true, 20.0, 0.0);
        $large = new EllipticalArc(10.0, 10.0, 0.0, true, true, 20.0, 0.0);
        $smallResult = $small->flatten(0.0, 0.0, 0.5);
        $largeResult = $large->flatten(0.0, 0.0, 0.5);
        $this->assertGreaterThan(
            count($smallResult),
            count($largeResult),
            'Large arc should produce more vertices than small arc'
        );
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test`
Expected: FAIL — `draw\EllipticalArc` not found

- [ ] **Step 3: Implement EllipticalArc**

```php
<?php
namespace draw;

class EllipticalArc implements PathSegment
{
    public function __construct(
        private float $rx,
        private float $ry,
        private float $xAxisRot,
        private bool $largeArc,
        private bool $sweep,
        private float $x,
        private float $y
    ) {
    }

    public function flatten(float $startX, float $startY, float $tolerance): array
    {
        // Degenerate: start == end (no-op per SVG spec)
        if ($startX == $this->x && $startY == $this->y) {
            return [];
        }

        // Degenerate: zero radii → straight line
        if ($this->rx == 0.0 || $this->ry == 0.0) {
            return [[$this->x, $this->y]];
        }

        $rx = abs($this->rx);
        $ry = abs($this->ry);
        $phi = deg2rad($this->xAxisRot);
        $cosPhi = cos($phi);
        $sinPhi = sin($phi);

        // Step 1: Transform endpoint difference to ellipse coordinate system
        $dx = ($startX - $this->x) / 2.0;
        $dy = ($startY - $this->y) / 2.0;
        $x1p = $cosPhi * $dx + $sinPhi * $dy;
        $y1p = -$sinPhi * $dx + $cosPhi * $dy;

        // Step 2: Ensure radii are large enough
        $lambda = ($x1p * $x1p) / ($rx * $rx) + ($y1p * $y1p) / ($ry * $ry);
        if ($lambda > 1.0) {
            $factor = sqrt($lambda);
            $rx *= $factor;
            $ry *= $factor;
        }

        // Step 3: Compute center in ellipse coordinate system
        $sign = ($this->largeArc === $this->sweep) ? -1.0 : 1.0;
        $num = $rx * $rx * $ry * $ry - $rx * $rx * $y1p * $y1p - $ry * $ry * $x1p * $x1p;
        $den = $rx * $rx * $y1p * $y1p + $ry * $ry * $x1p * $x1p;
        $factor = $sign * sqrt(max(0.0, $num / $den));
        $cxp = $factor * ($rx * $y1p) / $ry;
        $cyp = $factor * -($ry * $x1p) / $rx;

        // Transform center back to original coordinate system
        $cx = $cosPhi * $cxp - $sinPhi * $cyp + ($startX + $this->x) / 2.0;
        $cy = $sinPhi * $cxp + $cosPhi * $cyp + ($startY + $this->y) / 2.0;

        // Step 4: Compute start and sweep angles
        $theta1 = atan2(($y1p - $cyp) / $ry, ($x1p - $cxp) / $rx);
        $dx2 = -$x1p - $cxp;
        $dy2 = -$y1p - $cyp;
        $theta2 = atan2($dy2 / $ry, $dx2 / $rx);

        $dtheta = $theta2 - $theta1;
        if (!$this->sweep && $dtheta > 0.0) {
            $dtheta -= 2.0 * M_PI;
        } elseif ($this->sweep && $dtheta < 0.0) {
            $dtheta += 2.0 * M_PI;
        }

        // Step 5: Split into ≤90° pieces, convert each to cubic Bézier
        $numPieces = max(1, (int) ceil(abs($dtheta) / (M_PI / 2.0)));
        $delta = $dtheta / $numPieces;

        $result = [];
        for ($i = 0; $i < $numPieces; $i++) {
            $a1 = $theta1 + $i * $delta;
            $a2 = $a1 + $delta;
            $k = 4.0 / 3.0 * tan(($a2 - $a1) / 4.0);

            // Points in ellipse parameter space (unit circle coords)
            $u0x = cos($a1); $u0y = sin($a1);
            $u3x = cos($a2); $u3y = sin($a2);
            $u1x = $u0x - $k * sin($a1); $u1y = $u0y + $k * cos($a1);
            $u2x = $u3x + $k * sin($a2); $u2y = $u3y - $k * cos($a2);

            // Scale by radii, rotate by phi, translate by center
            $tx = function (float $ux, float $uy) use ($rx, $ry, $cosPhi, $sinPhi, $cx, $cy): array {
                $ex = $ux * $rx;
                $ey = $uy * $ry;
                return [
                    $cosPhi * $ex - $sinPhi * $ey + $cx,
                    $sinPhi * $ex + $cosPhi * $ey + $cy,
                ];
            };

            $c0 = $tx($u0x, $u0y);
            $c1 = $tx($u1x, $u1y);
            $c2 = $tx($u2x, $u2y);
            $c3 = $tx($u3x, $u3y);

            // Flatten this cubic piece
            $bezier = new CubicBezier($c1[0], $c1[1], $c2[0], $c2[1], $c3[0], $c3[1]);
            $pieceVertices = $bezier->flatten($c0[0], $c0[1], $tolerance);
            foreach ($pieceVertices as $v) {
                $result[] = $v;
            }
        }

        return $result;
    }

    /** @return array{float, float} */
    public function endPoint(): array
    {
        return [$this->x, $this->y];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test`
Expected: 22 tests PASS

- [ ] **Step 5: Commit**

```bash
git add library/draw/EllipticalArc.php tests/Canvas/PathSegmentTest.php
git commit -m "Add EllipticalArc segment with endpoint-to-center parameterization"
```

---

### Task 5: Path class — builder methods + state tracking

**Files:**
- Create: `library/draw/Path.php`
- Create: `tests/Canvas/PathTest.php`

- [ ] **Step 1: Write failing tests for Path builder methods**

```php
<?php
namespace Tests\Canvas;

use draw\Path;
use PHPUnit\Framework\TestCase;

class PathTest extends TestCase
{
    public function test_empty_path_isEmpty(): void
    {
        $path = new Path();
        $this->assertTrue($path->isEmpty());
    }

    public function test_move_to_sets_current_point(): void
    {
        $path = new Path();
        $path->moveTo(10.0, 20.0);
        $this->assertFalse($path->isEmpty());
        $this->assertSame([10.0, 20.0], $path->getCurrentPoint());
    }

    public function test_line_to_updates_current_point(): void
    {
        $path = new Path();
        $path->moveTo(10.0, 20.0);
        $path->lineTo(30.0, 40.0);
        $this->assertSame([30.0, 40.0], $path->getCurrentPoint());
    }

    public function test_horizontal_and_vertical_line_to(): void
    {
        $path = new Path();
        $path->moveTo(10.0, 20.0);
        $path->horizontalLineTo(50.0);
        $this->assertSame([50.0, 20.0], $path->getCurrentPoint());
        $path->verticalLineTo(60.0);
        $this->assertSame([50.0, 60.0], $path->getCurrentPoint());
    }

    public function test_cubic_to_updates_current_point(): void
    {
        $path = new Path();
        $path->moveTo(0.0, 0.0);
        $path->cubicTo(5.0, 5.0, 10.0, 5.0, 15.0, 0.0);
        $this->assertSame([15.0, 0.0], $path->getCurrentPoint());
    }

    public function test_quad_to_updates_current_point(): void
    {
        $path = new Path();
        $path->moveTo(0.0, 0.0);
        $path->quadTo(5.0, 10.0, 10.0, 0.0);
        $this->assertSame([10.0, 0.0], $path->getCurrentPoint());
    }

    public function test_arc_to_updates_current_point(): void
    {
        $path = new Path();
        $path->moveTo(0.0, 0.0);
        $path->arcTo(10.0, 10.0, 0.0, false, true, 20.0, 0.0);
        $this->assertSame([20.0, 0.0], $path->getCurrentPoint());
    }

    public function test_close_path_returns_to_subpath_start(): void
    {
        $path = new Path();
        $path->moveTo(10.0, 20.0);
        $path->lineTo(30.0, 40.0);
        $path->closePath();
        $this->assertSame([10.0, 20.0], $path->getCurrentPoint());
    }

    public function test_smooth_cubic_reflects_previous_cubic(): void
    {
        // After cubicTo(5,5, 10,5, 15,0), the second control point is (10,5).
        // smoothCubicTo should reflect it through the current point (15,0):
        // c1 = (15,0) + ((15,0) - (10,5)) = (15,0) + (5,-5) = (20,-5)
        $path = new Path();
        $path->moveTo(0.0, 0.0);
        $path->cubicTo(5.0, 5.0, 10.0, 5.0, 15.0, 0.0);
        $path->smoothCubicTo(20.0, 5.0, 25.0, 0.0);
        // If reflection worked, current point should be at the smooth cubic endpoint
        $this->assertSame([25.0, 0.0], $path->getCurrentPoint());
    }

    public function test_smooth_cubic_after_non_cubic_uses_current_point(): void
    {
        // After a lineTo, there is no previous cubic control point.
        // smoothCubicTo should use the current point as c1.
        $path = new Path();
        $path->moveTo(0.0, 0.0);
        $path->lineTo(10.0, 0.0);
        $path->smoothCubicTo(15.0, 5.0, 20.0, 0.0);
        $this->assertSame([20.0, 0.0], $path->getCurrentPoint());
    }

    public function test_smooth_quad_reflects_previous_quad(): void
    {
        // After quadTo(5,10, 10,0), the control point is (5,10).
        // smoothQuadTo should reflect it through current point (10,0):
        // cp = (10,0) + ((10,0) - (5,10)) = (10,0) + (5,-10) = (15,-10)
        $path = new Path();
        $path->moveTo(0.0, 0.0);
        $path->quadTo(5.0, 10.0, 10.0, 0.0);
        $path->smoothQuadTo(15.0, 0.0);
        $this->assertSame([15.0, 0.0], $path->getCurrentPoint());
    }

    public function test_smooth_quad_after_non_quad_uses_current_point(): void
    {
        $path = new Path();
        $path->moveTo(0.0, 0.0);
        $path->lineTo(10.0, 0.0);
        $path->smoothQuadTo(20.0, 0.0);
        $this->assertSame([20.0, 0.0], $path->getCurrentPoint());
    }

    public function test_multiple_subpaths_via_multiple_move_to(): void
    {
        $path = new Path();
        $path->moveTo(10.0, 10.0);
        $path->lineTo(20.0, 10.0);
        $path->closePath();
        $path->moveTo(30.0, 30.0);
        $path->lineTo(40.0, 30.0);
        // After second moveTo, current point should be (30,30)
        $this->assertSame([30.0, 30.0], $path->getCurrentPoint());
    }

    public function test_implicit_move_to_when_drawing_without_current_point(): void
    {
        // SVG spec: if a drawing command is issued without a preceding M,
        // it's as if M 0,0 was issued first.
        $path = new Path();
        $path->lineTo(10.0, 20.0);
        $this->assertSame([10.0, 20.0], $path->getCurrentPoint());
    }

    public function test_builder_methods_return_self_for_chaining(): void
    {
        $path = new Path();
        $this->assertSame($path, $path->moveTo(0.0, 0.0));
        $this->assertSame($path, $path->lineTo(10.0, 10.0));
        $this->assertSame($path, $path->cubicTo(3.0, 3.0, 7.0, 7.0, 10.0, 10.0));
        $this->assertSame($path, $path->quadTo(5.0, 5.0, 10.0, 10.0));
        $this->assertSame($path, $path->closePath());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test`
Expected: FAIL — `draw\Path` not found

- [ ] **Step 3: Implement Path class (builder methods + state tracking, no flatten yet)**

```php
<?php
namespace draw;

class Path
{
    /** @var array<PathSegment> */
    private array $segments = [];

    private float $currentX = 0.0;
    private float $currentY = 0.0;
    private float $subpathStartX = 0.0;
    private float $subpathStartY = 0.0;
    private bool $hasCurrentPoint = false;

    // Previous curve control point for smooth-curve reflection.
    // Only set when the previous segment was a cubic or quadratic.
    private ?float $prevCubicC2x = null;
    private ?float $prevCubicC2y = null;
    private ?float $prevQuadCpx = null;
    private ?float $prevQuadCpy = null;

    public function moveTo(float $x, float $y): self
    {
        $this->segments[] = new MoveTo($x, $y);
        $this->currentX = $x;
        $this->currentY = $y;
        $this->subpathStartX = $x;
        $this->subpathStartY = $y;
        $this->hasCurrentPoint = true;
        $this->prevCubicC2x = null;
        $this->prevCubicC2y = null;
        $this->prevQuadCpx = null;
        $this->prevQuadCpy = null;
        return $this;
    }

    public function lineTo(float $x, float $y): self
    {
        $this->ensureCurrentPoint();
        $this->segments[] = new LineTo($x, $y);
        $this->currentX = $x;
        $this->currentY = $y;
        $this->prevCubicC2x = null;
        $this->prevCubicC2y = null;
        $this->prevQuadCpx = null;
        $this->prevQuadCpy = null;
        return $this;
    }

    public function horizontalLineTo(float $x): self
    {
        return $this->lineTo($x, $this->currentY);
    }

    public function verticalLineTo(float $y): self
    {
        return $this->lineTo($this->currentX, $y);
    }

    public function cubicTo(float $c1x, float $c1y, float $c2x, float $c2y, float $x, float $y): self
    {
        $this->ensureCurrentPoint();
        $this->segments[] = new CubicBezier($c1x, $c1y, $c2x, $c2y, $x, $y);
        $this->currentX = $x;
        $this->currentY = $y;
        $this->prevCubicC2x = $c2x;
        $this->prevCubicC2y = $c2y;
        $this->prevQuadCpx = null;
        $this->prevQuadCpy = null;
        return $this;
    }

    public function smoothCubicTo(float $c2x, float $c2y, float $x, float $y): self
    {
        $this->ensureCurrentPoint();
        // Reflect previous cubic C2 through current point; if no previous cubic, c1 = current point
        if ($this->prevCubicC2x !== null) {
            $c1x = 2.0 * $this->currentX - $this->prevCubicC2x;
            $c1y = 2.0 * $this->currentY - $this->prevCubicC2y;
        } else {
            $c1x = $this->currentX;
            $c1y = $this->currentY;
        }
        return $this->cubicTo($c1x, $c1y, $c2x, $c2y, $x, $y);
    }

    public function quadTo(float $cpx, float $cpy, float $x, float $y): self
    {
        $this->ensureCurrentPoint();
        $this->segments[] = new QuadraticBezier($cpx, $cpy, $x, $y);
        $this->currentX = $x;
        $this->currentY = $y;
        $this->prevCubicC2x = null;
        $this->prevCubicC2y = null;
        $this->prevQuadCpx = $cpx;
        $this->prevQuadCpy = $cpy;
        return $this;
    }

    public function smoothQuadTo(float $x, float $y): self
    {
        $this->ensureCurrentPoint();
        // Reflect previous quadratic control point through current point
        if ($this->prevQuadCpx !== null) {
            $cpx = 2.0 * $this->currentX - $this->prevQuadCpx;
            $cpy = 2.0 * $this->currentY - $this->prevQuadCpy;
        } else {
            $cpx = $this->currentX;
            $cpy = $this->currentY;
        }
        return $this->quadTo($cpx, $cpy, $x, $y);
    }

    public function arcTo(
        float $rx,
        float $ry,
        float $xAxisRot,
        bool $largeArc,
        bool $sweep,
        float $x,
        float $y
    ): self {
        $this->ensureCurrentPoint();
        $this->segments[] = new EllipticalArc($rx, $ry, $xAxisRot, $largeArc, $sweep, $x, $y);
        $this->currentX = $x;
        $this->currentY = $y;
        $this->prevCubicC2x = null;
        $this->prevCubicC2y = null;
        $this->prevQuadCpx = null;
        $this->prevQuadCpy = null;
        return $this;
    }

    public function closePath(): self
    {
        if (!$this->hasCurrentPoint) {
            return $this;
        }
        $this->segments[] = new ClosePath($this->subpathStartX, $this->subpathStartY);
        $this->currentX = $this->subpathStartX;
        $this->currentY = $this->subpathStartY;
        $this->prevCubicC2x = null;
        $this->prevCubicC2y = null;
        $this->prevQuadCpx = null;
        $this->prevQuadCpy = null;
        return $this;
    }

    /** @return array{float, float} */
    public function getCurrentPoint(): array
    {
        return [$this->currentX, $this->currentY];
    }

    public function isEmpty(): bool
    {
        return count($this->segments) === 0;
    }

    private function ensureCurrentPoint(): void
    {
        if (!$this->hasCurrentPoint) {
            $this->moveTo(0.0, 0.0);
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test`
Expected: 37 tests PASS (22 existing + 15 new)

- [ ] **Step 5: Commit**

```bash
git add library/draw/Path.php tests/Canvas/PathTest.php
git commit -m "Add Path class with builder methods and state tracking"
```

---

### Task 6: Path::flatten()

**Files:**
- Modify: `library/draw/Path.php` — add `flatten()` method
- Modify: `tests/Canvas/PathTest.php` — add flatten tests

- [ ] **Step 1: Write failing tests for Path::flatten()**

Add to `tests/Canvas/PathTest.php`:

```php
// Add inside the class:

    public function test_flatten_rectangle_path(): void
    {
        $path = new Path();
        $path->moveTo(10.0, 10.0)
             ->lineTo(50.0, 10.0)
             ->lineTo(50.0, 30.0)
             ->lineTo(10.0, 30.0)
             ->closePath();

        $subpaths = $path->flatten();
        $this->assertCount(1, $subpaths);
        $this->assertTrue($subpaths[0]['closed']);
        $vertices = $subpaths[0]['vertices'];
        // Should have 4 vertices: (10,10), (50,10), (50,30), (10,30)
        $this->assertCount(4, $vertices);
        $this->assertSame([10.0, 10.0], $vertices[0]);
        $this->assertSame([50.0, 10.0], $vertices[1]);
        $this->assertSame([50.0, 30.0], $vertices[2]);
        $this->assertSame([10.0, 30.0], $vertices[3]);
    }

    public function test_flatten_open_path_is_not_closed(): void
    {
        $path = new Path();
        $path->moveTo(10.0, 10.0)
             ->lineTo(50.0, 10.0)
             ->lineTo(50.0, 30.0);

        $subpaths = $path->flatten();
        $this->assertCount(1, $subpaths);
        $this->assertFalse($subpaths[0]['closed']);
    }

    public function test_flatten_multi_subpath(): void
    {
        $path = new Path();
        $path->moveTo(10.0, 10.0)
             ->lineTo(20.0, 10.0)
             ->closePath()
             ->moveTo(30.0, 30.0)
             ->lineTo(40.0, 30.0)
             ->closePath();

        $subpaths = $path->flatten();
        $this->assertCount(2, $subpaths);
        $this->assertTrue($subpaths[0]['closed']);
        $this->assertTrue($subpaths[1]['closed']);
        $this->assertSame([10.0, 10.0], $subpaths[0]['vertices'][0]);
        $this->assertSame([30.0, 30.0], $subpaths[1]['vertices'][0]);
    }

    public function test_flatten_cubic_bezier_produces_multiple_vertices(): void
    {
        $path = new Path();
        $path->moveTo(0.0, 0.0)
             ->cubicTo(0.0, 10.0, 10.0, 0.0, 10.0, 10.0);

        $subpaths = $path->flatten(0.5);
        $this->assertCount(1, $subpaths);
        $vertices = $subpaths[0]['vertices'];
        $this->assertGreaterThan(2, count($vertices), 'Curved cubic should produce multiple vertices');
        // First vertex near start, last vertex is endpoint
        $last = $vertices[count($vertices) - 1];
        $this->assertEqualsWithDelta(10.0, $last[0], 0.01);
        $this->assertEqualsWithDelta(10.0, $last[1], 0.01);
    }

    public function test_flatten_arc_produces_multiple_vertices(): void
    {
        $path = new Path();
        $path->moveTo(0.0, 0.0)
             ->arcTo(10.0, 10.0, 0.0, false, true, 20.0, 0.0);

        $subpaths = $path->flatten();
        $this->assertCount(1, $subpaths);
        $this->assertGreaterThan(2, count($subpaths[0]['vertices']));
    }

    public function test_flatten_empty_path_returns_empty(): void
    {
        $path = new Path();
        $this->assertSame([], $path->flatten());
    }

    public function test_flatten_single_move_to_omitted(): void
    {
        // A subpath with only a MoveTo (no drawing commands) has no area
        // and should be omitted from flatten output.
        $path = new Path();
        $path->moveTo(10.0, 10.0);
        $this->assertSame([], $path->flatten());
    }

    public function test_flatten_close_path_then_move_to_creates_two_subpaths(): void
    {
        $path = new Path();
        $path->moveTo(0.0, 0.0)
             ->lineTo(10.0, 0.0)
             ->closePath()
             ->moveTo(20.0, 20.0);

        $subpaths = $path->flatten();
        // Second subpath is just a MoveTo → omitted
        $this->assertCount(1, $subpaths);
    }

    public function test_flatten_open_subpath_at_end_included(): void
    {
        $path = new Path();
        $path->moveTo(10.0, 10.0)
             ->lineTo(20.0, 10.0)
             ->lineTo(20.0, 20.0);

        $subpaths = $path->flatten();
        $this->assertCount(1, $subpaths);
        $this->assertFalse($subpaths[0]['closed']);
        $this->assertCount(3, $subpaths[0]['vertices']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test`
Expected: FAIL — `Path::flatten()` not defined

- [ ] **Step 3: Implement flatten() in Path**

Add this method to the Path class (and the `getSegments()` method for testing access if needed):

```php
/**
 * Flatten all segments into polygon vertices grouped by subpath.
 *
 * Each subpath is an array with keys:
 * - 'vertices': list of [x, y] float pairs
 * - 'closed': bool (true if the subpath ended with ClosePath)
 *
 * Degenerate subpaths (single MoveTo with no drawing commands) are omitted.
 *
 * @param float $tolerance Maximum deviation from true curve, in canvas units.
 * @return array<int, array{vertices: array<int, array{float, float}>, closed: bool}>
 */
public function flatten(float $tolerance = 0.5): array
{
    /** @var array<int, array{float, float}> $currentVertices */
    $currentVertices = [];
    $subpaths = [];
    $currentX = 0.0;
    $currentY = 0.0;
    $inSubpath = false;

    foreach ($this->segments as $seg) {
        if ($seg instanceof MoveTo) {
            // Finish previous subpath if it has vertices
            if ($inSubpath && count($currentVertices) >= 2) {
                $subpaths[] = ['vertices' => $currentVertices, 'closed' => false];
            }
            // Start new subpath
            $ep = $seg->endPoint();
            $currentX = $ep[0];
            $currentY = $ep[1];
            $currentVertices = [[$currentX, $currentY]];
            $inSubpath = true;
        } elseif ($seg instanceof ClosePath) {
            if ($inSubpath && count($currentVertices) >= 2) {
                $subpaths[] = ['vertices' => $currentVertices, 'closed' => true];
            }
            $ep = $seg->endPoint();
            $currentX = $ep[0];
            $currentY = $ep[1];
            $currentVertices = [];
            $inSubpath = false;
        } else {
            // Drawing segment: flatten and append vertices
            $vertices = $seg->flatten($currentX, $currentY, $tolerance);
            foreach ($vertices as $v) {
                $currentVertices[] = $v;
            }
            $ep = $seg->endPoint();
            $currentX = $ep[0];
            $currentY = $ep[1];
        }
    }

    // Handle trailing open subpath
    if ($inSubpath && count($currentVertices) >= 2) {
        $subpaths[] = ['vertices' => $currentVertices, 'closed' => false];
    }

    return $subpaths;
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test`
Expected: 46 tests PASS (37 + 9 new)

- [ ] **Step 5: Commit**

```bash
git add library/draw/Path.php tests/Canvas/PathTest.php
git commit -m "Add Path::flatten() with subpath grouping and tolerance"
```

---

### Task 7: Multi-subpath fillPolygonScanline refactor

**Files:**
- Modify: `library/draw/Canvas.php` — refactor `fillPolygonScanline` to accept multi-subpath

This task refactors the existing private method. There are no new external-facing
methods to test directly — the multi-subpath fill is exercised through
`drawPath()` in Task 8. The goal here is to add `fillPolygonScanlineMulti`,
make `fillPolygonScanline` delegate to it, and verify all existing tests still pass.

- [ ] **Step 1: Add fillPolygonScanlineMulti to Canvas.php**

Add this new private method to `library/draw/Canvas.php`:

```php
/**
 * Fill the interior of multiple subpaths using scanline conversion with the
 * non-zero winding rule. All subpaths contribute edges to the intersection
 * list, so overlapping or nested subpaths interact correctly (e.g., a
 * clockwise outer + counter-clockwise inner creates a hole).
 *
 * Uses the same half-open / top-left convention as fillPolygonScanline.
 *
 * @param array<int, array<int, array{int, int}>> $subpaths
 */
private function fillPolygonScanlineMulti(array $subpaths, Color $color, string $text): void
{
    if (count($subpaths) === 0) {
        return;
    }

    // Compute bounding box across all subpaths
    $minY = PHP_INT_MAX;
    $maxY = PHP_INT_MIN;
    foreach ($subpaths as $polygon) {
        $n = count($polygon);
        for ($i = 0; $i < $n; $i++) {
            if ($polygon[$i][1] < $minY) {
                $minY = $polygon[$i][1];
            }
            if ($polygon[$i][1] > $maxY) {
                $maxY = $polygon[$i][1];
            }
        }
    }

    for ($Y = $minY; $Y <= $maxY; $Y++) {
        $intersections = [];

        // Collect intersections from ALL subpaths
        foreach ($subpaths as $polygon) {
            $n = count($polygon);
            for ($i = 0; $i < $n; $i++) {
                $x1 = $polygon[$i][0];
                $y1 = $polygon[$i][1];
                $x2 = $polygon[($i + 1) % $n][0];
                $y2 = $polygon[($i + 1) % $n][1];

                $yLo = $y1 < $y2 ? $y1 : $y2;
                $yHi = $y1 < $y2 ? $y2 : $y1;

                if ($Y < $yLo || $Y >= $yHi) {
                    continue;
                }

                $xInt = $x1 + ($x2 - $x1) * ($Y - $y1) / ($y2 - $y1);
                $dir = ($y2 > $y1) ? 1 : -1;
                $intersections[] = [$xInt, $dir];
            }
        }

        usort($intersections, fn ($a, $b) => $a[0] <=> $b[0]);

        $winding = 0;
        $spanStart = null;
        foreach ($intersections as [$xInt, $dir]) {
            $prevWinding = $winding;
            $winding += $dir;
            if ($prevWinding === 0 && $winding !== 0) {
                $spanStart = $xInt;
            } elseif ($prevWinding !== 0 && $winding === 0) {
                $spanEnd = $xInt;
                if ($spanStart !== null) {
                    $xL = (int) ceil($spanStart);
                    $xR = (int) floor($spanEnd);
                    for ($xx = $xL; $xx <= $xR; $xx++) {
                        $this->drawPoint($xx, $Y, $color, $text);
                    }
                }
                $spanStart = null;
            }
        }
    }
}
```

- [ ] **Step 2: Refactor fillPolygonScanline to delegate**

Replace the existing `fillPolygonScanline` method body with a single delegation:

```php
private function fillPolygonScanline(array $points, Color $color, string $text): void
{
    $this->fillPolygonScanlineMulti([$points], $color, $text);
}
```

Remove the old single-polygon implementation body (the bounding box computation,
the scanline loop, the intersection collection, etc.). Keep only the delegation.

- [ ] **Step 3: Run ALL tests to verify no regression**

Run: `composer test`
Expected: 46 tests PASS (all existing tests still pass — the refactor is behavior-preserving)

- [ ] **Step 4: Run PHPStan**

Run: `composer phpstan 2>&1 | tail -3`
Expected: 676 errors (same as baseline)

- [ ] **Step 5: Commit**

```bash
git add library/draw/Canvas.php
git commit -m "Refactor fillPolygonScanline to delegate to multi-subpath variant"
```

---

### Task 8: Canvas::drawPath()

**Files:**
- Modify: `library/draw/Canvas.php` — add `drawPath()` method
- Modify: `tests/Canvas/PathTest.php` — add drawPath tests

- [ ] **Step 1: Write failing tests for drawPath()**

Add to `tests/Canvas/PathTest.php`:

```php
use draw\Canvas;
use draw\Color;

// Add inside the class:

    public function test_draw_path_fill_matches_draw_polygon(): void
    {
        // A simple rectangle path should fill the same as drawPolygon
        $path = new Path();
        $path->moveTo(2.0, 2.0)
             ->lineTo(8.0, 2.0)
             ->lineTo(8.0, 6.0)
             ->lineTo(2.0, 6.0)
             ->closePath();

        $canvas1 = Canvas::createBlank(12, 12);
        $canvas1->drawPolygon(
            [[2, 2], [8, 2], [8, 6], [2, 6]],
            new Color(3, null),
            null
        );

        $canvas2 = Canvas::createBlank(12, 12);
        $canvas2->drawPath($path, new Color(3, null), null);

        // Compare every pixel
        for ($y = 0; $y < 12; $y++) {
            for ($x = 0; $x < 12; $x++) {
                $this->assertSame(
                    $canvas1->data[$y][$x]->fg,
                    $canvas2->data[$y][$x]->fg,
                    "Pixel ($x, $y) differs between drawPolygon and drawPath"
                );
            }
        }
    }

    public function test_draw_path_outline_matches_draw_polygon(): void
    {
        $path = new Path();
        $path->moveTo(2.0, 2.0)
             ->lineTo(8.0, 2.0)
             ->lineTo(8.0, 6.0)
             ->lineTo(2.0, 6.0)
             ->closePath();

        $canvas1 = Canvas::createBlank(12, 12);
        $canvas1->drawPolygon(
            [[2, 2], [8, 2], [8, 6], [2, 6]],
            null,
            new Color(5, null)
        );

        $canvas2 = Canvas::createBlank(12, 12);
        $canvas2->drawPath($path, null, new Color(5, null));

        for ($y = 0; $y < 12; $y++) {
            for ($x = 0; $x < 12; $x++) {
                $this->assertSame(
                    $canvas1->data[$y][$x]->fg,
                    $canvas2->data[$y][$x]->fg,
                    "Pixel ($x, $y) outline differs between drawPolygon and drawPath"
                );
            }
        }
    }

    public function test_draw_path_both_fill_and_outline(): void
    {
        $path = new Path();
        $path->moveTo(2.0, 2.0)
             ->lineTo(8.0, 2.0)
             ->lineTo(8.0, 6.0)
             ->lineTo(2.0, 6.0)
             ->closePath();

        $canvas = Canvas::createBlank(12, 12);
        $canvas->drawPath($path, new Color(3, null), new Color(5, null));

        // Interior should be fill color
        $this->assertSame(3, $canvas->data[4][5]->fg);
        // Corner should be outline color (outline drawn on top)
        $this->assertSame(5, $canvas->data[2][2]->fg);
    }

    public function test_draw_path_empty_is_noop(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $path = new Path();
        $canvas->drawPath($path, new Color(3, null), new Color(5, null));

        for ($y = 0; $y < 10; $y++) {
            for ($x = 0; $x < 10; $x++) {
                $this->assertNull($canvas->data[$y][$x]->fg);
            }
        }
    }

    public function test_draw_path_donut_creates_hole(): void
    {
        // Outer square (CW), inner square (CCW) — donut with hole
        $path = new Path();
        // Outer CW: (2,2)→(17,2)→(17,17)→(2,17)
        $path->moveTo(2.0, 2.0)
             ->lineTo(17.0, 2.0)
             ->lineTo(17.0, 17.0)
             ->lineTo(2.0, 17.0)
             ->closePath()
             // Inner CCW: (6,6)→(6,13)→(13,13)→(13,6)
             ->moveTo(6.0, 6.0)
             ->lineTo(6.0, 13.0)
             ->lineTo(13.0, 13.0)
             ->lineTo(13.0, 6.0)
             ->closePath();

        $canvas = Canvas::createBlank(20, 20, true);
        $canvas->drawPath($path, new Color(3, null), null);

        // Center of hole should be empty
        $this->assertNull($canvas->data[9][9]->fg, "Donut hole center should be empty");
        // Ring should be filled
        $this->assertSame(3, $canvas->data[4][4]->fg, "Donut ring should be filled");
    }

    public function test_draw_path_outside_canvas_is_noop(): void
    {
        $path = new Path();
        $path->moveTo(-20.0, -20.0)
             ->lineTo(-10.0, -20.0)
             ->lineTo(-10.0, -10.0)
             ->lineTo(-20.0, -10.0)
             ->closePath();

        $canvas = Canvas::createBlank(10, 10);
        $canvas->drawPath($path, new Color(3, null), new Color(5, null));

        for ($y = 0; $y < 10; $y++) {
            for ($x = 0; $x < 10; $x++) {
                $this->assertNull($canvas->data[$y][$x]->fg);
            }
        }
    }

    public function test_draw_path_open_subpath_outline_no_closing_line(): void
    {
        // An open subpath should NOT draw a closing line for the outline
        $path = new Path();
        $path->moveTo(2.0, 2.0)
             ->lineTo(8.0, 2.0)
             ->lineTo(8.0, 8.0);
        // No closePath → open

        $canvas = Canvas::createBlank(12, 12);
        $canvas->drawPath($path, null, new Color(5, null));

        // The closing line from (8,8) back to (2,2) should NOT be drawn
        // Point on the closing diagonal: (5,5) — should not have outline
        $this->assertNull(
            $canvas->data[5][5]->fg,
            "Open subpath should not draw closing line"
        );
    }

    public function test_draw_path_closed_subpath_outline_has_closing_line(): void
    {
        $path = new Path();
        $path->moveTo(2.0, 2.0)
             ->lineTo(8.0, 2.0)
             ->lineTo(8.0, 8.0)
             ->closePath();

        $canvas = Canvas::createBlank(12, 12);
        $canvas->drawPath($path, null, new Color(5, null));

        // The closing line from (8,8) back to (2,2) SHOULD be drawn
        // (2,2) should be outline color (it's a vertex + endpoint of closing line)
        $this->assertSame(5, $canvas->data[2][2]->fg, "Closing line endpoint should be outlined");
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test`
Expected: FAIL — `drawPath()` not defined on Canvas

- [ ] **Step 3: Implement drawPath() on Canvas**

Add to `library/draw/Canvas.php` (and add `use draw\Path;` at the top if not already imported — since Canvas and Path are in the same namespace `draw`, no import is needed):

```php
/**
 * Draw a Path with optional fill and outline.
 *
 * The path is flattened to polygon vertices, snapped to the integer pixel
 * grid, then filled (multi-subpath scanline with non-zero winding rule)
 * and outlined (Bresenham lines per subpath).
 *
 * Outline is drawn on top of fill. Each subpath is outlined separately;
 * closed subpaths get a closing line, open subpaths do not.
 *
 * @param Path $path The path to render.
 * @param ?Color $fillColor Fill color, or null for no fill.
 * @param ?Color $outlineColor Outline color, or null for no outline.
 * @param string $text Optional text for rendered pixels.
 */
public function drawPath(
    Path $path,
    ?Color $fillColor,
    ?Color $outlineColor,
    string $text = ''
): void {
    $subpaths = $path->flatten();
    if (count($subpaths) === 0) {
        return;
    }
    if ($fillColor === null && $outlineColor === null) {
        return;
    }

    // Snap all vertices to integers
    $snappedSubpaths = [];
    foreach ($subpaths as $sp) {
        $snapped = [];
        foreach ($sp['vertices'] as $v) {
            $snapped[] = [(int) round($v[0]), (int) round($v[1])];
        }
        $snappedSubpaths[] = ['vertices' => $snapped, 'closed' => $sp['closed']];
    }

    // Fill: all subpaths contribute to winding rule
    if ($fillColor !== null) {
        $polygonArrays = [];
        foreach ($snappedSubpaths as $sp) {
            if (count($sp['vertices']) >= 3) {
                $polygonArrays[] = $sp['vertices'];
            }
        }
        if (count($polygonArrays) > 0) {
            $this->fillPolygonScanlineMulti($polygonArrays, $fillColor, $text);
        }
    }

    // Outline: draw each subpath separately
    if ($outlineColor !== null) {
        foreach ($snappedSubpaths as $sp) {
            $vertices = $sp['vertices'];
            $n = count($vertices);
            if ($n < 2) {
                continue;
            }
            for ($i = 1; $i < $n; $i++) {
                $this->drawLine(
                    $vertices[$i - 1][0],
                    $vertices[$i - 1][1],
                    $vertices[$i][0],
                    $vertices[$i][1],
                    $outlineColor,
                    $text
                );
            }
            // Closing line for closed subpaths
            if ($sp['closed']) {
                $this->drawLine(
                    $vertices[$n - 1][0],
                    $vertices[$n - 1][1],
                    $vertices[0][0],
                    $vertices[0][1],
                    $outlineColor,
                    $text
                );
            }
        }
    }
}
```

- [ ] **Step 4: Run ALL tests to verify everything passes**

Run: `composer test`
Expected: 54 tests PASS (46 existing + 8 new). All previous Canvas and PathSegment tests still pass.

- [ ] **Step 5: Run PHPStan to check static analysis**

Run: `composer phpstan 2>&1 | tail -3`
Expected: 676 errors (same as baseline — no increase)

- [ ] **Step 6: Commit**

```bash
git add library/draw/Canvas.php tests/Canvas/PathTest.php
git commit -m "Add Canvas::drawPath() with multi-subpath fill and outline"
```
