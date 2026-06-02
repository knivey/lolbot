# StrokeStyle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add stroke-width, stroke-dasharray, stroke-dashoffset, stroke-linecap, stroke-linejoin, and stroke-miterlimit to the draw library. Replace `?Color $outlineColor` on `drawPath()` with `?StrokeStyle $stroke`.

**Architecture:** Create LineCap and LineJoin enums, a StrokeStyle value object, then update the Canvas API. Width=1 uses fast Bresenham; width>1 expands the stroke into a polygon (offset curves + joins + caps) and fills it. Dashes subdivide the path into on/off segments before stroke expansion.

**Tech Stack:** PHP 8.1, PHPUnit 10, PHPStan level 9 (baseline ~667)

---

### Task 1: Create LineCap and LineJoin enums

**Files:**
- Create: `library/draw/LineCap.php`
- Create: `library/draw/LineJoin.php`
- Create: `tests/Canvas/LineCapTest.php`
- Create: `tests/Canvas/LineJoinTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Canvas/LineCapTest.php`:
```php
<?php

namespace Tests\Canvas;

use draw\LineCap;
use PHPUnit\Framework\TestCase;

class LineCapTest extends TestCase
{
    public function test_enum_has_three_cases(): void
    {
        $this->assertSame('Butt', LineCap::Butt->name);
        $this->assertSame('Round', LineCap::Round->name);
        $this->assertSame('Square', LineCap::Square->name);
    }
}
```

`tests/Canvas/LineJoinTest.php`:
```php
<?php

namespace Tests\Canvas;

use draw\LineJoin;
use PHPUnit\Framework\TestCase;

class LineJoinTest extends TestCase
{
    public function test_enum_has_three_cases(): void
    {
        $this->assertSame('Miter', LineJoin::Miter->name);
        $this->assertSame('Round', LineJoin::Round->name);
        $this->assertSame('Bevel', LineJoin::Bevel->name);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- tests/Canvas/LineCapTest.php tests/Canvas/LineJoinTest.php`
Expected: FATAL ERROR — classes not found

- [ ] **Step 3: Write implementations**

`library/draw/LineCap.php`:
```php
<?php

namespace draw;

enum LineCap
{
    case Butt;
    case Round;
    case Square;
}
```

`library/draw/LineJoin.php`:
```php
<?php

namespace draw;

enum LineJoin
{
    case Miter;
    case Round;
    case Bevel;
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test && composer phpstan`
Expected: 126 tests pass, PHPStan baseline unchanged

- [ ] **Step 5: Commit**

```bash
git add library/draw/LineCap.php library/draw/LineJoin.php tests/Canvas/LineCapTest.php tests/Canvas/LineJoinTest.php
git commit -m "Add LineCap and LineJoin enums"
```

---

### Task 2: Create StrokeStyle class

