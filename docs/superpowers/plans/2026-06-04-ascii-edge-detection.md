# Sobel Edge Detection for @ascii Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Sobel edge detection to the @ascii command's normal mode, overlaying directional characters (`| \ = /`) at detected edges instead of luminosity characters.

**Architecture:** After the existing `exportImagePixels()` call, compute a Rec.601 luminance map and apply 3x3 Sobel kernels to produce Gx/Gy gradient arrays. In the per-block character selection, aggregate Gx/Gy over the 8x8 block, compute magnitude and angle, and select a directional edge character if magnitude exceeds threshold (40.0). Only active in normal mode (not halfblock, not text/word, not block).

**Tech Stack:** PHP 8.1+, no new dependencies. All computation is inline on the existing `$pixels` array.

---

### Task 1: Add Sobel computation before the main render loop

**Files:**
- Modify: `artbot_scripts/urlimg.php:227` (after `$pixels = ...` line, before the loop)

- [ ] **Step 1: Add luminance map + Sobel computation**

Insert the following code block after line 227 (`$pixels = $img->exportImagePixels(...);`) and before line 229 (`$text = $cmdArgs[1];`):

```php
        $lumMap = array_fill(0, $sampleW * $sampleH, 0.0);
        $gxMap = array_fill(0, $sampleW * $sampleH, 0.0);
        $gyMap = array_fill(0, $sampleW * $sampleH, 0.0);
        for ($i = 0; $i < $sampleW * $sampleH; $i++) {
            $lumMap[$i] = 0.299 * $pixels[$i * 3] + 0.587 * $pixels[$i * 3 + 1] + 0.114 * $pixels[$i * 3 + 2];
        }
        for ($y = 1; $y < $sampleH - 1; $y++) {
            for ($x = 1; $x < $sampleW - 1; $x++) {
                $tl = $lumMap[($y - 1) * $sampleW + ($x - 1)];
                $tc = $lumMap[($y - 1) * $sampleW + $x];
                $tr = $lumMap[($y - 1) * $sampleW + ($x + 1)];
                $ml = $lumMap[$y * $sampleW + ($x - 1)];
                $mr = $lumMap[$y * $sampleW + ($x + 1)];
                $bl = $lumMap[($y + 1) * $sampleW + ($x - 1)];
                $bc = $lumMap[($y + 1) * $sampleW + $x];
                $br = $lumMap[($y + 1) * $sampleW + ($x + 1)];
                $idx = $y * $sampleW + $x;
                $gxMap[$idx] = -$tl + $tr - 2 * $ml + 2 * $mr - $bl + $br;
                $gyMap[$idx] = -$tl - 2 * $tc - $tr + $bl + 2 * $bc + $br;
            }
        }
```

- [ ] **Step 2: Verify syntax**

Run: `php -l artbot_scripts/urlimg.php`
Expected: `No syntax errors`

- [ ] **Step 3: Commit**

```bash
git add artbot_scripts/urlimg.php
git commit -m "feat: add Sobel gradient computation for @ascii edge detection"
```

---

### Task 2: Add edge character helper function

**Files:**
- Modify: `artbot_scripts/urlimg.php` (after the `render()` function near end of file)

- [ ] **Step 1: Add the `edgeChar()` function**

Insert after the `render()` function (currently the last function in the file):

```php
function edgeChar(array $gxMap, array $gyMap, int $srcX0, int $srcY0, int $blockSize, int $sampleW, int $sampleH, float $threshold = 40.0): ?string
{
    $sumGx = 0.0;
    $sumGy = 0.0;
    $yStart = max(1, $srcY0);
    $yEnd = min($sampleH - 1, $srcY0 + $blockSize);
    $xStart = max(1, $srcX0);
    $xEnd = min($sampleW - 1, $srcX0 + $blockSize);
    for ($sy = $yStart; $sy < $yEnd; $sy++) {
        for ($sx = $xStart; $sx < $xEnd; $sx++) {
            $idx = $sy * $sampleW + $sx;
            $sumGx += $gxMap[$idx];
            $sumGy += $gyMap[$idx];
        }
    }
    $pixels = ($yEnd - $yStart) * ($xEnd - $xStart);
    if ($pixels == 0) {
        return null;
    }
    $mag = sqrt($sumGx * $sumGx + $sumGy * $sumGy) / $pixels;
    if ($mag <= $threshold) {
        return null;
    }
    $angle = atan2($sumGy, $sumGx) * 180.0 / M_PI;
    if ($angle < 0) {
        $angle += 180.0;
    }
    if ($angle < 22.5 || $angle >= 157.5) {
        return '|';
    }
    if ($angle < 67.5) {
        return '\\';
    }
    if ($angle < 112.5) {
        return '=';
    }
    return '/';
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l artbot_scripts/urlimg.php`
Expected: `No syntax errors`

