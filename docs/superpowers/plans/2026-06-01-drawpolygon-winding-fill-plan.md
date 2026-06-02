# drawPolygon winding-fill primitive Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `Canvas::drawPolygon` primitive that fills polygons via non-zero-winding-rule scanline conversion, refactor `@stars` to use it (fixing the corner-stranding bug), and introduce PHPUnit as the project's test framework.

**Architecture:** New `drawPolygon` method on `draw\Canvas` accepts an array of `[$x, $y]` vertices plus optional fill and outline colors; it does fill first (scanline algorithm with half-open convention and non-zero winding rule), then draws the outline as Bresenham line segments on top. The `stars()` artbot command is refactored to call `drawPolygon` instead of looping `drawLine` + `fillColor`. PHPUnit 10 is added as a dev dependency with a dedicated `phpunit.xml` that uses composer autoload only (not `bootstrap.php`).

**Tech Stack:** PHP 8.1+, PHPUnit 10, existing `draw\Canvas`/`Color`/`Pixel`, existing `drawLine`/`drawPoint` primitives.

**Spec:** `docs/superpowers/specs/2026-06-01-drawpolygon-winding-fill-design.md`

---

## File structure

- **Create:** `phpunit.xml` — PHPUnit configuration at project root
- **Create:** `tests/Canvas/CanvasTest.php` — test cases for `drawPolygon`
- **Modify:** `composer.json` — add `phpunit/phpunit:^10` to `require-dev`, add `Tests\` PSR-4 to `autoload-dev`, add `test` script
- **Modify:** `psalm.xml` — add `tests` directory to project files
- **Modify:** `AGENTS.md` — replace "No test framework is configured." with PHPUnit + `composer test` bullet
- **Modify:** `library/draw/Canvas.php` — add `drawPolygon` (and private helper `fillPolygonScanline`)
- **Modify:** `artbot_scripts/drawing.php` — refactor `stars()` to use `drawPolygon`

---

## Task 1: PHPUnit setup

**Files:**
- Modify: `composer.json`
- Create: `phpunit.xml`
- Modify: `psalm.xml`
- Modify: `AGENTS.md`

- [ ] **Step 1: Add PHPUnit as a dev dependency**

```bash
composer require --dev "phpunit/phpunit:^10"
```

This will also update `composer.lock` and create `vendor/bin/phpunit`.

- [ ] **Step 2: Add `Tests\` PSR-4 autoload-dev and `test` script to `composer.json`**

In `composer.json`, add an `autoload-dev` block alongside the existing `autoload` block, and a `test` entry under `scripts`:

```json
"autoload-dev": {
    "psr-4": {
        "Tests\\": "tests/"
    }
},
"scripts": {
    "phpstan": "php -d memory_limit=1G vendor/bin/phpstan analyse",
    "test": "vendor/bin/phpunit"
},
```

If `scripts` already exists, add only the `"test"` entry; do not duplicate `phpstan`.

- [ ] **Step 3: Regenerate composer autoload**

```bash
composer dump-autoload
```

- [ ] **Step 4: Create `phpunit.xml` at project root**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>library</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 5: Confirm PHPStan covers `tests/`**

The existing `phpstan.neon` uses `paths: - .` which recursively analyses every PHP file in the project root including `tests/`. No modification needed. Verify by running:

```bash
composer phpstan
```

It should analyse files in `tests/` as well (no errors expected since the directory is empty at this point).

- [ ] **Step 6: Add `tests` directory to Psalm**

In `psalm.xml`, inside `<projectFiles>`, add:

```xml
<directory name="tests" />
```

- [ ] **Step 7: Update `AGENTS.md`**

Replace this line in the **Key commands** section:

```markdown
- **No test framework is configured.**
```

with:

```markdown
- **Tests:** `composer test` (PHPUnit 10; config in `phpunit.xml`, tests in `tests/`)
```

- [ ] **Step 8: Verify the test runner works**

```bash
composer test
```

Expected: PHPUnit runs, reports "No tests executed" (or similar) and exits 0. There must be no errors about missing autoload, missing bootstrap, or schema problems.

- [ ] **Step 9: Verify static analysers still pass**

```bash
composer phpstan
vendor/bin/psalm
```

Expected: both exit 0 (no new issues introduced; `tests/` directory is empty so adds no analysis targets yet).

- [ ] **Step 10: Review**

Use the `superpowers:requesting-code-review` skill. Specifically verify:
- `phpunit.xml` uses `vendor/autoload.php`, NOT `bootstrap.php`
- Composer JSON is valid (`composer validate` should pass)
- No changes to `require` block of `composer.json` — only `require-dev` and `autoload-dev`

Fix any issues before committing.

- [ ] **Step 11: Commit**

```bash
git add composer.json composer.lock phpunit.xml psalm.xml AGENTS.md
git commit -m "Add PHPUnit 10 test framework"
```

---

## Task 2: First failing test + drawPolygon skeleton

**Files:**
- Create: `tests/Canvas/CanvasTest.php`
- Modify: `library/draw/Canvas.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Canvas/CanvasTest.php`:

```php
<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\Color;
use PHPUnit\Framework\TestCase;

