# ASCII Color Optimization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Port IrcPalette's optimized color matching (adaptive Din99/RGB hybrid, caching, low-lumen fix) and Lab-space 4x downsampling to the `@ascii` command, replacing its brute-force inline palette.

**Architecture:** Add `limit16` support to `IrcPalette`, then rewrite the `@ascii` command in `urlimg.php` to use IrcPalette for all color matching. Default path resizes to 4x target via Imagick then does Lab block averaging; `--no-downsample` falls back to direct Imagick resize + per-pixel IrcPalette matching.

**Tech Stack:** PHP 8.1+, Imagick, `Itwmw\ColorDifference` library, existing `draw\IrcPalette` class, PHPUnit 10.

---

### Task 1: Add `limit16` parameter to `IrcPalette::nearestColorFromLab()`

**Files:**
- Modify: `library/draw/IrcPalette.php:118-144`
- Test: `tests/Canvas/IrcPaletteTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Canvas/IrcPaletteTest.php`:

```php
public function test_nearestColorFromLab_limit16_uses_only_first_16_colors(): void
{
    $fullResult = IrcPalette::nearestColorFromLab(50.0, 40.0, -30.0);
    $limitedResult = IrcPalette::nearestColorFromLab(50.0, 40.0, -30.0, true);
    $this->assertGreaterThanOrEqual(0, $limitedResult);
    $this->assertLessThanOrEqual(15, $limitedResult);
}

public function test_nearestColorFromLab_limit16_false_uses_all_99_colors(): void
{
    $result = IrcPalette::nearestColorFromLab(50.0, 40.0, -30.0, false);
    $this->assertGreaterThanOrEqual(0, $result);
    $this->assertLessThanOrEqual(98, $result);
}

public function test_nearestColorFromLab_limit16_matches_unlimited_for_palette_colors(): void
{
    $lab = IrcPalette::getLab(4);
    $fullResult = IrcPalette::nearestColorFromLab($lab[0], $lab[1], $lab[2]);
    $limitedResult = IrcPalette::nearestColorFromLab($lab[0], $lab[1], $lab[2], true);
    $this->assertSame($fullResult, $limitedResult);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Canvas/IrcPaletteTest.php --filter test_nearestColorFromLab_limit16`