- [ ] **Step 3: Commit**

```bash
git add artbot_scripts/urlimg.php
git commit -m "feat: add edgeChar() helper for Sobel edge-to-character mapping"
```

---

### Task 3: Integrate edge detection into the render loop

**Files:**
- Modify: `artbot_scripts/urlimg.php:351` (the normal-mode character selection in the `else` branch)

- [ ] **Step 1: Replace the character selection with edge-aware logic**

Find this block (around line 351):

```php
                else {
                        $str_char = render($luminosity);
```

Replace with:

```php
                else {
                    $str_char = edgeChar($gxMap, $gyMap, $srcX0, $srcY0, $blockSize, $sampleW, $sampleH) ?? render($luminosity);
```

- [ ] **Step 2: Verify syntax**

Run: `php -l artbot_scripts/urlimg.php`
Expected: `No syntax errors`

- [ ] **Step 3: Run full test suite**

Run: `composer test`
Expected: All 468 tests pass (1 skipped)

- [ ] **Step 4: Run static analysis**

Run: `composer phpstan`
Expected: No new errors in `artbot_scripts/urlimg.php`

- [ ] **Step 5: Commit**

```bash
git add artbot_scripts/urlimg.php
git commit -m "feat: integrate Sobel edge detection into @ascii normal mode"
```

---

### Task 4: Manual validation

- [ ] **Step 1: Test edge detection with a sample image**

Run a quick PHP test to verify edge characters are produced for a known edge pattern:

```bash
php -r '
$pixels = array_fill(0, 24 * 24 * 3, 0);
// Left half black, right half white
for ($y = 0; $y < 24; $y++) {
    for ($x = 12; $x < 24; $x++) {
        $idx = ($y * 24 + $x) * 3;
        $pixels[$idx] = 255;
        $pixels[$idx+1] = 255;
        $pixels[$idx+2] = 255;
    }
}
$lumMap = array_fill(0, 24 * 24, 0.0);
$gxMap = array_fill(0, 24 * 24, 0.0);
$gyMap = array_fill(0, 24 * 24, 0.0);
for ($i = 0; $i < 24 * 24; $i++) {
    $lumMap[$i] = 0.299 * $pixels[$i * 3] + 0.587 * $pixels[$i * 3 + 1] + 0.114 * $pixels[$i * 3 + 2];
}
for ($y = 1; $y < 23; $y++) {
    for ($x = 1; $x < 23; $x++) {
        $tl = $lumMap[($y - 1) * 24 + ($x - 1)];
        $tc = $lumMap[($y - 1) * 24 + $x];
        $tr = $lumMap[($y - 1) * 24 + ($x + 1)];
        $ml = $lumMap[$y * 24 + ($x - 1)];
        $mr = $lumMap[$y * 24 + ($x + 1)];
        $bl = $lumMap[($y + 1) * 24 + ($x - 1)];
        $bc = $lumMap[($y + 1) * 24 + $x];
        $br = $lumMap[($y + 1) * 24 + ($x + 1)];
        $idx = $y * 24 + $x;
        $gxMap[$idx] = -$tl + $tr - 2 * $ml + 2 * $mr - $bl + $br;
        $gyMap[$idx] = -$tl - 2 * $tc - $tr + $bl + 2 * $bc + $br;
    }
}
require "artbot_scripts/urlimg.php";
// Block at col=1 (x=8-15) should have vertical edge at x=12
$char = edgeChar($gxMap, $gyMap, 8, 0, 8, 24, 24);
echo "Edge char at vertical boundary: " . ($char ?? "null") . "\n";
// Block at col=0 (x=0-7) should be flat (all black)
$char2 = edgeChar($gxMap, $gyMap, 0, 0, 8, 24, 24);
echo "Edge char in flat black: " . ($char2 ?? "null") . "\n";
'
```

Expected output:
```
Edge char at vertical boundary: |
Edge char in flat black: null
```

- [ ] **Step 2: Run php-cs-fixer**

Run: `vendor/bin/php-cs-fixer fix artbot_scripts/urlimg.php`
Then check if any changes were made. If so, commit the formatting fix.

- [ ] **Step 3: Final commit if formatting changed**

```bash
git add artbot_scripts/urlimg.php
git commit -m "style: php-cs-fixer on urlimg.php"
```