class CanvasTest extends TestCase
{
    public function test_draw_polygon_with_both_colors_null_is_noop(): void
    {
        $canvas = Canvas::createBlank(10, 10);

        $canvas->drawPolygon(
            [[1.0, 1.0], [5.0, 1.0], [5.0, 5.0], [1.0, 5.0]],
            null,
            null
        );

        // Every pixel in a blank canvas has null fg and bg; nothing should have changed.
        for ($y = 0; $y < 10; $y++) {
            for ($x = 0; $x < 10; $x++) {
                $this->assertNull(
                    $canvas->data[$y][$x]->fg,
                    "Pixel ($x, $y) fg was modified when both colors are null"
                );
                $this->assertNull(
                    $canvas->data[$y][$x]->bg,
                    "Pixel ($x, $y) bg was modified when both colors are null"
                );
            }
        }
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
composer test
```

Expected: FAIL with an error like `Error: Call to undefined method draw\Canvas::drawPolygon()`.

- [ ] **Step 3: Add the drawPolygon skeleton**

In `library/draw/Canvas.php`, add the following method at the end of the class (before the closing `}`):

```php
    /**
     * Draw a closed polygon with optional fill and outline.
     *
     * Fill is applied first via scanline conversion using the non-zero winding
     * rule; outline is drawn on top via drawLine so it cleanly covers the fill
     * boundary. The polygon is implicitly closed (last vertex connects to first).
     *
     * @param array<int, array{0: int|float, 1: int|float}> $points [[$x, $y], ...]
     */
    public function drawPolygon(
        array $points,
        ?Color $fillColor,
        ?Color $outlineColor,
        string $text = ''
    ): void {
        if (count($points) < 3) {
            return;
        }
        if ($fillColor === null && $outlineColor === null) {
            return;
        }
        // Fill + outline bodies are added in Tasks 3 and 4.
    }
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
composer test
```

Expected: PASS (1 test, 1 assertion block).

- [ ] **Step 5: Verify static analysers**

```bash
composer phpstan
vendor/bin/psalm
```

Expected: both pass at level 9 / level 1.

- [ ] **Step 6: Review**

Use `superpowers:requesting-code-review`. Verify:
- Method signature matches the spec exactly (param order, types, nullable colors)
- Docblock describes both fill-first-then-outline ordering and winding rule
- Early-return order: `< 3 vertices` before `both null` (cheaper check first)

Fix any issues.

- [ ] **Step 7: Commit**

```bash
git add tests/Canvas/CanvasTest.php library/draw/Canvas.php
git commit -m "Add drawPolygon skeleton with no-op guards"
```

---

## Task 3: Outline-only square test + outline loop implementation

**Files:**
- Modify: `tests/Canvas/CanvasTest.php`
- Modify: `library/draw/Canvas.php`

- [ ] **Step 1: Add the failing test**

Append to `tests/Canvas/CanvasTest.php` inside the class:

```php
    public function test_draw_polygon_outline_only_square(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $outline = new Color(5, null);

        // Square (1,1)-(5,1)-(5,5)-(1,5)
        $canvas->drawPolygon(
            [[1.0, 1.0], [5.0, 1.0], [5.0, 5.0], [1.0, 5.0]],
            null,
            $outline
        );

        // Corners must have the outline color.
        $this->assertSame(5, $canvas->data[1][1]->fg);
        $this->assertSame(5, $canvas->data[5][1]->fg);
        $this->assertSame(5, $canvas->data[5][5]->fg);
        $this->assertSame(5, $canvas->data[1][5]->fg);

        // Interior (2,2)-(4,4) must NOT be touched.
        for ($y = 2; $y <= 4; $y++) {
            for ($x = 2; $x <= 4; $x++) {
                $this->assertNull(
                    $canvas->data[$y][$x]->fg,
                    "Interior pixel ($x, $y) was colored but only outline was requested"
                );
            }
        }
    }
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
composer test
```

Expected: FAIL. Corners are not colored because the outline loop is not yet implemented.

- [ ] **Step 3: Implement the outline loop**

In `library/draw/Canvas.php`, replace the body of `drawPolygon` (after the two early returns) with:

```php
        // Outline on top of fill. Implemented first; fill is added in the next task.
        if ($outlineColor !== null) {
            $firstX = null;
            $firstY = null;
            $prevX = null;
            $prevY = null;
            foreach ($points as $point) {
                $x = (int) round($point[0]);
                $y = (int) round($point[1]);
                if ($firstX === null) {
                    $firstX = $x;
                    $firstY = $y;
                } else {
                    $this->drawLine($prevX, $prevY, $x, $y, $outlineColor, $text);
                }
                $prevX = $x;
                $prevY = $y;
            }
            // Close the polygon: last vertex back to first.
            if ($firstX !== null && ($prevX !== $firstX || $prevY !== $firstY)) {
                $this->drawLine($prevX, $prevY, $firstX, $firstY, $outlineColor, $text);
            }
        }
```

(Rounder reuses the existing `drawLine` for Bresenham; vertex coords are rounded to int consistent with how `stars` previously rounded its trig output before calling `drawLine`.)

- [ ] **Step 4: Run the test to verify it passes**

```bash
composer test
```

Expected: both tests PASS.

- [ ] **Step 5: Verify static analysers**

```bash
composer phpstan
vendor/bin/psalm
```

Expected: both pass.

- [ ] **Step 6: Review**

Use `superpowers:requesting-code-review`. Verify:
- Outline draws every consecutive pair plus the closing edge
- Outline is only drawn when `outlineColor !== null`
- Vertex rounding matches existing project style (round to int, not truncate)
- The placeholder comment from Task 2 has been replaced by the outline block

Fix any issues.

- [ ] **Step 7: Commit**

```bash
git add tests/Canvas/CanvasTest.php library/draw/Canvas.php
git commit -m "Add drawPolygon outline drawing"
```

---

## Task 4: Fill-only square test + scanline algorithm implementation

**Files:**
- Modify: `tests/Canvas/CanvasTest.php`
- Modify: `library/draw/Canvas.php`

- [ ] **Step 1: Add the failing test**

Append to `tests/Canvas/CanvasTest.php`:

```php
    public function test_draw_polygon_fill_only_square(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $fill = new Color(3, null);

        // Square (1,1)-(5,1)-(5,5)-(1,5)
        $canvas->drawPolygon(
            [[1.0, 1.0], [5.0, 1.0], [5.0, 5.0], [1.0, 5.0]],
            $fill,
            null
        );

        // Interior pixels must be filled.
        $this->assertSame(3, $canvas->data[2][2]->fg);
        $this->assertSame(3, $canvas->data[3][3]->fg);
        $this->assertSame(3, $canvas->data[4][2]->fg);
        $this->assertSame(3, $canvas->data[2][4]->fg);

        // Outside pixels must NOT be filled.
        $this->assertNull($canvas->data[0][0]->fg);
        $this->assertNull($canvas->data[6][6]->fg);
        $this->assertNull($canvas->data[9][9]->fg);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
composer test
```

Expected: FAIL. Interior pixels have null fg because fill is not implemented.

- [ ] **Step 3: Implement the scanline fill algorithm**

In `library/draw/Canvas.php`, restructure the body of `drawPolygon` (after the two early returns) so that the fill runs before the outline. The full method body (after the two early returns) becomes:

```php
        if ($fillColor !== null) {
            $this->fillPolygonScanline($points, $fillColor, $text);
        }

        if ($outlineColor !== null) {
            $firstX = null;
            $firstY = null;
            $prevX = null;
            $prevY = null;
            foreach ($points as $point) {
                $x = (int) round($point[0]);
                $y = (int) round($point[1]);
                if ($firstX === null) {
                    $firstX = $x;
                    $firstY = $y;
                } else {
                    $this->drawLine($prevX, $prevY, $x, $y, $outlineColor, $text);
                }
                $prevX = $x;
                $prevY = $y;
            }
            // Close the polygon: last vertex back to first.
            if ($firstX !== null && ($prevX !== $firstX || $prevY !== $firstY)) {
                $this->drawLine($prevX, $prevY, $firstX, $firstY, $outlineColor, $text);
            }
        }
```

Then add a new private method immediately after `drawPolygon`:

```php
    /**
     * Fill the interior of a polygon using scanline conversion with the
     * non-zero winding rule. Vertices must be in order around the polygon
     * (clockwise or counter-clockwise); the polygon is implicitly closed.
     *
     * Uses the half-open convention `min(y0, y1) <= Y < max(y0, y1)` so that
     * horizontal edges and vertices exactly on a scanline are handled
     * uniformly without double-counting.
     *
     * @param array<int, array{0: int|float, 1: int|float}> $points
     */
    private function fillPolygonScanline(array $points, Color $color, string $text): void
    {
        $n = count($points);

        // Compute integer bounding box of scanlines to visit.
        $minY = $points[0][1];
        $maxY = $points[0][1];
        for ($i = 1; $i < $n; $i++) {
            if ($points[$i][1] < $minY) {
                $minY = $points[$i][1];
            }
            if ($points[$i][1] > $maxY) {
                $maxY = $points[$i][1];
            }
        }
        $yStart = (int) floor($minY);
        $yEnd = (int) ceil($maxY);

        for ($Y = $yStart; $Y <= $yEnd; $Y++) {
            // Collect (xIntersection, windingDirection) for every edge
            // crossing this scanline under the half-open convention.
            $intersections = [];
            for ($i = 0; $i < $n; $i++) {
                $x1 = $points[$i][0];
                $y1 = $points[$i][1];
                $x2 = $points[($i + 1) % $n][0];
                $y2 = $points[($i + 1) % $n][1];

                $yLo = $y1 < $y2 ? $y1 : $y2;
                $yHi = $y1 < $y2 ? $y2 : $y1;

                // Half-open: include edge iff yLo <= Y < yHi.
                if ($Y < $yLo || $Y >= $yHi) {
                    continue;
                }

                // x at scanline Y by linear interpolation along the edge.
                $xInt = $x1 + ($x2 - $x1) * ($Y - $y1) / ($y2 - $y1);
                $dir = ($y2 > $y1) ? 1 : -1;
                $intersections[] = [$xInt, $dir];
            }

            // Sort by x so we can walk left-to-right.
            usort($intersections, fn ($a, $b) => $a[0] <=> $b[0]);

            // Walk intersections, tracking running winding count.
            // A fill span opens when winding becomes non-zero and closes
            // when it returns to zero.
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

- [ ] **Step 4: Run the test to verify it passes**

```bash
composer test
```

Expected: all three tests PASS.

- [ ] **Step 5: Verify static analysers**

```bash
composer phpstan
vendor/bin/psalm
```

Expected: both pass.

- [ ] **Step 6: Review**

Use `superpowers:requesting-code-review`. Verify:
- Half-open convention is exactly `min(y0,y1) <= Y < max(y0,y1)` (NOT `<=` on both ends)
- Direction sign matches the inequality direction used for the half-open test
- Span opens/closes on winding-zero crossings only (not on every intersection)
- `ceil(xLeft)` to `floor(xRight)` inclusive keeps fill inside the geometric boundary
- `drawPoint` bounds check protects against out-of-canvas writes
- Docblock describes both the algorithm and the half-open convention

Fix any issues.

- [ ] **Step 7: Commit**

```bash
git add tests/Canvas/CanvasTest.php library/draw/Canvas.php
git commit -m "Add scanline polygon fill with non-zero winding rule"
```

---

## Task 5: Fill + outline combined test

**Files:**
- Modify: `tests/Canvas/CanvasTest.php`

This task is verification-only: the combined behavior was implemented in Tasks 3 and 4. The test confirms the fill-then-outline ordering and that outline pixels cover fill pixels at the boundary.

- [ ] **Step 1: Add the test**

Append to `tests/Canvas/CanvasTest.php`:

```php
    public function test_draw_polygon_fill_plus_outline_square(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $fill = new Color(3, null);
        $outline = new Color(5, null);

        // Square (1,1)-(5,1)-(5,5)-(1,5)
        $canvas->drawPolygon(
            [[1.0, 1.0], [5.0, 1.0], [5.0, 5.0], [1.0, 5.0]],
            $fill,
            $outline
        );

        // Corners are on the outline, so they must show the outline color
        // (outline is drawn on top of fill).
        $this->assertSame(5, $canvas->data[1][1]->fg);
        $this->assertSame(5, $canvas->data[5][1]->fg);
        $this->assertSame(5, $canvas->data[5][5]->fg);
        $this->assertSame(5, $canvas->data[1][5]->fg);

        // Interior pixels are pure fill.
        $this->assertSame(3, $canvas->data[2][2]->fg);
        $this->assertSame(3, $canvas->data[3][3]->fg);

        // Outside pixels are untouched.
        $this->assertNull($canvas->data[0][0]->fg);
        $this->assertNull($canvas->data[6][6]->fg);
    }
```

- [ ] **Step 2: Run the test to verify it passes**

```bash
composer test
```

Expected: all four tests PASS.

- [ ] **Step 3: Verify static analysers**

```bash
composer phpstan
vendor/bin/psalm
```

Expected: both pass.

- [ ] **Step 4: Review**

Use `superpowers:requesting-code-review`. Verify the test exercises both colors and that outline-wins-at-boundary is actually asserted (not just fill OR outline).

Fix any issues.

- [ ] **Step 5: Commit**

```bash
git add tests/Canvas/CanvasTest.php
git commit -m "Add drawPolygon fill-plus-outline integration test"
```

---

## Task 6: Degenerate-polygon edge case tests

**Files:**
- Modify: `tests/Canvas/CanvasTest.php`

These tests verify the early-return guards for `< 3 vertices` and the bounds safety for polygons outside the canvas (which relies on `drawPoint`'s existing `isset` check).

- [ ] **Step 1: Add the tests**

Append to `tests/Canvas/CanvasTest.php`:

```php
    public function test_draw_polygon_with_two_vertices_is_noop(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $color = new Color(5, null);

        $canvas->drawPolygon([[1.0, 1.0], [5.0, 5.0]], $color, $color);

        for ($y = 0; $y < 10; $y++) {
            for ($x = 0; $x < 10; $x++) {
                $this->assertNull($canvas->data[$y][$x]->fg);
            }
        }
    }

    public function test_draw_polygon_with_one_vertex_is_noop(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $color = new Color(5, null);

        $canvas->drawPolygon([[5.0, 5.0]], $color, $color);

        for ($y = 0; $y < 10; $y++) {
            for ($x = 0; $x < 10; $x++) {
                $this->assertNull($canvas->data[$y][$x]->fg);
            }
        }
    }

    public function test_draw_polygon_with_zero_vertices_is_noop(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $color = new Color(5, null);

        $canvas->drawPolygon([], $color, $color);

        for ($y = 0; $y < 10; $y++) {
            for ($x = 0; $x < 10; $x++) {
                $this->assertNull($canvas->data[$y][$x]->fg);
            }
        }
    }

    public function test_draw_polygon_fully_outside_canvas_does_not_throw(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $color = new Color(5, null);

        // Polygon entirely up-and-left of the canvas.
        $canvas->drawPolygon(
            [[-20.0, -20.0], [-10.0, -20.0], [-10.0, -10.0], [-20.0, -10.0]],
            $color,
            $color
        );

        // Polygon entirely down-and-right of the canvas.
        $canvas->drawPolygon(
            [[50.0, 50.0], [60.0, 50.0], [60.0, 60.0], [50.0, 60.0]],
            $color,
            $color
        );

        // Canvas must be untouched (drawPoint's isset check rejected every write).
        for ($y = 0; $y < 10; $y++) {
            for ($x = 0; $x < 10; $x++) {
                $this->assertNull($canvas->data[$y][$x]->fg);
            }
        }
    }
```

- [ ] **Step 2: Run the tests to verify they pass**

```bash
composer test
```

Expected: all eight tests PASS. If any fail, the implementation has a guard-rail bug — fix it before proceeding.

- [ ] **Step 3: Verify static analysers**

```bash
composer phpstan
vendor/bin/psalm
```

Expected: both pass.

- [ ] **Step 4: Review**

Use `superpowers:requesting-code-review`. Verify the tests exercise both fill and outline paths for the out-of-bounds case (so both `drawPoint` from fill AND `drawLine` from outline are stress-tested for bounds safety).

Fix any issues.

- [ ] **Step 5: Commit**

```bash
git add tests/Canvas/CanvasTest.php
git commit -m "Add drawPolygon degenerate-polygon edge case tests"
```

---

## Task 7: Stars corner-stranding regression test

**Files:**
- Modify: `tests/Canvas/CanvasTest.php`

This is the regression test for the original bug. It uses an independent pure-PHP winding-number oracle to assert that no pixel the oracle considers "inside" the polygon is left unfilled. The oracle is written inline in the test file.

- [ ] **Step 1: Add the regression test and oracle helpers**

Append to `tests/Canvas/CanvasTest.php`:

```php
    /**
     * Regression test for the @stars corner-stranding bug. Previously the
     * stars command drew the outline with drawLine then flood-filled from
     * the centroid; at sharp corners the rasterized lines did not form a
     * 4-connected seal, so the flood fill correctly left corner pixels
     * unfilled even though they are geometrically inside the polygon.
     *
     * This test uses drawPolygon (scanline fill with non-zero winding rule)
     * and an independent winding-number point-in-polygon oracle to verify
     * that every pixel the oracle considers inside the polygon is colored.
     */
    public function test_draw_polygon_star_corner_stranding_regression(): void
    {
        // Deterministic 5-pointed star (matches the math in stars() but with
        // fixed rotation, fixed radius, fixed center).
        $cx = 40.0;
        $cy = 24.0;
        $radius = 20.0;
        $rot = 0.0;
        $alpha = (2.0 * M_PI) / 10.0;
        $points = [];
        for ($p = 11; $p != 0; $p--) {
            $omega = ($alpha * $p) + $rot;
            $r = $radius * (($p % 2) + 1) / 2.0;
            $points[] = [$r * sin($omega) + $cx, $r * cos($omega) + $cy];
        }

        $canvas = Canvas::createBlank(80, 48, true);
        $fill = new Color(7, null);
        $outline = new Color(4, null);
        $canvas->drawPolygon($points, $fill, $outline);

        // Bounding box of the star.
        $xs = array_column($points, 0);
        $ys = array_column($points, 1);
        $minX = (int) floor(min($xs));
        $maxX = (int) ceil(max($xs));
        $minY = (int) floor(min($ys));
        $maxY = (int) ceil(max($ys));

        // For every pixel in the bounding box, if the winding oracle says
        // the pixel center is inside the polygon, the canvas must show
        // either the fill color or the outline color.
        for ($y = $minY; $y <= $maxY; $y++) {
            for ($x = $minX; $x <= $maxX; $x++) {
                $winding = $this->windingAt($x + 0.5, $y + 0.5, $points);
                if ($winding === 0) {
                    continue;
                }
                $fg = $canvas->data[$y][$x]->fg;
                $this->assertNotNull(
                    $fg,
                    "Pixel ($x, $y) is inside polygon (winding=$winding) but is unfilled"
                );
                $this->assertContains(
                    $fg,
                    [7, 4],
                    "Pixel ($x, $y) is inside polygon but has unexpected fg=$fg"
                );
            }
        }
    }

    /**
     * Pure-PHP winding-number point-in-polygon test, independent of the
     * Canvas implementation under test. Returns non-zero winding iff the
     * point is inside the polygon under the non-zero winding rule.
     */
    private function windingAt(float $px, float $py, array $points): int
    {
        $n = count($points);
        $winding = 0;
        for ($i = 0; $i < $n; $i++) {
            [$x1, $y1] = $points[$i];
            [$x2, $y2] = $points[($i + 1) % $n];
            if ($y1 <= $py) {
                if ($y2 > $py) {
                    if ($this->isLeft($px, $py, $x1, $y1, $x2, $y2) > 0) {
                        $winding++;
                    }
                }
            } else {
                if ($y2 <= $py) {
                    if ($this->isLeft($px, $py, $x1, $y1, $x2, $y2) < 0) {
                        $winding--;
                    }
                }
            }
        }
        return $winding;
    }

    private function isLeft(float $px, float $py, float $x1, float $y1, float $x2, float $y2): float
    {
        return ($x2 - $x1) * ($py - $y1) - ($px - $x1) * ($y2 - $y1);
    }
```

- [ ] **Step 2: Run the test to verify it passes**

```bash
composer test
```

Expected: all tests PASS, including the new regression test. If the regression test fails, the algorithm has a bug — DO NOT proceed to refactor `stars()` until this passes.

- [ ] **Step 3: Verify static analysers**

```bash
composer phpstan
vendor/bin/psalm
```

Expected: both pass.

- [ ] **Step 4: Review**

Use `superpowers:requesting-code-review`. Verify:
- The oracle (`windingAt` + `isLeft`) is the standard textbook winding-number algorithm
- The oracle is independent of `drawPolygon` (does not call into `Canvas`)
- The point tested is the pixel *center* (`$x + 0.5, $y + 0.5`), not the corner
- The star coordinates use the same trig math as `stars()` (so this actually catches the original bug)
- The test asserts both "filled" AND "with one of fill-or-outline color" (catches wrong-color bugs too)

Fix any issues.

- [ ] **Step 5: Commit**

```bash
git add tests/Canvas/CanvasTest.php
git commit -m "Add stars corner-stranding regression test"
```

---

## Task 8: Refactor stars command to use drawPolygon

**Files:**
- Modify: `artbot_scripts/drawing.php:121-170` (the `stars` function body)

- [ ] **Step 1: Refactor the stars function**

Open `artbot_scripts/drawing.php`. Replace the body of the `stars` function (the `for ($i = 0; $i < $numstars; $i++)` loop, lines roughly 136-167) so that each star uses `drawPolygon` instead of manual line-drawing plus flood fill.

The replacement loop body:

```php
    for ($i = 0; $i < $numstars; $i++) {
        $tart = Canvas::createBlank(80, $lines, true);
        $fillColor = new Color($fgs[array_rand($fgs)], null);
        $outlineColor = new Color($fgs[array_rand($fgs)], null);
        $alpha = (2 * M_PI) / 10;
        $radius = random_int(7, 25);
        $x = random_int(0, 80);
        $y = random_int(0, $lines);
        $points = [];
        $rot = deg2rad(random_int(0, intval(360 / 5)));
        for ($p = 11; $p != 0; $p--) {
            $omega = ($alpha * $p) + $rot;
            $r = $radius * ($p % 2 + 1) / 2;
            $points[] = [$r * sin($omega) + $x, $r * cos($omega) + $y];
        }

        $willFill = random_int(0, 4) > 1;
        $tart->drawPolygon(
            $points,
            $willFill ? $fillColor : null,
            $outlineColor
        );
        $art->overlay($tart);
    }
```

Do NOT change anything outside the per-star loop body. The `$numstars` calculation, the outer canvas creation, the `fillColor(0, 0, ...)` background call, and the `pumpToChan` line must remain untouched.

- [ ] **Step 2: Verify static analysers**

```bash
composer phpstan
vendor/bin/psalm
```

Expected: both pass.

- [ ] **Step 3: Run all tests**

```bash
composer test
```

Expected: all tests pass.

- [ ] **Step 4: Format the code**

```bash
vendor/bin/php-cs-fixer fix artbot_scripts/drawing.php
```

- [ ] **Step 5: Review**

Use `superpowers:requesting-code-review`. Verify:
- Per-star loop body is the ONLY thing changed in `stars()`
- The 60% fill chance (`random_int(0, 4) > 1`) matches the original `if (random_int(0, 4) > 1)` condition
- `$fillColor` and `$outlineColor` are still picked independently per star (matches original behavior)
- Vertex math (`$alpha`, `$radius`, `$omega`, `$r`) is byte-for-byte unchanged from the original
- `drawPolygon` is called with `(points, fillColorOrNull, outlineColor)` matching the new API signature
- `overlay` call is preserved

Fix any issues.

- [ ] **Step 6: Manual smoke test (optional but recommended)**

If you can run the bot locally:

```bash
php artbots.php
```

Then in a test channel that the art bot is in:

```
@stars
@stars --lines 100
```

Visually confirm that star corners are fully filled (no notch artifacts). If you cannot run the bot locally, skip this step and note it in the commit message.

- [ ] **Step 7: Commit**

```bash
git add artbot_scripts/drawing.php
git commit -m "Refactor stars command to use drawPolygon"
```

---

## Self-review (post-implementation)

After all eight tasks are complete, run the full verification suite one more time:

```bash
composer test
composer phpstan
vendor/bin/psalm
```

Then check spec coverage against `docs/superpowers/specs/2026-06-01-drawpolygon-winding-fill-design.md`:

- [x] `Canvas::drawPolygon` API (signature, nullable colors, ordering) → Tasks 2–4
- [x] Non-zero winding rule scanline fill → Task 4
- [x] Half-open convention edge cases (horizontal edges, vertex on scanline) → Task 4 + verified by Task 7
- [x] Pixel snapping (`ceil`/`floor` for fill, `round` for outline) → Task 4 + Task 3
- [x] `< 3 vertices` early return → Task 2 + verified by Task 6
- [x] Out-of-bounds safety → verified by Task 6
- [x] PHPUnit setup (composer, phpunit.xml, autoload-dev, AGENTS.md, phpstan/psalm) → Task 1
- [x] Square fill+outline / fill-only / outline-only tests → Tasks 3, 4, 5
- [x] Degenerate polygon tests → Task 6
- [x] Stars corner-stranding regression test with winding oracle → Task 7
- [x] Stars command refactor → Task 8
- [x] Existing `fillColor` unchanged → not touched by any task ✓
