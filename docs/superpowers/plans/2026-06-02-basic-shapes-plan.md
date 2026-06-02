# Basic Shapes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add static factory methods to `Path` for SVG basic shapes and remove deprecated Canvas ellipse methods.

**Architecture:** Six static factory methods on `Path` (rect, circle, ellipse, line, polyline, polygon) that return new `Path` objects. Remove `Canvas::drawEllipse()` and `Canvas::drawFilledEllipse()`. Update art commands to use the new API.

**Tech Stack:** PHP 8.1+, PHPUnit 10, existing `draw\Path` and `draw\Canvas` classes.

---

### Task 1: Path::line() and Path::polyline() factory methods

**Files:**
- Modify: `library/draw/Path.php` — add `line()` and `polyline()` static methods at end of class (before `ensureCurrentPoint`)
- Modify: `tests/Canvas/PathTest.php` — add tests

- [ ] **Step 1: Write failing tests for Path::line()**

Append to `tests/Canvas/PathTest.php` before the closing `}`:

```php
    public function test_line_creates_open_path(): void
    {
        $path = Path::line(1.0, 2.0, 5.0, 8.0);
        $this->assertFalse($path->isEmpty());
        $this->assertSame([5.0, 8.0], $path->getCurrentPoint());
        $subpaths = $path->flatten();
        $this->assertCount(1, $subpaths);
        $this->assertFalse($subpaths[0]['closed']);
        $this->assertSame([1.0, 2.0], $subpaths[0]['vertices'][0]);
        $this->assertSame([5.0, 8.0], $subpaths[0]['vertices'][1]);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter test_line_creates_open_path`
Expected: FAIL (method does not exist)

- [ ] **Step 3: Write failing tests for Path::polyline()**

Append to `tests/Canvas/PathTest.php`:

```php
    public function test_polyline_creates_open_path(): void
    {
        $path = Path::polyline([[0.0, 0.0], [10.0, 0.0], [10.0, 10.0]]);
        $subpaths = $path->flatten();
        $this->assertCount(1, $subpaths);
        $this->assertFalse($subpaths[0]['closed']);
        $this->assertCount(3, $subpaths[0]['vertices']);
        $this->assertSame([10.0, 10.0], $path->getCurrentPoint());
    }

    public function test_polyline_rejects_fewer_than_two_points(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Path::polyline([[0.0, 0.0]]);
    }
```

- [ ] **Step 4: Run test to verify it fails**

Run: `composer test -- --filter test_polyline`
Expected: FAIL

- [ ] **Step 5: Implement Path::line() and Path::polyline()**

In `library/draw/Path.php`, add these methods before `ensureCurrentPoint()` (line ~225):

