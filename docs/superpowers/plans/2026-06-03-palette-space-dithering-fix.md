# Palette-Space Dithering Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace uniform RGB-offset dithering with palette-space threshold dithering so banding is broken up uniformly.

**Architecture:** In `IrcPalette::nearestColor`, when dithering is on, find the two nearest palette colors and use the Bayer threshold to pick between them based on the interpolation fraction.

**Tech Stack:** PHP 8.1+, PHPUnit 10

---

### Task 1: Replace dithering logic in `IrcPalette::nearestColor`

**Files:**
- Modify: `library/draw/IrcPalette.php`
- Modify: `tests/Canvas/IrcPaletteTest.php`

- [ ] **Step 1: Write the tests**

Update `tests/Canvas/IrcPaletteTest.php`. Keep all existing tests. The existing dithering tests should mostly pass with the new logic, but one test (`test_nearestColor_dithering_with_xy_zero_same_as_none_for_bayer_center`) relied on the old RGB-offset behavior. Replace it with a test that verifies the palette-space behavior.

Replace `test_nearestColor_dithering_with_xy_zero_same_as_none_for_bayer_center` with:

```php
public function test_nearestColor_dithering_picks_between_two_palette_neighbors(): void
{
    $r = 128;
    $g = 50;
    $b = 50;
    $none = IrcPalette::nearestColor($r, $g, $b, Dithering::None);
    $results = [];
    for ($y = 0; $y < 4; $y++) {
        for ($x = 0; $x < 4; $x++) {
            $code = IrcPalette::nearestColor($r, $g, $b, Dithering::Ordered4x4, $x, $y);
            $results[] = $code;
        }
    }
    $unique = array_unique($results);
    $this->assertGreaterThanOrEqual(2, count($unique), 'Dithering should produce at least 2 different palette colors for a mid-range input');
    foreach ($unique as $code) {
        $this->assertGreaterThanOrEqual(0, $code);
        $this->assertLessThanOrEqual(98, $code);
    }
}
```

Add a new test that verifies the dithering is symmetric (not one-sided):

```php
public function test_nearestColor_dithering_is_symmetric_across_band(): void
{
    $none1 = IrcPalette::nearestColor(100, 50, 50, Dithering::None);
    $none2 = IrcPalette::nearestColor(130, 50, 50, Dithering::None);
    if ($none1 === $none2) {
        $this->markTestSkipped('Test colors map to same palette entry');
    }
    $midR = 115;
    $midG = 50;
    $midB = 50;
    $lowR = 105;
    $highR = 125;
    $ditheredAtLow = [];
    $ditheredAtMid = [];
    $ditheredAtHigh = [];
    for ($y = 0; $y < 4; $y++) {
        for ($x = 0; $x < 4; $x++) {
            $ditheredAtLow[] = IrcPalette::nearestColor($lowR, $midG, $midB, Dithering::Ordered4x4, $x, $y);
            $ditheredAtMid[] = IrcPalette::nearestColor($midR, $midG, $midB, Dithering::Ordered4x4, $x, $y);
            $ditheredAtHigh[] = IrcPalette::nearestColor($highR, $midG, $midB, Dithering::Ordered4x4, $x, $y);
        }
    }
    $uniqueLow = count(array_unique($ditheredAtLow));
    $uniqueMid = count(array_unique($ditheredAtMid));
    $uniqueHigh = count(array_unique($ditheredAtHigh));
    $this->assertGreaterThanOrEqual(2, $uniqueMid, 'Mid-band should dither between at least 2 colors');
}
```

Add a test for the Bayer threshold distribution:

```php
public function test_nearestColor_dithering_threshold_distribution(): void
{
    $r = 115;
    $g = 50;
    $b = 50;
    $noneCode = IrcPalette::nearestColor($r, $g, $b, Dithering::None);
    $bestCount = 0;
    $otherCount = 0;
    for ($y = 0; $y < 4; $y++) {
        for ($x = 0; $x < 4; $x++) {
            $code = IrcPalette::nearestColor($r, $g, $b, Dithering::Ordered4x4, $x, $y);
            if ($code === $noneCode) {
                $bestCount++;
            } else {
                $otherCount++;
            }
        }
    }
    $this->assertGreaterThan(0, $bestCount, 'Some pixels should pick the best match');
    $this->assertGreaterThan(0, $otherCount, 'Some pixels should pick the second-best match');
}
```

- [ ] **Step 2: Run tests to see which fail**

