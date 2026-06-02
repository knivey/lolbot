# EvenOdd Fill Rule Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add EvenOdd fill rule to the scanline converter for SVG compatibility.

**Architecture:** Create a `FillRule` enum, thread it through `drawPath()` to `fillPolygonScanlineMulti()`, and switch the scanline walk from winding-counter to boolean-toggle when EvenOdd is selected.

**Tech Stack:** PHP 8.1, PHPUnit 10, PHPStan level 9 (baseline 666)

---

### Task 1: Create FillRule enum

**Files:**
- Create: `library/draw/FillRule.php`
- Test: `tests/Canvas/FillRuleTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Canvas;

use draw\FillRule;
use PHPUnit\Framework\TestCase;

class FillRuleTest extends TestCase
{
    public function test_enum_has_nonzero_and_evenodd_cases(): void
    {
        $this->assertSame('NonZero', FillRule::NonZero->name);
        $this->assertSame('EvenOdd', FillRule::EvenOdd->name);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Canvas/FillRuleTest.php`
Expected: FATAL ERROR — class `draw\FillRule` not found

- [ ] **Step 3: Write implementation**

```php
<?php

namespace draw;

enum FillRule
{
    case NonZero;
    case EvenOdd;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- tests/Canvas/FillRuleTest.php`
Expected: PASS (1 test)

- [ ] **Step 5: Run full test suite + PHPStan**

Run: `composer test && composer phpstan`
Expected: 122 tests pass, PHPStan 666 (baseline unchanged)

- [ ] **Step 6: Commit**

```bash
git add library/draw/FillRule.php tests/Canvas/FillRuleTest.php
git commit -m "Add FillRule enum with NonZero and EvenOdd cases"
```

---

### Task 2: Add EvenOdd scanline logic and thread FillRule through drawPath

**Files:**
- Modify: `library/draw/Canvas.php` — `drawPath()` signature (line 346), `fillPolygonScanlineMulti()` (line 436)
- Test: `tests/Canvas/CanvasTest.php`

- [ ] **Step 1: Write the failing tests**

Add these two tests to `tests/Canvas/CanvasTest.php` (before the closing `}` at line 450):

```php
    public function test_evenodd_concentric_squares_hole(): void
    {
        $canvas = Canvas::createBlank(20, 20);
        $fill = new Color(4, null);

        $outer = Path::polygon([[1.0, 1.0], [15.0, 1.0], [15.0, 15.0], [1.0, 15.0]]);
        $inner = Path::polygon([[5.0, 5.0], [11.0, 5.0], [11.0, 11.0], [5.0, 11.0]]);

        $path = new Path();
        foreach ($outer->flatten() as $sp) {
            $path->moveTo($sp['vertices'][0][0], $sp['vertices'][0][1]);
            $n = count($sp['vertices']);
            for ($i = 1; $i < $n; $i++) {
                $path->lineTo($sp['vertices'][$i][0], $sp['vertices'][$i][1]);
            }
            if ($sp['closed']) {
                $path->closePath();
            }
        }
        foreach ($inner->flatten() as $sp) {
            $path->moveTo($sp['vertices'][0][0], $sp['vertices'][0][1]);
            $n = count($sp['vertices']);
            for ($i = 1; $i < $n; $i++) {
                $path->lineTo($sp['vertices'][$i][0], $sp['vertices'][$i][1]);
            }
            if ($sp['closed']) {
                $path->closePath();
            }
        }

        $canvas->drawPath($path, $fill, null, '', FillRule::EvenOdd);

        $this->assertSame(4, $canvas->data[3][3]->fg, "Pixel between outer and inner boundary should be filled (EvenOdd)");
        $this->assertSame(4, $canvas->data[3][8]->fg, "Pixel between outer and inner boundary should be filled (EvenOdd)");
        $this->assertNull($canvas->data[8][8]->fg, "Pixel inside inner square should NOT be filled (EvenOdd hole)");
        $this->assertNull($canvas->data[6][6]->fg, "Pixel inside inner square should NOT be filled (EvenOdd hole)");
    }

    public function test_nonzero_concentric_squares_no_hole(): void
    {
        $canvas = Canvas::createBlank(20, 20);
        $fill = new Color(4, null);

        $outer = Path::polygon([[1.0, 1.0], [15.0, 1.0], [15.0, 15.0], [1.0, 15.0]]);
        $inner = Path::polygon([[5.0, 5.0], [11.0, 5.0], [11.0, 11.0], [5.0, 11.0]]);

        $path = new Path();
        foreach ($outer->flatten() as $sp) {
            $path->moveTo($sp['vertices'][0][0], $sp['vertices'][0][1]);
            $n = count($sp['vertices']);
            for ($i = 1; $i < $n; $i++) {
                $path->lineTo($sp['vertices'][$i][0], $sp['vertices'][$i][1]);
            }
            if ($sp['closed']) {
                $path->closePath();
            }
        }
        foreach ($inner->flatten() as $sp) {
            $path->moveTo($sp['vertices'][0][0], $sp['vertices'][0][1]);
            $n = count($sp['vertices']);
            for ($i = 1; $i < $n; $i++) {
                $path->lineTo($sp['vertices'][$i][0], $sp['vertices'][$i][1]);
            }
            if ($sp['closed']) {
                $path->closePath();
            }
        }

        $canvas->drawPath($path, $fill, null, '', FillRule::NonZero);

        $this->assertSame(4, $canvas->data[3][3]->fg, "Pixel between outer and inner should be filled (NonZero)");
        $this->assertSame(4, $canvas->data[8][8]->fg, "Pixel inside inner square should also be filled (NonZero, same winding)");
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- tests/Canvas/CanvasTest.php --filter test_evenodd_concentric`
Expected: FAIL — `FillRule` not imported / `FillRule::EvenOdd` argument not accepted