```php
    public static function line(float $x1, float $y1, float $x2, float $y2): self
    {
        return (new self())
            ->moveTo($x1, $y1)
            ->lineTo($x2, $y2);
    }

    public static function polyline(array $points): self
    {
        if (count($points) < 2) {
            throw new \InvalidArgumentException('polyline requires at least 2 points');
        }
        $path = new self();
        $path->moveTo($points[0][0], $points[0][1]);
        for ($i = 1; $i < count($points); $i++) {
            $path->lineTo($points[$i][0], $points[$i][1]);
        }
        return $path;
    }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `composer test`
Expected: all pass

- [ ] **Step 7: Commit**

```bash
git add library/draw/Path.php tests/Canvas/PathTest.php
git commit -m "Add Path::line() and Path::polyline() factory methods"
```

---

### Task 2: Path::polygon() factory method

**Files:**
- Modify: `library/draw/Path.php` — add `polygon()` static method
- Modify: `tests/Canvas/PathTest.php` — add tests

- [ ] **Step 1: Write failing tests**

Append to `tests/Canvas/PathTest.php`:

```php
    public function test_polygon_creates_closed_path(): void
    {
        $path = Path::polygon([[0.0, 0.0], [10.0, 0.0], [10.0, 10.0], [0.0, 10.0]]);
        $subpaths = $path->flatten();
        $this->assertCount(1, $subpaths);
        $this->assertTrue($subpaths[0]['closed']);
        $this->assertCount(4, $subpaths[0]['vertices']);
    }

    public function test_polygon_rejects_fewer_than_two_points(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Path::polygon([[5.0, 5.0]]);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter test_polygon`
Expected: FAIL

- [ ] **Step 3: Implement Path::polygon()**

In `library/draw/Path.php`, add after `polyline()`:

```php
    public static function polygon(array $points): self
    {
        if (count($points) < 2) {
            throw new \InvalidArgumentException('polygon requires at least 2 points');
        }
        $path = new self();
        $path->moveTo($points[0][0], $points[0][1]);
        for ($i = 1; $i < count($points); $i++) {
            $path->lineTo($points[$i][0], $points[$i][1]);
        }
        $path->closePath();
        return $path;
    }
```

- [ ] **Step 4: Run tests**

Run: `composer test`
Expected: all pass

- [ ] **Step 5: Commit**

```bash
git add library/draw/Path.php tests/Canvas/PathTest.php
git commit -m "Add Path::polygon() factory method"
```

---

### Task 3: Path::circle() and Path::ellipse() factory methods

**Files:**
- Modify: `library/draw/Path.php` — add `circle()` and `ellipse()` static methods
- Modify: `tests/Canvas/PathTest.php` — add tests

- [ ] **Step 1: Write failing tests**

Append to `tests/Canvas/PathTest.php`:

```php
    public function test_circle_creates_closed_path(): void
    {
        $path = Path::circle(20.0, 20.0, 10.0);
        $subpaths = $path->flatten();
        $this->assertCount(1, $subpaths);
        $this->assertTrue($subpaths[0]['closed']);
        $this->assertSame([20.0 + 10.0, 20.0], $path->getCurrentPoint());
    }

    public function test_ellipse_creates_closed_path(): void
    {
        $path = Path::ellipse(30.0, 20.0, 15.0, 8.0);
        $subpaths = $path->flatten();
        $this->assertCount(1, $subpaths);
        $this->assertTrue($subpaths[0]['closed']);
        $this->assertSame([30.0 + 15.0, 20.0], $path->getCurrentPoint());
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- --filter test_circle_creates_closed_path`
Expected: FAIL

- [ ] **Step 3: Implement Path::circle() and Path::ellipse()**

In `library/draw/Path.php`, add after `polygon()`:

```php
    public static function circle(float $cx, float $cy, float $r): self
    {
        return self::ellipse($cx, $cy, $r, $r);
    }

    public static function ellipse(float $cx, float $cy, float $rx, float $ry): self
    {
        $path = new self();
        $path->moveTo($cx + $rx, $cy);
        $path->arcTo($rx, $ry, 0, false, true, $cx, $cy + $ry);
        $path->arcTo($rx, $ry, 0, false, true, $cx - $rx, $cy);
        $path->arcTo($rx, $ry, 0, false, true, $cx, $cy - $ry);
        $path->arcTo($rx, $ry, 0, false, true, $cx + $rx, $cy);
        $path->closePath();
        return $path;
    }
```

- [ ] **Step 4: Run tests**

Run: `composer test`
Expected: all pass

- [ ] **Step 5: Commit**

```bash
git add library/draw/Path.php tests/Canvas/PathTest.php
git commit -m "Add Path::circle() and Path::ellipse() factory methods"
```

---

### Task 4: Path::rect() factory method

**Files:**
- Modify: `library/draw/Path.php` — add `rect()` static method
- Modify: `tests/Canvas/PathTest.php` — add tests

- [ ] **Step 1: Write failing tests**

Append to `tests/Canvas/PathTest.php`:

```php
    public function test_rect_creates_closed_path(): void
    {
        $path = Path::rect(5.0, 10.0, 20.0, 15.0);
        $subpaths = $path->flatten();
        $this->assertCount(1, $subpaths);
        $this->assertTrue($subpaths[0]['closed']);
        $this->assertCount(4, $subpaths[0]['vertices']);
    }

    public function test_rect_with_rounded_corners(): void
    {
        $path = Path::rect(0.0, 0.0, 20.0, 10.0, 3.0, 3.0);
        $subpaths = $path->flatten();
        $this->assertCount(1, $subpaths);
        $this->assertTrue($subpaths[0]['closed']);
        $this->assertGreaterThan(4, count($subpaths[0]['vertices']));
    }

    public function test_rect_clamps_radius_to_half_smallest_dimension(): void
    {
        $path = Path::rect(0.0, 0.0, 10.0, 4.0, 100.0, 100.0);
        $subpaths = $path->flatten();
        $this->assertCount(1, $subpaths);
        $this->assertTrue($subpaths[0]['closed']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- --filter test_rect`
Expected: FAIL

- [ ] **Step 3: Implement Path::rect()**

In `library/draw/Path.php`, add after `ellipse()`:

```php
    public static function rect(float $x, float $y, float $w, float $h, float $rx = 0, float $ry = 0): self
    {
        $path = new self();

        if ($rx > 0 || $ry > 0) {
            $maxRx = $w / 2;
            $maxRy = $h / 2;
            if ($rx <= 0) {
                $rx = $ry;
            }
            if ($ry <= 0) {
                $ry = $rx;
            }
            $rx = min($rx, $maxRx);
            $ry = min($ry, $maxRy);

            $path->moveTo($x + $rx, $y);
            $path->lineTo($x + $w - $rx, $y);
            $path->arcTo($rx, $ry, 0, false, true, $x + $w, $y + $ry);
            $path->lineTo($x + $w, $y + $h - $ry);
            $path->arcTo($rx, $ry, 0, false, true, $x + $w - $rx, $y + $h);
            $path->lineTo($x + $rx, $y + $h);
            $path->arcTo($rx, $ry, 0, false, true, $x, $y + $h - $ry);
            $path->lineTo($x, $y + $ry);
            $path->arcTo($rx, $ry, 0, false, true, $x + $rx, $y);
        } else {
            $path->moveTo($x, $y);
            $path->lineTo($x + $w, $y);
            $path->lineTo($x + $w, $y + $h);
            $path->lineTo($x, $y + $h);
        }

        $path->closePath();
        return $path;
    }
```

- [ ] **Step 4: Run tests**

Run: `composer test`
Expected: all pass

- [ ] **Step 5: Commit**

```bash
git add library/draw/Path.php tests/Canvas/PathTest.php
git commit -m "Add Path::rect() factory method with rounded corner support"
```

---

### Task 5: Remove Canvas::drawEllipse() and drawFilledEllipse(), update callers

**Files:**
- Modify: `library/draw/Canvas.php` — remove `drawEllipse()` (lines 242-263) and `drawFilledEllipse()` (lines 231-240)
- Modify: `artbot_scripts/drawing.php` — update all callers

- [ ] **Step 1: Remove drawFilledEllipse from Canvas**

In `library/draw/Canvas.php`, delete the `drawFilledEllipse` method (lines 231-240).

- [ ] **Step 2: Remove drawEllipse from Canvas**

In `library/draw/Canvas.php`, delete the `drawEllipse` method (lines 242-263) including the comment on line 242.

- [ ] **Step 3: Update drawing.php callers**

Replace the `lineTest` function (lines 14-27) with:

```php
#[Cmd("linetest")]
#[Syntax('<sx: uint max=100> <sy: uint max=100> <ex: uint max=100> <ey: uint max=100>')]
function lineTest(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
{
    $art = Canvas::createBlank(30, 14);
    $sx = $cmdArgs['sx'];
    $sy = $cmdArgs['sy'];
    $ex = $cmdArgs['ex'];
    $ey = $cmdArgs['ey'];

    $art->drawPath(Path::line($sx, $sy, $ex, $ey), null, new Color(04, 0), "x");

    \pumpToChan($bot, $args->chan, explode("\n", trim($art, "\n")));
}
```

Delete the `filledEllipseTest` function (lines 30-43) and the `ellipseTest` function (lines 45-59). These were debug-only commands whose functionality is covered by the general Path API.

Replace the `circles` function (lines 80-96) with:

```php
#[Cmd("circles")]
#[Desc("Draw some random circles")]
function circles(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
{
    $art = Canvas::createBlank(80, 48, true);
    $numcircles = rand(5, 20);
    for ($i = 0; $i < $numcircles; $i++) {
        $color = new Color(rand(0, 16), null);
        $w = rand(6, 80);
        $h = rand($w - 3, $w + 3) + 5;
        $cx = rand(-5, 90);
        $cy = rand(-5, 55);
        $art->drawPath(Path::ellipse($cx, $cy, $w / 2, $h / 2), null, $color);
    }

    \pumpToChan($bot, $args->chan, explode("\n", trim($art, "\n")));
}
```

Replace the `pentagons` function (lines 99-115) with:

```php
#[Cmd("pentagons")]
#[Desc("Draw some random pentagons")]
function pentagons(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
{
    $art = Canvas::createBlank(80, 48, true);
    $numpents = rand(5, 20);
    for ($i = 0; $i < $numpents; $i++) {
        $color = new Color(rand(0, 16), null);
        $radius = random_int(10, 25);
        $cx = random_int(5, 70);
        $cy = random_int(5, 45);
        $rot = deg2rad(random_int(0, 72));
        $points = [];
        for ($p = 0; $p < 5; $p++) {
            $angle = (2 * M_PI * $p / 5) + $rot;
            $points[] = [$cx + $radius * cos($angle), $cy + $radius * sin($angle)];
        }
        $art->drawPath(Path::polygon($points), null, $color);
    }

    \pumpToChan($bot, $args->chan, explode("\n", trim($art, "\n")));
}
```

Note: `drawEllipse` used `$w`/`$h` as diameters (divided by 2 internally). `Path::ellipse` takes radii, so we pass `$w / 2, $h / 2`. The old pentagons used `drawEllipse` with `$segments=5` and rotation — we now compute 5 rotated polygon vertices directly.

- [ ] **Step 4: Run tests and PHPStan**

Run: `composer test && composer phpstan`
Expected: tests pass, PHPStan errors <= 676

- [ ] **Step 5: Commit**

```bash
git add library/draw/Canvas.php artbot_scripts/drawing.php
git commit -m "Remove Canvas drawEllipse/drawFilledEllipse, update callers to use Path factories"
```

---

### Task 6: Add rendering tests for basic shape factories

**Files:**
- Modify: `tests/Canvas/PathTest.php` — add rendering tests

- [ ] **Step 1: Write rendering tests**

Append to `tests/Canvas/PathTest.php`:

```php
    public function test_line_renders_pixels(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $color = new Color(4, 0);
        $canvas->drawPath(Path::line(0, 0, 9, 9), null, $color);
        $this->assertSame(4, $canvas->data[0][0]->fg);
        $this->assertSame(4, $canvas->data[9][9]->fg);
    }

    public function test_rect_renders_outline(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $color = new Color(4, 0);
        $canvas->drawPath(Path::rect(1, 1, 8, 8), null, $color);
        $this->assertSame(4, $canvas->data[1][1]->fg);
        $this->assertSame(4, $canvas->data[1][8]->fg);
        $this->assertSame(4, $canvas->data[8][1]->fg);
        $this->assertSame(4, $canvas->data[8][8]->fg);
    }

    public function test_rect_renders_fill(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $fill = new Color(4, 0);
        $canvas->drawPath(Path::rect(2, 2, 6, 6), $fill, null);
        $this->assertSame(4, $canvas->data[4][4]->fg);
        $this->assertNull($canvas->data[0][0]->fg);
    }

    public function test_circle_renders_outline(): void
    {
        $canvas = Canvas::createBlank(20, 20);
        $color = new Color(4, 0);
        $canvas->drawPath(Path::circle(10, 10, 5), null, $color);
        $this->assertSame(4, $canvas->data[10][15]->fg);
        $this->assertSame(4, $canvas->data[10][5]->fg);
    }

    public function test_ellipse_renders_fill(): void
    {
        $canvas = Canvas::createBlank(20, 20);
        $fill = new Color(4, 0);
        $canvas->drawPath(Path::ellipse(10, 10, 8, 5), $fill, null);
        $this->assertSame(4, $canvas->data[10][10]->fg);
        $this->assertNull($canvas->data[0][0]->fg);
    }
```

- [ ] **Step 2: Run tests**

Run: `composer test`
Expected: all pass

- [ ] **Step 3: Commit**

```bash
git add tests/Canvas/PathTest.php
git commit -m "Add rendering tests for Path basic shape factories"
```