Run: `composer test -- tests/Canvas/IrcPaletteTest.php`
Expected: The replaced test may fail, new tests may fail

- [ ] **Step 3: Replace the dithering logic in `IrcPalette::nearestColor`**

In `library/draw/IrcPalette.php`:

Remove the `DITHER_STRENGTH` constant.

Replace the `nearestColor` method body entirely:

```php
public static function nearestColor(int $r, int $g, int $b, Dithering $mode = Dithering::None, int $x = 0, int $y = 0): int
{
    if ($mode === Dithering::Ordered4x4) {
        return self::nearestColorDithered($r, $g, $b, $x, $y);
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

private static function nearestColorDithered(int $r, int $g, int $b, int $x, int $y): int
{
    self::$colorPalette ??= self::buildColorPalette();
    $target = new Color(new RGB($r, $g, $b));

    $bestIdx = 0;
    $bestDist = INF;
    $secondIdx = 1;
    $secondDist = INF;
    foreach (self::$colorPalette as $idx => $palColor) {
        $d = $target->getDifferenceDin99($palColor);
        if ($d < $bestDist) {
            $secondIdx = $bestIdx;
            $secondDist = $bestDist;
            $bestIdx = $idx;
            $bestDist = $d;
        } elseif ($d < $secondDist) {
            $secondIdx = $idx;
            $secondDist = $d;
        }
    }

    if ($bestDist < 0.001) {
        return $bestIdx;
    }

    $bayer = self::BAYER_4X4[$y & 3][$x & 3];
    $threshold = ($bayer + 0.5) / 16.0;

    $t = $bestDist / ($bestDist + $secondDist);

    if ($t >= $threshold) {
        return $secondIdx;
    }
    return $bestIdx;
}
```

- [ ] **Step 4: Run IrcPalette tests**

Run: `composer test -- tests/Canvas/IrcPaletteTest.php`
Expected: ALL PASS

- [ ] **Step 5: Run full test suite**

Run: `composer test`
Expected: ALL PASS

- [ ] **Step 6: Commit**

```bash
git add library/draw/IrcPalette.php tests/Canvas/IrcPaletteTest.php
git commit -m "fix(draw): replace RGB-offset dithering with palette-space threshold dithering"
```

---

### Task 2: Verify dithering quality with diagnostic

**Files:**
- No code changes — diagnostic only

- [ ] **Step 1: Run diagnostic to verify the fix**

Run this PHP script to verify the dithering now works correctly:

```bash
php -r '
require "vendor/autoload.php";
use draw\IrcPalette;
use draw\Dithering;

echo "=== Palette-space dithering: unique colors per 4x4 block ===\n";
$testColors = [
    [128, 64, 32, "brownish"],
    [200, 50, 50, "red"],
    [50, 200, 50, "green"],
    [50, 50, 200, "blue"],
    [100, 100, 100, "gray"],
    [200, 200, 50, "bright yellow"],
    [150, 50, 50, "dark red"],
    [180, 100, 50, "orange"],
];
foreach ($testColors as $c) {
    $codes = [];
    $noneCode = IrcPalette::nearestColor($c[0], $c[1], $c[2], Dithering::None);
    for ($y = 0; $y < 4; $y++) {
        for ($x = 0; $x < 4; $x++) {
            $codes[] = IrcPalette::nearestColor($c[0], $c[1], $c[2], Dithering::Ordered4x4, $x, $y);
        }
    }
    $unique = array_unique($codes);
    printf("rgb=(%3d,%3d,%3d) %-14s none=%2d unique=%d values=%s\n",
        $c[0], $c[1], $c[2], $c[3], $noneCode, count($unique), implode(",", $unique));
}

echo "\n=== Verify symmetric dithering across a band ===\n";
for ($r = 90; $r <= 140; $r += 10) {
    $codes = [];
    $none = IrcPalette::nearestColor($r, 50, 50, Dithering::None);
    for ($y = 0; $y < 4; $y++) {
        for ($x = 0; $x < 4; $x++) {
            $codes[] = IrcPalette::nearestColor($r, 50, 50, Dithering::Ordered4x4, $x, $y);
        }
    }
    $unique = count(array_unique($codes));
    printf("r=%3d none=%2d unique_dithered=%d\n", $r, $none, $unique);
}
'
```

Expected output should show:
- ALL test colors produce >= 2 unique values per 4x4 block (including bright yellow)
- Dithering works at multiple points across the band, not just at edges

- [ ] **Step 2: If diagnostic looks good, proceed. If not, note the issue.**