- [ ] **Step 3: Add `use draw\FillRule` to CanvasTest**

Add `use draw\FillRule;` after the existing `use draw\Transform;` line in `tests/Canvas/CanvasTest.php`.

- [ ] **Step 4: Modify `Canvas::drawPath()` signature**

Change `drawPath()` at line 346 to accept `FillRule`:

```php
    public function drawPath(
        Path $path,
        ?Color $fillColor,
        ?Color $outlineColor,
        string $text = '',
        FillRule $fillRule = FillRule::NonZero
    ): void {
```

- [ ] **Step 5: Thread `$fillRule` to `fillPolygonScanlineMulti()`**

Change the call at line 388:

```php
                $this->fillPolygonScanlineMulti($polygonArrays, $fillColor, $text, $fillRule);
```

- [ ] **Step 6: Update `fillPolygonScanlineMulti()` signature and logic**

Change the signature at line 436 to accept `FillRule`:

```php
    private function fillPolygonScanlineMulti(array $subpaths, Color $color, string $text, FillRule $fillRule): void
```

Replace the scanline walk (lines 488-515) with rule-aware logic:

```php
            usort($intersections, fn ($a, $b) => $a[0] <=> $b[0]);

            if ($fillRule === FillRule::EvenOdd) {
                $inside = false;
                $spanStart = null;
                foreach ($intersections as [$xInt]) {
                    $inside = !$inside;
                    if ($inside) {
                        $spanStart = $xInt;
                    } else {
                        if ($spanStart !== null) {
                            $xL = (int) ceil($spanStart);
                            $xR = (int) floor($xInt);
                            for ($xx = $xL; $xx <= $xR; $xx++) {
                                if (isset($this->data[$Y][$xx])) {
                                    $this->data[$Y][$xx]->fg = $color->fg;
                                    $this->data[$Y][$xx]->bg = $color->bg;
                                    if ($text != '') {
                                        $this->data[$Y][$xx]->text = $text;
                                    }
                                }
                            }
                        }
                        $spanStart = null;
                    }
                }
            } else {
                $winding = 0;
                $spanStart = null;
                foreach ($intersections as [$xInt, $dir]) {
                    $prevWinding = $winding;
                    $winding += $dir;
                    if ($prevWinding === 0 && $winding !== 0) {
                        $spanStart = $xInt;
                    } elseif ($prevWinding !== 0 && $winding === 0) {
                        if ($spanStart !== null) {
                            $xL = (int) ceil($spanStart);
                            $xR = (int) floor($xInt);
                            for ($xx = $xL; $xx <= $xR; $xx++) {
                                if (isset($this->data[$Y][$xx])) {
                                    $this->data[$Y][$xx]->fg = $color->fg;
                                    $this->data[$Y][$xx]->bg = $color->bg;
                                    if ($text != '') {
                                        $this->data[$Y][$xx]->text = $text;
                                    }
                                }
                            }
                        }
                        $spanStart = null;
                    }
                }
            }
```

- [ ] **Step 7: Run full test suite + PHPStan**

Run: `composer test && composer phpstan`
Expected: 124 tests pass, PHPStan 666 (baseline unchanged)

- [ ] **Step 8: Commit**

```bash
git add library/draw/Canvas.php tests/Canvas/CanvasTest.php
git commit -m "Add EvenOdd fill rule to scanline converter"
```