Expected: FAIL (method doesn't accept `limit16` parameter yet)

- [ ] **Step 3: Write minimal implementation**

In `library/draw/IrcPalette.php`, change `nearestColorFromLab()` signature to accept `bool $limit16 = false` and iterate only indices 0-15 when set. The Lab cache key must include the limit16 state to avoid collisions:

```php
public static function nearestColorFromLab(float $L, float $a, float $b, bool $limit16 = false): int
{
    $qL = (int)round($L * 10);
    $qa = (int)round($a * 10);
    $qb = (int)round($b * 10);
    $key = "{$qL},{$qa},{$qb}" . ($limit16 ? ',16' : '');
    if (isset(self::$labCache[$key])) {
        return self::$labCache[$key];
    }

    self::$colorPalette ??= self::buildColorPalette();
    $target = new Color(new \Itwmw\ColorDifference\Lib\Lab($L, $a, $b));
    $bestIdx = 0;
    $bestDist = INF;
    $maxIdx = $limit16 ? 15 : PHP_INT_MAX;
    foreach (self::$colorPalette as $idx => $palColor) {
        if ($idx > $maxIdx) break;
        $d = self::colorDistance($target, $palColor, $L);
        if ($d < $bestDist) {
            $bestIdx = $idx;
            $bestDist = $d;
        }
    }
    self::$labCache[$key] = $bestIdx;
    if (count(self::$labCache) > 8192) {
        self::$labCache = [];
    }
    return $bestIdx;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- tests/Canvas/IrcPaletteTest.php --filter test_nearestColorFromLab_limit16`
Expected: PASS

- [ ] **Step 5: Run full test suite**

Run: `composer test`
Expected: All existing tests still pass (backward-compatible change)

- [ ] **Step 6: Commit**

```bash
git add library/draw/IrcPalette.php tests/Canvas/IrcPaletteTest.php
git commit -m "feat: add limit16 parameter to IrcPalette::nearestColorFromLab()"
```

---

### Task 2: Add `limit16` parameter to `IrcPalette::nearestColor()`

**Files:**
- Modify: `library/draw/IrcPalette.php:146-178`
- Test: `tests/Canvas/IrcPaletteTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_nearestColor_limit16_uses_only_first_16_colors(): void
{
    $result = IrcPalette::nearestColor(100, 80, 60, Dithering::None, 0, 0, true);
    $this->assertGreaterThanOrEqual(0, $result);
    $this->assertLessThanOrEqual(15, $result);
}

public function test_nearestColor_limit16_false_uses_all_99_colors(): void
{
    $result = IrcPalette::nearestColor(100, 80, 60, Dithering::None, 0, 0, false);
    $this->assertGreaterThanOrEqual(0, $result);
    $this->assertLessThanOrEqual(98, $result);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Canvas/IrcPaletteTest.php --filter test_nearestColor_limit16`
Expected: FAIL

- [ ] **Step 3: Write minimal implementation**

Add `bool $limit16 = false` parameter to `nearestColor()`. Add the `16` suffix to the cache key when limiting. The dithered/shader variants should NOT be called when limit16 is true (the `--no-downsample` path in urlimg doesn't use dithering):

```php
public static function nearestColor(int $r, int $g, int $b, Dithering $mode = Dithering::None, int $x = 0, int $y = 0, bool $limit16 = false): int
{
    if ($mode === Dithering::Ordered4x4 && !$limit16) {
        return self::nearestColorDithered($r, $g, $b, $x, $y);
    }

    if (($mode === Dithering::ShaderBlocks || $mode === Dithering::ShaderBlocksAll) && !$limit16) {
        return self::nearestColorShaderBlocksCode($r, $g, $b);
    }

    $key = (($r << 16) | ($g << 8) | $b) . ($limit16 ? 'l' : '');
    if (isset(self::$nearestCache[$key])) {
        return self::$nearestCache[$key];
    }

    self::$colorPalette ??= self::buildColorPalette();
    $target = new Color(new RGB($r, $g, $b));
    $targetL = $target->getLab()->L;
    $bestIdx = 0;
    $bestDist = INF;
    $maxIdx = $limit16 ? 15 : PHP_INT_MAX;
    foreach (self::$colorPalette as $idx => $palColor) {
        if ($idx > $maxIdx) break;
        $d = self::colorDistance($target, $palColor, $targetL);
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

Note: The cache key changes from `int` to `string` when limit16 is true. The non-limited path uses the same packed-int key format, so we append a letter for the limited case to avoid collisions. For the non-limited case we cast to string to keep the key type consistent. This means the cache type annotation changes from `array<int, int>` to `array<string|int, int>`, but since PHP array keys are coerced this works without issues.

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- tests/Canvas/IrcPaletteTest.php --filter test_nearestColor_limit16`
Expected: PASS

- [ ] **Step 5: Run full test suite**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 6: Commit**

```bash
git add library/draw/IrcPalette.php tests/Canvas/IrcPaletteTest.php
git commit -m "feat: add limit16 parameter to IrcPalette::nearestColor()"
```

---

### Task 3: Rewrite `@ascii` command to use IrcPalette with 4x Lab downsampling

**Files:**
- Modify: `artbot_scripts/urlimg.php`

This is the main task. The changes are substantial but concentrated in the `ascii()` function and removal of the inline palette + matching functions.

- [ ] **Step 1: Add import and remove inline palette**

At the top of `urlimg.php`, add `use draw\IrcPalette;` and remove the `use Itwmw\ColorDifference\Color;` and `use Itwmw\ColorDifference\Lib\RGB;` imports (they're only used by the removed palette/functions).

Remove the entire `static $palette = [...]` block (lines 139-239).

- [ ] **Step 2: Update command attributes**

Remove the `--quality`, `--lab`, `--rgb` option attributes from the `#[Options(...)]` line. Add `--no-downsample` option:

```php
#[Cmd("ascii")]
#[Syntax("<img_url> [custom_text]...")]
#[\knivey\cmdr\attributes\Desc("Generates an ascii from an image url, color matching defaults to Din99")]
#[Option("--width", "how wide to make the ascii ex --width=80")]
#[Option("--edit", "Generate a URL to open the ascii in asciibird editor")]
#[Option("--block", "Render the image with full blocks")]
#[Option("--halfblock", "Render the image with halfblocks")]
#[Option("--saturation", "change saturation value as percent, 100 is default")]
#[Option("--brightness", "change brightness value as percent, 100 is default")]
#[Option("--gamma", "adjust the gamma of the image, ex --gamma=0.8")]
#[Option("--render2", "alternate text rending for luminocity")]
#[Option("--16", "limit to only using 16 colors")]
#[Option("--no-downsample", "skip 4x Lab downsampling, use direct Imagick resize")]
```

- [ ] **Step 3: Rewrite the resize and pixel loop in `ascii()` function**

Replace the section from the `$limit` variable setup through the pixel iteration loop. The new logic:

1. Remove `$limit` variable, replace with `$limit16 = $cmdArgs->optEnabled("--16")`
2. After gamma/brightness/saturation adjustments, calculate target width/height as before
3. If `--no-downsample`: resize directly to target, iterate pixels calling `IrcPalette::nearestColor(r, g, b, Dithering::None, 0, 0, limit16)`
4. Default path: resize to `targetW * 4` x `targetH * 4`, then for each target pixel read a 4x4 block from the intermediate image, average Lab values, call `IrcPalette::nearestColorFromLab(avgL, avga, avgb, limit16)`

New resize + pixel loop section (replaces lines ~290-436):

```php
        $limit16 = false;
        if($cmdArgs->optEnabled("--16")) {
            $limit16 = true;
        }

        $img = new Imagick();
        $img->readImageBlob($body);
        if($cmdArgs->optEnabled("--gamma")) {
            $gamma = $cmdArgs->getOpt("--gamma");
            $img->gammaImage($gamma);
        }
        $brightness = 100;
        $saturation = 100;
        $hue = 100;
        if($cmdArgs->optEnabled("--saturation")) {
            $saturation = intval($cmdArgs->getOpt("--saturation"));
            if($saturation < 0 || $saturation > 10000) {
                $bot->pm($args->chan, "--saturation should be between 0 and 10000");
                return;
            }
        }
        if($cmdArgs->optEnabled("--brightness")) {
            $brightness = intval($cmdArgs->getOpt("--brightness"));
            if($brightness < 0 || $brightness > 10000) {
                $bot->pm($args->chan, "--brightness should be between 0 and 10000");
                return;
            }
        }
        $img->modulateImage($brightness, $saturation, $hue);
        $origSize = $img->getImageGeometry();
        $factor = $width / $origSize['width'];
        $targetW = (int)round($origSize['width'] * $factor);
        if($cmdArgs->optEnabled("--halfblock"))
            $targetH = (int)make_even(round($origSize['height'] * $factor));
        else
            $targetH = (int)round($origSize['height'] * $factor / 2);

        $noDownsample = $cmdArgs->optEnabled("--no-downsample");

        if ($noDownsample) {
            $img->resizeImage($targetW, $targetH, Imagick::FILTER_LANCZOS2SHARP, 0);
            $size = $img->getImageGeometry();
        } else {
            $midW = $targetW * 4;
            $midH = $targetH * 4;
            $img->resizeImage($midW, $midH, Imagick::FILTER_LANCZOS2SHARP, 0);
            $size = ['width' => $targetW, 'height' => $targetH];
        }

        $text = $cmdArgs[1];
        if($text != "") {
            $text = strtoupper($text);
            $text = str_replace(' ', '', $text);
            $words = str_split($text);
        }
        if($cmdArgs->optEnabled("--block")) {
            $words =  ["█"];
        }
        pumpToChan($bot, $args->chan, ["ok give me a few seconds to generate the ascii.."]);
        Amp\delay(0.05);

        for($row = 0; $row < $size['height']; $row++) {
            $last_match_index = -1;
            $fg = -1;
            $bg = -1;
            $hb = "\u{2580}";
            for($col = 0; $col < $size['width']; $col++) {
                $luminosity = 0.0;

                if ($noDownsample) {
                    $pixel = $img->getImagePixelColor($col, $row);
                    $luminosity = $pixel->getHSL()['luminosity'];
                    $rgba = $pixel->getColor();
                    $match_index = IrcPalette::nearestColor((int)$rgba['r'], (int)$rgba['g'], (int)$rgba['b'], \draw\Dithering::None, 0, 0, $limit16);

                    if($cmdArgs->optEnabled("--halfblock")) {
                        $pixel2 = $img->getImagePixelColor($col, $row + 1);
                        $rgba2 = $pixel2->getColor();
                        $match_index2 = IrcPalette::nearestColor((int)$rgba2['r'], (int)$rgba2['g'], (int)$rgba2['b'], \draw\Dithering::None, 0, 0, $limit16);
                    }
                } else {
                    $srcX = $col * 4;
                    $srcY = $row * 4;
                    $lSum = 0.0; $aSum = 0.0; $bSum = 0.0; $count = 0;
                    for ($sy = $srcY; $sy < $srcY + 4; $sy++) {
                        for ($sx = $srcX; $sx < $srcX + 4; $sx++) {
                            $px = $img->getImagePixelColor($sx, $sy);
                            $c = $px->getColor();
                            $labColor = new \Itwmw\ColorDifference\Color(new \Itwmw\ColorDifference\Lib\RGB((int)$c['r'], (int)$c['g'], (int)$c['b']));
                            $lab = $labColor->getLab();
                            $lSum += $lab->L;
                            $aSum += $lab->a;
                            $bSum += $lab->b;
                            $count++;
                        }
                    }
                    $match_index = IrcPalette::nearestColorFromLab($lSum / $count, $aSum / $count, $bSum / $count, $limit16);
                    $luminosity = ($lSum / $count) / 100.0;

                    if($cmdArgs->optEnabled("--halfblock")) {
                        $srcY2 = ($row + 1) * 4;
                        $lSum2 = 0.0; $aSum2 = 0.0; $bSum2 = 0.0; $count2 = 0;
                        for ($sy = $srcY2; $sy < $srcY2 + 4; $sy++) {
                            for ($sx = $srcX; $sx < $srcX + 4; $sx++) {
                                $px = $img->getImagePixelColor($sx, $sy);
                                $c = $px->getColor();
                                $labColor = new \Itwmw\ColorDifference\Color(new \Itwmw\ColorDifference\Lib\RGB((int)$c['r'], (int)$c['g'], (int)$c['b']));
                                $lab = $labColor->getLab();
                                $lSum2 += $lab->L;
                                $aSum2 += $lab->a;
                                $bSum2 += $lab->b;
                                $count2++;
                            }
                        }
                        $match_index2 = IrcPalette::nearestColorFromLab($lSum2 / $count2, $aSum2 / $count2, $bSum2 / $count2, $limit16);
                    }
                }

                if($cmdArgs->optEnabled("--halfblock")) {
                    if($match_index != $fg || $match_index2 != $bg) {
                        if($match_index == $match_index2 && $match_index2 == $bg) {
                            $img_string .= " ";
                            continue;
                        }
                        if($bg == $match_index2)
                            $img_string .= "\x03$match_index";
                        else
                            $img_string .= "\x03$match_index,$match_index2";
                        $fg = $match_index;
                        $bg = $match_index2;
                    }
                    if($match_index != $match_index2)
                        $img_string .= $hb;
                    else
                        $img_string .= " ";

                    continue;
                }

                if(isset($words)) {
                    if($match_index != $last_match_index) {
                        $img_string .= "\x03{$match_index}{$words[$pos]}";
                    }
                    else {
                        $img_string .= $words[$pos];
                    }

                    if(++$pos === count($words)) {
                        $pos = 0;
                    }
                }
                else {
                    if($cmdArgs->optEnabled('--render2')) {
                        $str_char = render2($luminosity);
                    } else {
                        $str_char = render($luminosity);
                    }
                    if($match_index != $last_match_index) {
                        $img_string .= "\x03{$match_index}{$str_char}";
                    }
                    else {
                        $img_string .= $str_char;
                    }
                }
                $last_match_index = $match_index;

            }
            if($cmdArgs->optEnabled("--halfblock"))
                $row++;
            $img_string .= "\n";
        }
```

- [ ] **Step 4: Remove the old matching functions**

Delete the 4 functions at lines 479-549:
- `getClosestMatchCIEDE2000()`
- `getClosestMatchDin99()`
- `getClosestMatchEuclideanLab()`
- `getClosestMatchEuclideanRGB()`

- [ ] **Step 5: Run static analysis**

Run: `composer phpstan`
Expected: No errors related to the changes

- [ ] **Step 6: Run tests**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 7: Commit**

```bash
git add artbot_scripts/urlimg.php
git commit -m "feat: rewrite @ascii to use IrcPalette with 4x Lab downsampling

- Replace inline 99-color palette and 4 brute-force matching functions
  with IrcPalette adaptive Din99/RGB hybrid (low-lumen fix, caching)
- Add 4x oversample + Lab-space block averaging as default downscale
- Add --no-downsample flag to fall back to direct Imagick resize
- Remove --quality, --lab, --rgb flags
- Keep --16 flag via IrcPalette limit16 support"
```

---

### Design Spec Cross-Reference

| Spec requirement | Task |
|---|---|
| Remove inline palette + 4 matchers | Task 3 |
| Remove --quality, --lab, --rgb flags | Task 3 |
| Add --no-downsample flag | Task 3 |
| Keep --16 flag | Tasks 1, 2, 3 |
| Add limit16 to nearestColorFromLab | Task 1 |
| Add limit16 to nearestColor | Task 2 |
| 4x oversample + Lab block averaging (default) | Task 3 |
| Direct resize + IrcPalette (no-downsample) | Task 3 |
| Keep all other flags unchanged | Task 3 |