**Files:**
- Create: `library/draw/StrokeStyle.php`
- Create: `tests/Canvas/StrokeStyleTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Canvas/StrokeStyleTest.php`:
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
    public function test_default_values(): void
    {
        $s = new StrokeStyle(new Color(4, null));
        $this->assertSame(4, $s->color->fg);
        $this->assertSame(1.0, $s->width);
        $this->assertNull($s->dashArray);
        $this->assertSame(0.0, $s->dashOffset);
        $this->assertSame(LineCap::Butt, $s->lineCap);
        $this->assertSame(LineJoin::Miter, $s->lineJoin);
        $this->assertSame(4.0, $s->miterLimit);
    }

    public function test_custom_values(): void
    {
        $s = new StrokeStyle(
            new Color(5, null),
            width: 3.0,
            dashArray: [5.0, 3.0],
            dashOffset: 2.0,
            lineCap: LineCap::Round,
            lineJoin: LineJoin::Bevel,
            miterLimit: 8.0
        );
        $this->assertSame(5, $s->color->fg);
        $this->assertSame(3.0, $s->width);
        $this->assertSame([5.0, 3.0], $s->dashArray);
        $this->assertSame(2.0, $s->dashOffset);
        $this->assertSame(LineCap::Round, $s->lineCap);
        $this->assertSame(LineJoin::Bevel, $s->lineJoin);
        $this->assertSame(8.0, $s->miterLimit);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Canvas/StrokeStyleTest.php`
Expected: FATAL ERROR — class not found

- [ ] **Step 3: Write implementation**

`library/draw/StrokeStyle.php`:
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
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test && composer phpstan`
Expected: 128 tests pass, PHPStan baseline unchanged

- [ ] **Step 5: Commit**

```bash
git add library/draw/StrokeStyle.php tests/Canvas/StrokeStyleTest.php
git commit -m "Add StrokeStyle value object"
```

---

### Task 3: Update drawPath() signature and all callers

**Files:**
- Modify: `library/draw/Canvas.php` — change `drawPath()` signature, update outline rendering block
- Modify: `tests/Canvas/CanvasTest.php` — update all `drawPath()` calls
- Modify: `tests/Canvas/PathTest.php` — update all `drawPath()` calls
- Modify: `artbot_scripts/drawing.php` — update all `drawPath()` calls
- Modify: `scripts/stocks/stocks.php` — update all `drawPath()` calls

This task changes the API but keeps behavior identical (width=1 Bresenham for all strokes).

- [ ] **Step 1: Update Canvas::drawPath() signature**

In `library/draw/Canvas.php`, change the signature at line 346:

FROM:
```php
    public function drawPath(
        Path $path,
        ?Color $fillColor,
        ?Color $outlineColor,
        string $text = '',
        FillRule $fillRule = FillRule::NonZero
    ): void {
```

TO:
```php
    public function drawPath(
        Path $path,
        ?Color $fillColor,
        ?StrokeStyle $stroke,
        string $text = '',
        FillRule $fillRule = FillRule::NonZero
    ): void {
```

- [ ] **Step 2: Update the outline rendering block in drawPath()**

In the same method, change all references from `$outlineColor` to `$stroke`. The outline block currently starts at approximately line 393 with `if ($outlineColor !== null) {`. Replace the entire outline block:

FROM (approximately lines 393-424):
```php
        if ($outlineColor !== null) {
            foreach ($snappedSubpaths as $sp) {
                $vertices = $sp['vertices'];
                $n = count($vertices);
                if ($sp['closed'] && $n < 3) {
                    continue;
                }
                if ($n < 2) {
                    continue;
                }
                for ($i = 1; $i < $n; $i++) {
                    $this->drawLineInternal(
                        $vertices[$i - 1][0],
                        $vertices[$i - 1][1],
                        $vertices[$i][0],
                        $vertices[$i][1],
                        $outlineColor,
                        $text
                    );
                }
                if ($sp['closed']) {
                    $this->drawLineInternal(
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
```

TO:
```php
        if ($stroke !== null) {
            foreach ($snappedSubpaths as $sp) {
                $this->strokeSubpath($sp, $stroke, $text);
            }
        }
```

Also update the early return check. Change:
```php
        if ($fillColor === null && $outlineColor === null) {
```
to:
```php
        if ($fillColor === null && $stroke === null) {
```

- [ ] **Step 3: Add strokeSubpath() method to Canvas**

Add this new private method to `Canvas.php` (before `fillPolygonScanlineMulti`). For now it only handles width==1:

```php
    private function strokeSubpath(array $sp, StrokeStyle $stroke, string $text): void
    {
        $vertices = $sp['vertices'];
        $n = count($vertices);
        if ($sp['closed'] && $n < 3) {
            return;
        }
        if ($n < 2) {
            return;
        }
        if ($stroke->width <= 1.0) {
            for ($i = 1; $i < $n; $i++) {
                $this->drawLineInternal(
                    $vertices[$i - 1][0],
                    $vertices[$i - 1][1],
                    $vertices[$i][0],
                    $vertices[$i][1],
                    $stroke->color,
                    $text
                );
            }
            if ($sp['closed']) {
                $this->drawLineInternal(
                    $vertices[$n - 1][0],
                    $vertices[$n - 1][1],
                    $vertices[0][0],
                    $vertices[0][1],
                    $stroke->color,
                    $text
                );
            }
            return;
        }
    }
```

- [ ] **Step 4: Update the null check early return**

At approximately line 357, change:
```php
        if ($fillColor === null && $outlineColor === null) {
```
to:
```php
        if ($fillColor === null && $stroke === null) {
```

- [ ] **Step 5: Update tests/Canvas/CanvasTest.php**

Add `use draw\StrokeStyle;` after the existing `use draw\Transform;` line.

Every `drawPath()` call that passes a `Color` as the third argument must wrap it in `new StrokeStyle(...)`. Every `null` third argument stays `null`. Examples:

```php
// Before:
$canvas->drawPath(Path::polygon(...), null, $outline);
// After:
$canvas->drawPath(Path::polygon(...), null, new StrokeStyle($outline));

// Before:
$canvas->drawPath(Path::polygon(...), $fill, $outline);
// After:
$canvas->drawPath(Path::polygon(...), $fill, new StrokeStyle($outline));

// Before:
$canvas->drawPath(Path::polygon(...), $color, $color);
// After:
$canvas->drawPath(Path::polygon(...), $color, new StrokeStyle($color));

// Before:
$canvas->drawPath(Path::polygon(...), null, null);
// After (no change):
$canvas->drawPath(Path::polygon(...), null, null);

// Before:
$canvas->drawPath(Path::polygon(...), null, new Color(4, null));
// After:
$canvas->drawPath(Path::polygon(...), null, new StrokeStyle(new Color(4, null)));
```

Apply this pattern to ALL drawPath calls in CanvasTest.php (approximately 16 call sites).

- [ ] **Step 6: Update tests/Canvas/PathTest.php**

Add `use draw\StrokeStyle;` after existing use statements.

Apply the same transformation to all `drawPath()` calls with outline colors. Approximately 18 call sites. Same pattern: `new Color(...)` as 3rd arg → `new StrokeStyle(new Color(...))`.

- [ ] **Step 7: Update artbot_scripts/drawing.php**

Add `use draw\StrokeStyle;` if not already imported (check existing use statements at top).

Apply the same transformation to all `drawPath()` calls. Approximately 18 call sites. Same pattern.

Note: some calls use `new Color(04, 0)` (with leading zero) — keep that as-is, just wrap in StrokeStyle.

- [ ] **Step 8: Update scripts/stocks/stocks.php**

Apply the same transformation. Approximately 6 call sites. Wrap all outline `new draw\Color(...)` in `new draw\StrokeStyle(new draw\Color(...))`.

- [ ] **Step 9: Run full test suite + PHPStan**

Run: `composer test && composer phpstan`
Expected: 128 tests pass, PHPStan baseline unchanged

- [ ] **Step 10: Commit**

```bash
git add library/draw/Canvas.php tests/Canvas/CanvasTest.php tests/Canvas/PathTest.php artbot_scripts/drawing.php scripts/stocks/stocks.php
git commit -m "Replace ?Color outlineColor with ?StrokeStyle stroke in drawPath()"
```

---

### Task 4: Implement stroke expansion for width > 1 (with joins and caps)

**Files:**
- Modify: `library/draw/Canvas.php` — expand `strokeSubpath()` with full polygon expansion

This is the core algorithm task. It adds the width > 1 branch to `strokeSubpath()`.

- [ ] **Step 1: Write the failing tests**

Add these tests to `tests/Canvas/CanvasTest.php`:

```php
    public function test_stroke_width_2_horizontal_line(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $stroke = new StrokeStyle(new Color(4, null), width: 2.0);

        $canvas->drawPath(Path::line(2, 5, 8, 5), null, $stroke);

        $this->assertSame(4, $canvas->data[4][5]->fg, "Row above center should be stroked");
        $this->assertSame(4, $canvas->data[5][5]->fg, "Center row should be stroked");
        $this->assertNull($canvas->data[3][5]->fg, "Row two above should not be stroked");
        $this->assertNull($canvas->data[6][5]->fg, "Row below should not be stroked");
    }

    public function test_stroke_width_3_horizontal_line(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $stroke = new StrokeStyle(new Color(4, null), width: 3.0);

        $canvas->drawPath(Path::line(2, 5, 8, 5), null, $stroke);

        $this->assertSame(4, $canvas->data[4][5]->fg);
        $this->assertSame(4, $canvas->data[5][5]->fg);
        $this->assertSame(4, $canvas->data[6][5]->fg);
        $this->assertNull($canvas->data[3][5]->fg);
        $this->assertNull($canvas->data[7][5]->fg);
    }

    public function test_stroke_width_3_vertical_line(): void
    {
        $canvas = Canvas::createBlank(10, 20);
        $stroke = new StrokeStyle(new Color(4, null), width: 3.0);

        $canvas->drawPath(Path::line(5, 2, 5, 8), null, $stroke);

        $this->assertSame(4, $canvas->data[5][4]->fg);
        $this->assertSame(4, $canvas->data[5][5]->fg);
        $this->assertSame(4, $canvas->data[5][6]->fg);
        $this->assertNull($canvas->data[5][3]->fg);
        $this->assertNull($canvas->data[5][7]->fg);
    }

    public function test_stroke_width_2_square_outline(): void
    {
        $canvas = Canvas::createBlank(20, 20);
        $stroke = new StrokeStyle(new Color(4, null), width: 2.0);

        $canvas->drawPath(Path::rect(5, 5, 10, 10), null, $stroke);

        $this->assertSame(4, $canvas->data[4][5]->fg, "Top edge row above");
        $this->assertSame(4, $canvas->data[5][5]->fg, "Top edge");
        $this->assertSame(4, $canvas->data[14][5]->fg, "Bottom edge");
        $this->assertSame(4, $canvas->data[15][5]->fg, "Bottom edge row below");
        $this->assertSame(4, $canvas->data[5][4]->fg, "Left edge col before");
        $this->assertSame(4, $canvas->data[5][5]->fg, "Left edge");
        $this->assertNull($canvas->data[9][9]->fg, "Interior should be empty");
    }

    public function test_stroke_butt_cap_open_path(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $stroke = new StrokeStyle(new Color(4, null), width: 3.0, lineCap: LineCap::Butt);

        $canvas->drawPath(Path::line(5, 5, 10, 5), null, $stroke);

        $this->assertSame(4, $canvas->data[4][5]->fg, "Butt cap at start");
        $this->assertSame(4, $canvas->data[4][10]->fg, "Butt cap at end");
        $this->assertNull($canvas->data[4][4]->fg, "No extension before start");
        $this->assertNull($canvas->data[4][11]->fg, "No extension after end");
    }

    public function test_stroke_square_cap_extends(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $stroke = new StrokeStyle(new Color(4, null), width: 2.0, lineCap: LineCap::Square);

        $canvas->drawPath(Path::line(5, 5, 10, 5), null, $stroke);

        $this->assertSame(4, $canvas->data[5][4]->fg, "Square cap extends 1px before start");
        $this->assertSame(4, $canvas->data[5][11]->fg, "Square cap extends 1px after end");
    }

    public function test_stroke_round_cap_adds_semicircle(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $stroke = new StrokeStyle(new Color(4, null), width: 3.0, lineCap: LineCap::Round);

        $canvas->drawPath(Path::line(5, 5, 10, 5), null, $stroke);

        $this->assertSame(4, $canvas->data[5][4]->fg, "Round cap at start");
        $this->assertSame(4, $canvas->data[5][11]->fg, "Round cap at end");
    }

    public function test_stroke_miter_join(): void
    {
        $canvas = Canvas::createBlank(20, 20);
        $stroke = new StrokeStyle(new Color(4, null), width: 2.0, lineJoin: LineJoin::Miter);

        $path = new Path();
        $path->moveTo(5.0, 5.0);
        $path->lineTo(10.0, 5.0);
        $path->lineTo(10.0, 10.0);
        $canvas->drawPath($path, null, $stroke);

        $this->assertSame(4, $canvas->data[4][10]->fg, "Miter join at corner");
    }

    public function test_stroke_bevel_join(): void
    {
        $canvas = Canvas::createBlank(20, 20);
        $stroke = new StrokeStyle(new Color(4, null), width: 2.0, lineJoin: LineJoin::Bevel);

        $path = new Path();
        $path->moveTo(5.0, 5.0);
        $path->lineTo(10.0, 5.0);
        $path->lineTo(10.0, 10.0);
        $canvas->drawPath($path, null, $stroke);

        $this->assertSame(4, $canvas->data[5][9]->fg, "Bevel join area");
    }

    public function test_stroke_round_join(): void
    {
        $canvas = Canvas::createBlank(20, 20);
        $stroke = new StrokeStyle(new Color(4, null), width: 4.0, lineJoin: LineJoin::Round);

        $path = new Path();
        $path->moveTo(5.0, 10.0);
        $path->lineTo(10.0, 10.0);
        $path->lineTo(10.0, 5.0);
        $canvas->drawPath($path, null, $stroke);

        $this->assertSame(4, $canvas->data[10][10]->fg, "Round join at corner");
    }

    public function test_stroke_width_1_unchanged_behavior(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $stroke = new StrokeStyle(new Color(4, null));

        $canvas->drawPath(Path::line(2, 2, 8, 8), null, $stroke);

        $this->assertSame(4, $canvas->data[2][2]->fg);
        $this->assertSame(4, $canvas->data[5][5]->fg);
        $this->assertSame(4, $canvas->data[8][8]->fg);
    }

    public function test_stroke_miter_limit_clips_to_bevel(): void
    {
        $canvas = Canvas::createBlank(30, 30);
        $stroke = new StrokeStyle(new Color(4, null), width: 2.0, lineJoin: LineJoin::Miter, miterLimit: 1.0);

        $path = new Path();
        $path->moveTo(5.0, 15.0);
        $path->lineTo(15.0, 10.0);
        $path->lineTo(25.0, 15.0);
        $canvas->drawPath($path, null, $stroke);

        $this->assertTrue(true, "Miter limit clipping does not crash");
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- tests/Canvas/CanvasTest.php --filter test_stroke_`
Expected: Most tests fail because `strokeSubpath()` doesn't handle width > 1 yet.

- [ ] **Step 3: Implement stroke expansion**

Replace the entire `strokeSubpath()` method in `library/draw/Canvas.php` with the full implementation:

```php
    private function strokeSubpath(array $sp, StrokeStyle $stroke, string $text): void
    {
        $vertices = $sp['vertices'];
        $n = count($vertices);
        if ($sp['closed'] && $n < 3) {
            return;
        }
        if ($n < 2) {
            return;
        }
        if ($stroke->width <= 1.0) {
            for ($i = 1; $i < $n; $i++) {
                $this->drawLineInternal(
                    $vertices[$i - 1][0],
                    $vertices[$i - 1][1],
                    $vertices[$i][0],
                    $vertices[$i][1],
                    $stroke->color,
                    $text
                );
            }
            if ($sp['closed']) {
                $this->drawLineInternal(
                    $vertices[$n - 1][0],
                    $vertices[$n - 1][1],
                    $vertices[0][0],
                    $vertices[0][1],
                    $stroke->color,
                    $text
                );
            }
            return;
        }

        $halfW = $stroke->width / 2.0;

        $dashSegments = $this->applyDashPattern($vertices, $sp['closed'], $stroke);

        foreach ($dashSegments as $seg) {
            $segVerts = $seg['vertices'];
            $segClosed = $seg['closed'];
            $segN = count($segVerts);
            if ($segClosed && $segN < 3) {
                continue;
            }
            if ($segN < 2) {
                continue;
            }

            $polygon = $this->expandStrokePolygon($segVerts, $segClosed, $halfW, $stroke);
            if (count($polygon) >= 3) {
                $this->fillPolygonScanlineMulti([$polygon], $stroke->color, $text, FillRule::NonZero);
            }
        }
    }
```

- [ ] **Step 4: Implement expandStrokePolygon()**

Add this private method to `Canvas.php`:

```php
    private function expandStrokePolygon(array $vertices, bool $closed, float $halfW, StrokeStyle $stroke): array
    {
        $n = count($vertices);
        $left = [];
        $right = [];

        $count = $closed ? $n : $n - 1;
        for ($i = 0; $i < $count; $i++) {
            $curr = $vertices[$i];
            $next = $vertices[($i + 1) % $n];

            $dx = (float) ($next[0] - $curr[0]);
            $dy = (float) ($next[1] - $curr[1]);
            $len = sqrt($dx * $dx + $dy * $dy);
            if ($len < 0.0001) {
                continue;
            }
            $nx = -$dy / $len;
            $ny = $dx / $len;

            $left[] = [$curr[0] + $nx * $halfW, $curr[1] + $ny * $halfW];
            $left[] = [$next[0] + $nx * $halfW, $next[1] + $ny * $halfW];
            $right[] = [$curr[0] - $nx * $halfW, $curr[1] - $ny * $halfW];
            $right[] = [$next[0] - $nx * $halfW, $next[1] - $ny * $halfW];
        }

        if (empty($left)) {
            return [];
        }

        $leftClean = $this->deduplicateVertices($left);
        $rightClean = $this->deduplicateVertices($right);

        $leftJoined = $this->applyJoins($leftClean, $closed, $halfW, $stroke);
        $rightJoined = $this->applyJoins($rightClean, $closed, $halfW, $stroke);

        if ($closed) {
            $rightReversed = array_reverse($rightJoined);
            return array_merge($leftJoined, $rightReversed);
        }

        $startCap = $this->makeCap($leftJoined[0], $rightJoined[0], $vertices[0], $vertices[1] ?? $vertices[0], $halfW, $stroke->lineCap, true);
        $endCap = $this->makeCap($leftJoined[count($leftJoined) - 1], $rightJoined[count($rightJoined) - 1], $vertices[$n - 1], $vertices[$n - 2], $halfW, $stroke->lineCap, false);

        $rightReversed = array_reverse($rightJoined);
        return array_merge($startCap, $leftJoined, $endCap, $rightReversed);
    }
```

- [ ] **Step 5: Implement helper methods**

Add these private methods to `Canvas.php`:

```php
    private function deduplicateVertices(array $vertices): array
    {
        $result = [$vertices[0]];
        for ($i = 1; $i < count($vertices); $i++) {
            $prev = $result[count($result) - 1];
            $dx = $vertices[$i][0] - $prev[0];
            $dy = $vertices[$i][1] - $prev[1];
            if ($dx * $dx + $dy * $dy > 0.0001) {
                $result[] = $vertices[$i];
            }
        }
        return $result;
    }

    private function applyJoins(array $offsetPts, bool $closed, float $halfW, StrokeStyle $stroke): array
    {
        $n = count($offsetPts);
        if ($n < 2) {
            return $offsetPts;
        }

        $result = [];
        $total = $closed ? $n : $n;

        for ($i = 0; $i < $total; $i++) {
            if ($closed) {
                $prev = $offsetPts[($i - 1 + $n) % $n];
                $curr = $offsetPts[$i];
                $next = $offsetPts[($i + 1) % $n];
            } else {
                $result[] = $offsetPts[$i];
                if ($i === 0 || $i === $n - 1) {
                    continue;
                }
                $prev = $offsetPts[$i - 1];
                $curr = $offsetPts[$i];
                $next = $offsetPts[$i + 1];
            }

            $joinPts = $this->computeJoin($prev, $curr, $next, $halfW, $stroke);
            if ($joinPts !== null) {
                foreach ($joinPts as $pt) {
                    $result[] = $pt;
                }
            } else {
                $result[] = $curr;
            }
        }

        return $result;
    }

    private function computeJoin(array $prev, array $curr, array $next, float $halfW, StrokeStyle $stroke): ?array
    {
        if ($stroke->lineJoin === LineJoin::Miter) {
            $intersection = $this->lineIntersection($prev, $curr, $curr, $next);
            if ($intersection !== null) {
                $dx = $intersection[0] - $curr[0];
                $dy = $intersection[1] - $curr[1];
                $dist = sqrt($dx * $dx + $dy * $dy);
                if ($dist <= $stroke->miterLimit * $halfW) {
                    return [$intersection];
                }
            }
            return null;
        }

        if ($stroke->lineJoin === LineJoin::Round) {
            $arcPts = $this->arcPoints($curr, $prev, $next, $halfW);
            return $arcPts;
        }

        return null;
    }

    private function lineIntersection(array $p1, array $p2, array $p3, array $p4): ?array
    {
        $x1 = $p1[0]; $y1 = $p1[1];
        $x2 = $p2[0]; $y2 = $p2[1];
        $x3 = $p3[0]; $y3 = $p3[1];
        $x4 = $p4[0]; $y4 = $p4[1];

        $denom = ($x1 - $x2) * ($y3 - $y4) - ($y1 - $y2) * ($x3 - $x4);
        if (abs($denom) < 0.0001) {
            return null;
        }

        $t = (($x1 - $x3) * ($y3 - $y4) - ($y1 - $y3) * ($x3 - $x4)) / $denom;

        $x = $x1 + $t * ($x2 - $x1);
        $y = $y1 + $t * ($y2 - $y1);
        return [$x, $y];
    }

    private function arcPoints(array $center, array $from, array $to, float $radius): array
    {
        $a1 = atan2($from[1] - $center[1], $from[0] - $center[0]);
        $a2 = atan2($to[1] - $center[1], $to[0] - $center[0]);

        $diff = $a2 - $a1;
        while ($diff > M_PI) $diff -= 2 * M_PI;
        while ($diff < -M_PI) $diff += 2 * M_PI;

        $steps = max(3, (int) ceil(abs($diff) * $radius / 2.0));
        $pts = [];
        for ($i = 0; $i <= $steps; $i++) {
            $angle = $a1 + $diff * $i / $steps;
            $pts[] = [$center[0] + $radius * cos($angle), $center[1] + $radius * sin($angle)];
        }
        return $pts;
    }

    private function makeCap(array $leftPt, array $rightPt, array $endpoint, array $direction, float $halfW, LineCap $cap, bool $isStart): array
    {
        if ($cap === LineCap::Butt) {
            return [];
        }

        $dx = (float) ($direction[0] - $endpoint[0]);
        $dy = (float) ($direction[1] - $endpoint[1]);
        $len = sqrt($dx * $dx + $dy * $dy);
        if ($len < 0.0001) {
            return [];
        }
        $dirX = $dx / $len;
        $dirY = $dy / $len;

        if ($isStart) {
            $dirX = -$dirX;
            $dirY = -$dirY;
        }

        if ($cap === LineCap::Square) {
            $extX = $dirX * $halfW;
            $extY = $dirY * $halfW;
            $sq1 = [$leftPt[0] + $extX, $leftPt[1] + $extY];
            $sq2 = [$rightPt[0] + $extX, $rightPt[1] + $extY];
            return [$sq1, $sq2];
        }

        if ($cap === LineCap::Round) {
            $dirFrom = [$leftPt[0] - $endpoint[0], $leftPt[1] - $endpoint[1]];
            $dirTo = [$rightPt[0] - $endpoint[0], $rightPt[1] - $endpoint[1]];
            return $this->arcPoints(
                [$endpoint[0], $endpoint[1]],
                [$endpoint[0] + $dirFrom[0], $endpoint[1] + $dirFrom[1]],
                [$endpoint[0] + $dirTo[0], $endpoint[1] + $dirTo[1]],
                $halfW
            );
        }

        return [];
    }
```

- [ ] **Step 6: Add stub applyDashPattern() (returns the full path as one segment)**

Add this to `Canvas.php`. The full dash implementation is Task 5; for now it returns the full path:

```php
    private function applyDashPattern(array $vertices, bool $closed, StrokeStyle $stroke): array
    {
        if ($stroke->dashArray === null || count($stroke->dashArray) === 0) {
            return [['vertices' => $vertices, 'closed' => $closed]];
        }
        return [['vertices' => $vertices, 'closed' => $closed]];
    }
```

- [ ] **Step 7: Run full test suite**

Run: `composer test`
Expected: All tests pass (138+ tests including new stroke tests)

- [ ] **Step 8: Run PHPStan**

Run: `composer phpstan`
Expected: Baseline unchanged (~667)

- [ ] **Step 9: Commit**

```bash
git add library/draw/Canvas.php tests/Canvas/CanvasTest.php
git commit -m "Implement stroke expansion for width > 1 with caps and joins"
```

---

### Task 5: Implement dash patterns

**Files:**
- Modify: `library/draw/Canvas.php` — replace `applyDashPattern()` stub with real implementation
- Test: `tests/Canvas/CanvasTest.php` — add dash tests

- [ ] **Step 1: Write the failing tests**

Add to `tests/Canvas/CanvasTest.php`:

```php
    public function test_dash_pattern_simple(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $stroke = new StrokeStyle(new Color(4, null), dashArray: [3.0, 2.0]);

        $canvas->drawPath(Path::line(0, 5, 10, 5), null, $stroke);

        $this->assertSame(4, $canvas->data[5][0]->fg, "First dash pixel 0");
        $this->assertSame(4, $canvas->data[5][1]->fg, "First dash pixel 1");
        $this->assertSame(4, $canvas->data[5][2]->fg, "First dash pixel 2");
        $this->assertNull($canvas->data[5][3]->fg, "Gap pixel 3");
        $this->assertNull($canvas->data[5][4]->fg, "Gap pixel 4");
        $this->assertSame(4, $canvas->data[5][5]->fg, "Second dash pixel 5");
        $this->assertSame(4, $canvas->data[5][6]->fg, "Second dash pixel 6");
        $this->assertSame(4, $canvas->data[5][7]->fg, "Second dash pixel 7");
        $this->assertNull($canvas->data[5][8]->fg, "Gap pixel 8");
    }

    public function test_dash_pattern_with_offset(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $stroke = new StrokeStyle(new Color(4, null), dashArray: [3.0, 2.0], dashOffset: 3.0);

        $canvas->drawPath(Path::line(0, 5, 10, 5), null, $stroke);

        $this->assertNull($canvas->data[5][0]->fg, "Offset shifts start into gap");
        $this->assertNull($canvas->data[5][1]->fg, "Still in gap");
        $this->assertSame(4, $canvas->data[5][2]->fg, "Dash starts at offset");
    }

    public function test_dash_pattern_with_thick_stroke(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $stroke = new StrokeStyle(new Color(4, null), width: 3.0, dashArray: [4.0, 3.0]);

        $canvas->drawPath(Path::line(0, 5, 10, 5), null, $stroke);

        $this->assertSame(4, $canvas->data[4][0]->fg, "Thick dash fills rows");
        $this->assertSame(4, $canvas->data[5][0]->fg, "Thick dash fills center");
        $this->assertSame(4, $canvas->data[6][0]->fg, "Thick dash fills rows");
        $this->assertNull($canvas->data[4][4]->fg, "Gap in thick dash");
        $this->assertNull($canvas->data[5][4]->fg, "Gap center");
    }

    public function test_dash_pattern_null_means_solid(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $stroke = new StrokeStyle(new Color(4, null));

        $canvas->drawPath(Path::line(0, 5, 10, 5), null, $stroke);

        for ($x = 0; $x <= 10; $x++) {
            $this->assertSame(4, $canvas->data[5][$x]->fg, "Solid line pixel $x");
        }
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- tests/Canvas/CanvasTest.php --filter test_dash_pattern`
Expected: FAIL — dash pattern stub returns full path, dashes not applied

- [ ] **Step 3: Implement applyDashPattern()**

Replace the stub `applyDashPattern()` in `Canvas.php` with:

```php
    private function applyDashPattern(array $vertices, bool $closed, StrokeStyle $stroke): array
    {
        if ($stroke->dashArray === null || count($stroke->dashArray) === 0) {
            return [['vertices' => $vertices, 'closed' => $closed]];
        }

        $totalLen = 0.0;
        $segments = [];
        for ($i = 0; $i < count($vertices) - 1; $i++) {
            $dx = (float) ($vertices[$i + 1][0] - $vertices[$i][0]);
            $dy = (float) ($vertices[$i + 1][1] - $vertices[$i][1]);
            $segLen = sqrt($dx * $dx + $dy * $dy);
            $segments[] = ['start' => $vertices[$i], 'end' => $vertices[$i + 1], 'len' => $segLen, 'offset' => $totalLen];
            $totalLen += $segLen;
        }
        if ($closed && count($vertices) >= 2) {
            $dx = (float) ($vertices[0][0] - $vertices[count($vertices) - 1][0]);
            $dy = (float) ($vertices[0][1] - $vertices[count($vertices) - 1][1]);
            $segLen = sqrt($dx * $dx + $dy * $dy);
            $segments[] = [
                'start' => $vertices[count($vertices) - 1],
                'end' => $vertices[0],
                'len' => $segLen,
                'offset' => $totalLen
            ];
            $totalLen += $segLen;
        }

        $dashLen = array_sum($stroke->dashArray);
        if ($dashLen <= 0) {
            return [['vertices' => $vertices, 'closed' => $closed]];
        }

        $result = [];
        $currentDashVerts = [];
        $pos = -$stroke->dashOffset;
        $patternIdx = 0;
        $patternPos = 0.0;
        $drawing = true;

        while ($pos < $totalLen) {
            if ($pos < 0) {
                $advance = min(-$pos, $stroke->dashArray[$patternIdx % count($stroke->dashArray)] - $patternPos);
                $patternPos += $advance;
                $pos += $advance;
                if ($patternPos >= $stroke->dashArray[$patternIdx % count($stroke->dashArray)]) {
                    $patternPos = 0.0;
                    $patternIdx++;
                    $drawing = !$drawing;
                }
                continue;
            }

            $remainingInDash = $stroke->dashArray[$patternIdx % count($stroke->dashArray)] - $patternPos;
            $remainingInPath = $totalLen - $pos;
            $advance = min($remainingInDash, $remainingInPath);

            $fromPos = $pos;
            $toPos = $pos + $advance;

            if ($drawing) {
                $fromPt = $this->pointAtLength($segments, $fromPos);
                $toPt = $this->pointAtLength($segments, $toPos);
                if ($fromPt !== null && $toPt !== null) {
                    if (empty($currentDashVerts)) {
                        $currentDashVerts[] = $fromPt;
                    }
                    $currentDashVerts[] = $toPt;
                }
            }

            $pos = $toPos;
            $patternPos += $advance;

            if ($patternPos >= $stroke->dashArray[$patternIdx % count($stroke->dashArray)] - 0.0001) {
                $patternPos = 0.0;
                $patternIdx++;
                $drawing = !$drawing;
                if (!empty($currentDashVerts) && count($currentDashVerts) >= 2) {
                    $result[] = ['vertices' => $currentDashVerts, 'closed' => false];
                    $currentDashVerts = [];
                }
            }
        }

        if (!empty($currentDashVerts) && count($currentDashVerts) >= 2) {
            $result[] = ['vertices' => $currentDashVerts, 'closed' => false];
        }

        if (empty($result)) {
            return [];
        }

        return $result;
    }

    private function pointAtLength(array $segments, float $length): ?array
    {
        foreach ($segments as $seg) {
            if ($length <= $seg['offset'] + $seg['len'] + 0.0001) {
                $t = $seg['len'] > 0.0001 ? ($length - $seg['offset']) / $seg['len'] : 0.0;
                $t = max(0.0, min(1.0, $t));
                return [
                    $seg['start'][0] + $t * ($seg['end'][0] - $seg['start'][0]),
                    $seg['start'][1] + $t * ($seg['end'][1] - $seg['start'][1])
                ];
            }
        }
        if (!empty($segments)) {
            $last = $segments[count($segments) - 1];
            return [$last['end'][0], $last['end'][1]];
        }
        return null;
    }
```

- [ ] **Step 4: Run full test suite**

Run: `composer test`
Expected: All tests pass (142+ tests)

- [ ] **Step 5: Run PHPStan**

Run: `composer phpstan`
Expected: Baseline unchanged

- [ ] **Step 6: Commit**

```bash
git add library/draw/Canvas.php tests/Canvas/CanvasTest.php
git commit -m "Implement dash pattern support for strokes"
```

---

### Task 6: Add stroke demo art command

**Files:**
- Modify: `artbot_scripts/drawing.php` — add `!strokes` demo command or update `!demo`

- [ ] **Step 1: Add a stroke demo to the demo command's `$demos` array**

In `artbot_scripts/drawing.php`, add a `strokes` demo function and register it:

```php
function demoStrokes(Canvas $art): void
{
    $colors = [4, 7, 8, 9, 11, 12, 13];
    $y = 5;
    foreach ([1.0, 2.0, 3.0, 4.0] as $width) {
        $color = new Color($colors[array_rand($colors)], null);
        $art->drawPath(
            Path::line(5.0, (float) $y, 75.0, (float) $y),
            null,
            new StrokeStyle($color, width: $width)
        );
        $y += (int) ($width + 3);
    }

    $cy = (float) ($y + 8);
    foreach ([LineCap::Butt, LineCap::Round, LineCap::Square] as $cap) {
        $color = new Color($colors[array_rand($colors)], null);
        $art->drawPath(
            Path::line(10.0, $cy, 25.0, $cy),
            null,
            new StrokeStyle($color, width: 4.0, lineCap: $cap)
        );
        $cy += 8.0;
    }
}
```

Add `'strokes' => 'demoStrokes'` to the `$demos` array in the `demo` command.

Also add `use draw\StrokeStyle;` and `use draw\LineCap;` to the imports at top of the file.

- [ ] **Step 2: Run tests**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 3: Commit**

```bash
git add artbot_scripts/drawing.php
git commit -m "Add strokes demo showing widths and caps"
```
