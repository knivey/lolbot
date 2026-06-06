# SVG Filters Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add SVG `<filter>` element support with five filter primitives (feGaussianBlur, feOffset, feColorMatrix, feMerge, feDropShadow) to the draw library's rendering pipeline.

**Architecture:** Each filter primitive is a class implementing `FilterPrimitive` interface. A `FilterNode` (SceneNode wrapper) renders its child to an offscreen buffer, runs the primitive chain via `FilterPipeline` (named result routing), then composites back. Follows the ClipNode/MaskNode pattern established in milestone 10.

**Tech Stack:** PHP 8.1+, PHPUnit 10, no new dependencies.

**Spec:** `docs/superpowers/specs/2026-06-06-svg-filters-design.md`

---

## File Structure

### New files

| File | Responsibility |
|------|----------------|
| `library/draw/FilterPrimitive.php` | Interface: `getInput()`, `getResult()`, `apply()` |
| `library/draw/FilterRegion.php` | Value object: x, y, width, height (fractional/absolute) |
| `library/draw/FilterPipeline.php` | Named result routing + built-in source canvas management |
| `library/draw/FilterNode.php` | SceneNode wrapper: renders child to offscreen, runs primitives, composites back |
| `library/draw/GaussianBlurPrimitive.php` | feGaussianBlur: 3-pass box blur in RGB space |
| `library/draw/OffsetPrimitive.php` | feOffset: pixel translation by dx/dy |
| `library/draw/ColorMatrixPrimitive.php` | feColorMatrix: per-pixel matrix (4 types) |
| `library/draw/MergePrimitive.php` | feMerge: composite multiple named inputs |
| `library/draw/DropShadowPrimitive.php` | feDropShadow: shorthand expanding to blur+offset+flood+mask+merge |
| `tests/Canvas/FilterPrimitiveTest.php` | Tests for all primitives + FilterPipeline + FilterRegion |
| `tests/Canvas/FilterNodeTest.php` | Tests for FilterNode (scene tree integration) |

### Modified files

| File | Change |
|------|--------|
| `library/draw/SVGParser.php` | Harvest `<filter>` in collectAllDefs, parse filter elements, wrap with FilterNode |
| `library/draw/ClipNode.php` | Add `FilterNode` case to `computeBbox()` |
| `tests/Canvas/SVGParserTest.php` | Add filter parsing tests |

---

### Task 1: FilterPrimitive interface + FilterRegion value object

**Files:**
- Create: `library/draw/FilterPrimitive.php`
- Create: `library/draw/FilterRegion.php`
- Test: `tests/Canvas/FilterPrimitiveTest.php`

- [ ] **Step 1: Write tests for FilterRegion**

```php
<?php

namespace Tests\Canvas;

use draw\FilterRegion;
use PHPUnit\Framework\TestCase;

class FilterPrimitiveTest extends TestCase
{
    public function test_filter_region_defaults(): void
    {
        $region = FilterRegion::defaults();
        $this->assertSame(-0.1, $region->x);
        $this->assertSame(-0.1, $region->y);
        $this->assertSame(1.2, $region->width);
        $this->assertSame(1.2, $region->height);
    }

    public function test_filter_region_custom_values(): void
    {
        $region = new FilterRegion(0.0, 0.0, 1.0, 1.0);
        $this->assertSame(0.0, $region->x);
        $this->assertSame(0.0, $region->y);
        $this->assertSame(1.0, $region->width);
        $this->assertSame(1.0, $region->height);
    }

    public function test_filter_region_to_absolute_with_bbox(): void
    {
        $region = new FilterRegion(-0.1, -0.1, 1.2, 1.2);
        $absolute = $region->toAbsolute(10.0, 5.0, 20.0, 10.0);
        $this->assertSame(8.0, $absolute['x']);
        $this->assertSame(4.0, $absolute['y']);
        $this->assertSame(24.0, $absolute['width']);
        $this->assertSame(12.0, $absolute['height']);
    }

    public function test_filter_region_to_absolute_identity(): void
    {
        $region = new FilterRegion(0.0, 0.0, 1.0, 1.0);
        $absolute = $region->toAbsolute(10.0, 5.0, 20.0, 10.0);
        $this->assertSame(10.0, $absolute['x']);
        $this->assertSame(5.0, $absolute['y']);
        $this->assertSame(20.0, $absolute['width']);
        $this->assertSame(10.0, $absolute['height']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- tests/Canvas/FilterPrimitiveTest.php`
Expected: FAIL — classes do not exist

- [ ] **Step 3: Implement FilterPrimitive interface**

Create `library/draw/FilterPrimitive.php`:

```php
<?php

namespace draw;

interface FilterPrimitive
{
    public function getInput(): ?string;

    public function getResult(): ?string;

    public function apply(Canvas $input, FilterPipeline $pipeline): Canvas;
}
```

- [ ] **Step 4: Implement FilterRegion value object**

Create `library/draw/FilterRegion.php`:

```php
<?php

namespace draw;

class FilterRegion
{
    public function __construct(
        public readonly float $x,
        public readonly float $y,
        public readonly float $width,
        public readonly float $height,
    ) {
    }

    public static function defaults(): self
    {
        return new self(-0.1, -0.1, 1.2, 1.2);
    }

    public function toAbsolute(float $bboxX, float $bboxY, float $bboxW, float $bboxH): array
    {
        return [
            'x' => $bboxX + $this->x * $bboxW,
            'y' => $bboxY + $this->y * $bboxH,
            'width' => $this->width * $bboxW,
            'height' => $this->height * $bboxH,
        ];
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `composer test -- tests/Canvas/FilterPrimitiveTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add library/draw/FilterPrimitive.php library/draw/FilterRegion.php tests/Canvas/FilterPrimitiveTest.php
git commit -m "Add FilterPrimitive interface and FilterRegion value object"
```

---

### Task 2: FilterPipeline — named result routing + built-in sources

**Files:**
- Create: `library/draw/FilterPipeline.php`
- Modify: `tests/Canvas/FilterPrimitiveTest.php`

- [ ] **Step 1: Write tests for FilterPipeline**

Append to `tests/Canvas/FilterPrimitiveTest.php`:

```php
use draw\FilterPipeline;
use draw\Pixel;

    public function test_pipeline_stores_named_result(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(5, 5, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $result = Canvas::createBlank(10, 10);
        $result->drawPoint(3, 3, new Color(0, null));
        $pipeline->setResult('blur1', $result);

        $this->assertSame($result, $pipeline->getResult('blur1'));
    }

    public function test_pipeline_provides_source_graphic(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(5, 5, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $sg = $pipeline->getResult('SourceGraphic');

        $this->assertSame(4, $sg->data[5][5]->fg);
        $this->assertNull($sg->data[0][0]->fg);
    }

    public function test_pipeline_provides_source_alpha(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(5, 5, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $sa = $pipeline->getResult('SourceAlpha');

        $pixel = $sa->data[5][5];
        $this->assertNotNull($pixel->fg);
        $rgb = IrcPalette::getRgb($pixel->fg);
        $this->assertSame(255, $rgb[0]);
        $this->assertSame(255, $rgb[1]);
        $this->assertSame(255, $rgb[2]);
    }

    public function test_pipeline_source_alpha_empty_where_source_empty(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(5, 5, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $sa = $pipeline->getResult('SourceAlpha');

        $this->assertNull($sa->data[0][0]->fg);
    }

    public function test_pipeline_background_image_is_empty(): void
    {
        $source = Canvas::createBlank(10, 10);
        $pipeline = new FilterPipeline($source);
        $bg = $pipeline->getResult('BackgroundImage');

        $this->assertNull($bg->data[0][0]->fg);
    }

    public function test_pipeline_background_alpha_is_empty(): void
    {
        $source = Canvas::createBlank(10, 10);
        $pipeline = new FilterPipeline($source);
        $ba = $pipeline->getResult('BackgroundAlpha');

        $this->assertNull($ba->data[0][0]->fg);
    }

    public function test_pipeline_returns_null_for_unknown_result(): void
    {
        $source = Canvas::createBlank(10, 10);
        $pipeline = new FilterPipeline($source);

        $this->assertNull($pipeline->getResult('nonexistent'));
    }

    public function test_pipeline_last_result_tracks_output(): void
    {
        $source = Canvas::createBlank(10, 10);
        $pipeline = new FilterPipeline($source);

        $this->assertSame($source, $pipeline->getLastResult());

        $result = Canvas::createBlank(10, 10);
        $result->drawPoint(3, 3, new Color(0, null));
        $pipeline->setResult('step1', $result);

        $this->assertSame($result, $pipeline->getLastResult());
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- tests/Canvas/FilterPrimitiveTest.php`
Expected: FAIL — `FilterPipeline` class does not exist

- [ ] **Step 3: Implement FilterPipeline**

Create `library/draw/FilterPipeline.php`:

```php
<?php

namespace draw;

class FilterPipeline
{
    private array $results = [];
    private ?Canvas $lastResult = null;
    private bool $bgWarned = false;

    public function __construct(
        Canvas $sourceGraphic,
        private readonly ?\Psr\Log\LoggerInterface $logger = null,
    ) {
        $this->results['SourceGraphic'] = $sourceGraphic;
        $this->lastResult = $sourceGraphic;
    }

    public function getResult(string $name): ?Canvas
    {
        if (isset($this->results[$name])) {
            return $this->results[$name];
        }

        return match ($name) {
            'SourceAlpha' => $this->buildSourceAlpha(),
            'BackgroundImage' => $this->buildEmptyCanvas('BackgroundImage'),
            'BackgroundAlpha' => $this->buildEmptyCanvas('BackgroundAlpha'),
            default => null,
        };
    }

    public function setResult(string $name, Canvas $canvas): void
    {
        $this->results[$name] = $canvas;
        $this->lastResult = $canvas;
    }

    public function getLastResult(): Canvas
    {
        return $this->lastResult;
    }

    private function buildSourceAlpha(): Canvas
    {
        $source = $this->results['SourceGraphic'];
        $alpha = Canvas::createBlank($source->w, $source->h, $source->halfblocks);

        for ($y = 0; $y < $source->h; $y++) {
            for ($x = 0; $x < $source->w; $x++) {
                $sp = $source->data[$y][$x];
                $dp = $alpha->data[$y][$x];
                if ($sp->fg !== null) {
                    $dp->fg = Color::White;
                    $dp->fgAlpha = $sp->fgAlpha;
                }
                if ($sp->bg !== null) {
                    $dp->bg = Color::White;
                    $dp->bgAlpha = $sp->bgAlpha;
                }
            }
        }

        $this->results['SourceAlpha'] = $alpha;
        return $alpha;
    }

    private function buildEmptyCanvas(string $sourceName): Canvas
    {
        if (!$this->bgWarned) {
            $this->logger?->warning("SVG filter source '{$sourceName}' is not supported, using empty canvas");
            $this->bgWarned = true;
        }
        $source = $this->results['SourceGraphic'];
        $empty = Canvas::createBlank($source->w, $source->h, $source->halfblocks);
        return $empty;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test -- tests/Canvas/FilterPrimitiveTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add library/draw/FilterPipeline.php tests/Canvas/FilterPrimitiveTest.php
git commit -m "Add FilterPipeline with named result routing and built-in sources"
```

---

### Task 3: OffsetPrimitive

**Files:**
- Create: `library/draw/OffsetPrimitive.php`
- Modify: `tests/Canvas/FilterPrimitiveTest.php`

- [ ] **Step 1: Write tests for OffsetPrimitive**

Append to `tests/Canvas/FilterPrimitiveTest.php`:

```php
use draw\OffsetPrimitive;

    public function test_offset_shifts_pixels_right_and_down(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(2, 2, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $primitive = new OffsetPrimitive(3.0, 2.0);
        $result = $primitive->apply($source, $pipeline);

        $this->assertNull($result->data[2][2]->fg);
        $this->assertSame(4, $result->data[4][5]->fg);
    }

    public function test_offset_negative_values(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(7, 7, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $primitive = new OffsetPrimitive(-3.0, -2.0);
        $result = $primitive->apply($source, $pipeline);

        $this->assertNull($result->data[7][7]->fg);
        $this->assertSame(4, $result->data[5][4]->fg);
    }

    public function test_offset_out_of_bounds_pixels_lost(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(1, 1, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $primitive = new OffsetPrimitive(-5.0, -5.0);
        $result = $primitive->apply($source, $pipeline);

        $this->assertNull($result->data[0][0]->fg);
    }

    public function test_offset_with_named_input(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(2, 2, new Color(4, null));

        $other = Canvas::createBlank(10, 10);
        $other->drawPoint(5, 5, new Color(0, null));

        $pipeline = new FilterPipeline($source);
        $pipeline->setResult('other', $other);

        $primitive = new OffsetPrimitive(1.0, 0.0, input: 'other');
        $result = $primitive->apply($other, $pipeline);

        $this->assertNull($result->data[5][5]->fg);
        $this->assertSame(0, $result->data[5][6]->fg);
    }

    public function test_offset_stores_named_result(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(2, 2, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $primitive = new OffsetPrimitive(1.0, 0.0, result: 'shifted');
        $result = $primitive->apply($source, $pipeline);

        $this->assertSame($result, $pipeline->getResult('shifted'));
    }

    public function test_offset_zero_produces_copy(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(5, 5, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $primitive = new OffsetPrimitive(0.0, 0.0);
        $result = $primitive->apply($source, $pipeline);

        $this->assertSame(4, $result->data[5][5]->fg);
        $this->assertNotSame($source, $result);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- tests/Canvas/FilterPrimitiveTest.php`
Expected: FAIL — `OffsetPrimitive` class does not exist

- [ ] **Step 3: Implement OffsetPrimitive**

Create `library/draw/OffsetPrimitive.php`:

```php
<?php

namespace draw;

class OffsetPrimitive implements FilterPrimitive
{
    public function __construct(
        private readonly float $dx,
        private readonly float $dy,
        private readonly ?string $input = null,
        private readonly ?string $result = null,
    ) {
    }

    public function getInput(): ?string
    {
        return $this->input;
    }

    public function getResult(): ?string
    {
        return $this->result;
    }

    public function apply(Canvas $input, FilterPipeline $pipeline): Canvas
    {
        $output = Canvas::createBlank($input->w, $input->h, $input->halfblocks);

        $shiftX = (int) round($this->dx);
        $shiftY = (int) round($this->dy);

        for ($y = 0; $y < $input->h; $y++) {
            for ($x = 0; $x < $input->w; $x++) {
                $srcX = $x - $shiftX;
                $srcY = $y - $shiftY;
                if ($srcX < 0 || $srcX >= $input->w || $srcY < 0 || $srcY >= $input->h) {
                    continue;
                }
                $sp = $input->data[$srcY][$srcX];
                if ($sp->fg === null && $sp->bg === null) {
                    continue;
                }
                $dp = $output->data[$y][$x];
                if ($sp->fg !== null) {
                    $dp->fg = $sp->fg;
                    $dp->fgAlpha = $sp->fgAlpha;
                    $dp->dithered = $sp->dithered;
                    $dp->secondBest = $sp->secondBest;
                    $dp->t = $sp->t;
                }
                if ($sp->bg !== null) {
                    $dp->bg = $sp->bg;
                    $dp->bgAlpha = $sp->bgAlpha;
                }
                if ($sp->text !== ' ') {
                    $dp->text = $sp->text;
                }
            }
        }

        if ($this->result !== null) {
            $pipeline->setResult($this->result, $output);
        }

        return $output;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test -- tests/Canvas/FilterPrimitiveTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add library/draw/OffsetPrimitive.php tests/Canvas/FilterPrimitiveTest.php
git commit -m "Add OffsetPrimitive (feOffset) filter primitive"
```

---

### Task 4: GaussianBlurPrimitive

**Files:**
- Create: `library/draw/GaussianBlurPrimitive.php`
- Modify: `tests/Canvas/FilterPrimitiveTest.php`

- [ ] **Step 1: Write tests for GaussianBlurPrimitive**

Append to `tests/Canvas/FilterPrimitiveTest.php`:

```php
use draw\GaussianBlurPrimitive;

    public function test_blur_spreads_single_pixel(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(5, 5, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $primitive = new GaussianBlurPrimitive(1.0);
        $result = $primitive->apply($source, $pipeline);

        $this->assertNotNull($result->data[5][5]->fg);
        $this->assertNotNull($result->data[5][4]->fg, 'Pixel to the left should have color from blur spread');
        $this->assertNotNull($result->data[5][6]->fg, 'Pixel to the right should have color from blur spread');
    }

    public function test_blur_zero_stddev_returns_same_image(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(5, 5, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $primitive = new GaussianBlurPrimitive(0.0);
        $result = $primitive->apply($source, $pipeline);

        $this->assertSame(4, $result->data[5][5]->fg);
        $this->assertNull($result->data[5][6]->fg);
    }

    public function test_blur_preserves_transparency(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(5, 5, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $primitive = new GaussianBlurPrimitive(1.0);
        $result = $primitive->apply($source, $pipeline);

        $this->assertNull($result->data[0][0]->fg, 'Far-away pixels should remain transparent');
    }

    public function test_blur_large_radius_creates_wide_spread(): void
    {
        $source = Canvas::createBlank(20, 10);
        $source->drawPoint(10, 5, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $primitive = new GaussianBlurPrimitive(3.0);
        $result = $primitive->apply($source, $pipeline);

        $filledCount = 0;
        for ($y = 0; $y < 10; $y++) {
            for ($x = 0; $x < 20; $x++) {
                if ($result->data[$y][$x]->fg !== null) {
                    $filledCount++;
                }
            }
        }
        $this->assertGreaterThan(1, $filledCount, 'Large blur should spread to many pixels');
    }

    public function test_blur_with_named_input_and_result(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(5, 5, new Color(4, null));

        $other = Canvas::createBlank(10, 10);
        $other->drawPoint(3, 3, new Color(0, null));

        $pipeline = new FilterPipeline($source);
        $pipeline->setResult('other', $other);

        $primitive = new GaussianBlurPrimitive(1.0, input: 'other', result: 'blurred');
        $result = $primitive->apply($other, $pipeline);

        $this->assertSame($result, $pipeline->getResult('blurred'));
        $this->assertNotNull($result->data[3][3]->fg);
    }

    public function test_blur_handles_bg_channel(): void
    {
        $source = Canvas::createBlank(10, 10);
        $p = new Pixel();
        $p->bg = 4;
        $p->bgAlpha = 1.0;
        $source->data[5][5] = $p;

        $pipeline = new FilterPipeline($source);
        $primitive = new GaussianBlurPrimitive(1.0);
        $result = $primitive->apply($source, $pipeline);

        $this->assertNotNull($result->data[5][4]->bg, 'Blur should spread bg channel');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- tests/Canvas/FilterPrimitiveTest.php`
Expected: FAIL — `GaussianBlurPrimitive` class does not exist

- [ ] **Step 3: Implement GaussianBlurPrimitive**

Create `library/draw/GaussianBlurPrimitive.php`:

```php
<?php

namespace draw;

class GaussianBlurPrimitive implements FilterPrimitive
{
    public function __construct(
        private readonly float $stdDeviation,
        private readonly ?string $input = null,
        private readonly ?string $result = null,
    ) {
    }

    public function getInput(): ?string
    {
        return $this->input;
    }

    public function getResult(): ?string
    {
        return $this->result;
    }

    public function apply(Canvas $input, FilterPipeline $pipeline): Canvas
    {
        if ($this->stdDeviation < 0.001) {
            $output = Canvas::createBlank($input->w, $input->h, $input->halfblocks);
            Compositor::blend($output, $input);
            if ($this->result !== null) {
                $pipeline->setResult($this->result, $output);
            }
            return $output;
        }

        $boxRadius = (int) floor($this->stdDeviation * sqrt(12.0 / 3.0) / 2.0 + 0.5);
        if ($boxRadius < 1) {
            $boxRadius = 1;
        }

        $pass1 = self::boxBlurH($input, $boxRadius);
        $pass2 = self::boxBlurV($pass1, $boxRadius);
        $output = self::boxBlurH($pass2, $boxRadius);

        if ($this->result !== null) {
            $pipeline->setResult($this->result, $output);
        }

        return $output;
    }

    private static function boxBlurH(Canvas $src, int $radius): Canvas
    {
        $dst = Canvas::createBlank($src->w, $src->h, $src->halfblocks);
        $kernelSize = 2 * $radius + 1;

        for ($y = 0; $y < $src->h; $y++) {
            for ($x = 0; $x < $src->w; $x++) {
                $fgR = 0.0;
                $fgG = 0.0;
                $fgB = 0.0;
                $fgCount = 0;

                $bgR = 0.0;
                $bgG = 0.0;
                $bgB = 0.0;
                $bgCount = 0;

                for ($k = -$radius; $k <= $radius; $k++) {
                    $sx = $x + $k;
                    if ($sx < 0) {
                        $sx = 0;
                    }
                    if ($sx >= $src->w) {
                        $sx = $src->w - 1;
                    }

                    $sp = $src->data[$y][$sx];

                    if ($sp->fg !== null) {
                        $rgb = IrcPalette::getRgb($sp->fg);
                        $fgR += $rgb[0];
                        $fgG += $rgb[1];
                        $fgB += $rgb[2];
                        $fgCount++;
                    }

                    if ($sp->bg !== null) {
                        $rgb = IrcPalette::getRgb($sp->bg);
                        $bgR += $rgb[0];
                        $bgG += $rgb[1];
                        $bgB += $rgb[2];
                        $bgCount++;
                    }
                }

                $dp = $dst->data[$y][$x];
                if ($fgCount > 0) {
                    $dp->fg = IrcPalette::nearestColor(
                        (int) round($fgR / $fgCount),
                        (int) round($fgG / $fgCount),
                        (int) round($fgB / $fgCount),
                    );
                    $dp->fgAlpha = 1.0;
                }
                if ($bgCount > 0) {
                    $dp->bg = IrcPalette::nearestColor(
                        (int) round($bgR / $bgCount),
                        (int) round($bgG / $bgCount),
                        (int) round($bgB / $bgCount),
                    );
                    $dp->bgAlpha = 1.0;
                }
            }
        }

        return $dst;
    }

    private static function boxBlurV(Canvas $src, int $radius): Canvas
    {
        $dst = Canvas::createBlank($src->w, $src->h, $src->halfblocks);
        $kernelSize = 2 * $radius + 1;

        for ($y = 0; $y < $src->h; $y++) {
            for ($x = 0; $x < $src->w; $x++) {
                $fgR = 0.0;
                $fgG = 0.0;
                $fgB = 0.0;
                $fgCount = 0;

                $bgR = 0.0;
                $bgG = 0.0;
                $bgB = 0.0;
                $bgCount = 0;

                for ($k = -$radius; $k <= $radius; $k++) {
                    $sy = $y + $k;
                    if ($sy < 0) {
                        $sy = 0;
                    }
                    if ($sy >= $src->h) {
                        $sy = $src->h - 1;
                    }

                    $sp = $src->data[$sy][$x];

                    if ($sp->fg !== null) {
                        $rgb = IrcPalette::getRgb($sp->fg);
                        $fgR += $rgb[0];
                        $fgG += $rgb[1];
                        $fgB += $rgb[2];
                        $fgCount++;
                    }

                    if ($sp->bg !== null) {
                        $rgb = IrcPalette::getRgb($sp->bg);
                        $bgR += $rgb[0];
                        $bgG += $rgb[1];
                        $bgB += $rgb[2];
                        $bgCount++;
                    }
                }

                $dp = $dst->data[$y][$x];
                if ($fgCount > 0) {
                    $dp->fg = IrcPalette::nearestColor(
                        (int) round($fgR / $fgCount),
                        (int) round($fgG / $fgCount),
                        (int) round($fgB / $fgCount),
                    );
                    $dp->fgAlpha = 1.0;
                }
                if ($bgCount > 0) {
                    $dp->bg = IrcPalette::nearestColor(
                        (int) round($bgR / $bgCount),
                        (int) round($bgG / $bgCount),
                        (int) round($bgB / $bgCount),
                    );
                    $dp->bgAlpha = 1.0;
                }
            }
        }

        return $dst;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test -- tests/Canvas/FilterPrimitiveTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add library/draw/GaussianBlurPrimitive.php tests/Canvas/FilterPrimitiveTest.php
git commit -m "Add GaussianBlurPrimitive (feGaussianBlur) with 3-pass box blur"
```

---

### Task 5: ColorMatrixPrimitive

**Files:**
- Create: `library/draw/ColorMatrixPrimitive.php`
- Modify: `tests/Canvas/FilterPrimitiveTest.php`

- [ ] **Step 1: Write tests for ColorMatrixPrimitive**

Append to `tests/Canvas/FilterPrimitiveTest.php`:

```php
use draw\ColorMatrixPrimitive;

    public function test_color_matrix_identity_preserves_color(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(5, 5, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $matrix = [
            1, 0, 0, 0, 0,
            0, 1, 0, 0, 0,
            0, 0, 1, 0, 0,
            0, 0, 0, 1, 0,
        ];
        $primitive = new ColorMatrixPrimitive('matrix', $matrix);
        $result = $primitive->apply($source, $pipeline);

        $this->assertSame(4, $result->data[5][5]->fg);
    }

    public function test_color_matrix_saturate_zero_produces_grey(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(5, 5, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $primitive = new ColorMatrixPrimitive('saturate', [0.0]);
        $result = $primitive->apply($source, $pipeline);

        $this->assertNotNull($result->data[5][5]->fg);
        $rgb = IrcPalette::getRgb($result->data[5][5]->fg);
        $this->assertEqualsWithDelta($rgb[0], $rgb[1], 5, 'R and G should be close in desaturated');
        $this->assertEqualsWithDelta($rgb[1], $rgb[2], 5, 'G and B should be close in desaturated');
    }

    public function test_color_matrix_saturate_one_preserves_color(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(5, 5, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $primitive = new ColorMatrixPrimitive('saturate', [1.0]);
        $result = $primitive->apply($source, $pipeline);

        $this->assertSame(4, $result->data[5][5]->fg);
    }

    public function test_color_matrix_hue_rotate_shifts_color(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(5, 5, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $originalRgb = IrcPalette::getRgb(4);

        $primitive = new ColorMatrixPrimitive('hueRotate', [90.0]);
        $result = $primitive->apply($source, $pipeline);

        $this->assertNotNull($result->data[5][5]->fg);
        $resultRgb = IrcPalette::getRgb($result->data[5][5]->fg);
        $this->assertFalse(
            $resultRgb[0] === $originalRgb[0] && $resultRgb[1] === $originalRgb[1] && $resultRgb[2] === $originalRgb[2],
            'Hue rotation should change the color',
        );
    }

    public function test_color_matrix_luminance_to_alpha(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(5, 5, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $primitive = new ColorMatrixPrimitive('luminanceToAlpha', []);
        $result = $primitive->apply($source, $pipeline);

        $pixel = $result->data[5][5];
        $this->assertNotNull($pixel->fg);
        $this->assertLessThan(1.0, $pixel->fgAlpha, 'Luminance to alpha should reduce alpha');
    }

    public function test_color_matrix_skips_transparent_pixels(): void
    {
        $source = Canvas::createBlank(10, 10);

        $pipeline = new FilterPipeline($source);
        $primitive = new ColorMatrixPrimitive('saturate', [0.0]);
        $result = $primitive->apply($source, $pipeline);

        $this->assertNull($result->data[5][5]->fg);
    }

    public function test_color_matrix_handles_bg_channel(): void
    {
        $source = Canvas::createBlank(10, 10);
        $p = new Pixel();
        $p->bg = 4;
        $p->bgAlpha = 1.0;
        $source->data[5][5] = $p;

        $pipeline = new FilterPipeline($source);
        $primitive = new ColorMatrixPrimitive('saturate', [0.0]);
        $result = $primitive->apply($source, $pipeline);

        $this->assertNotNull($result->data[5][5]->bg);
        $rgb = IrcPalette::getRgb($result->data[5][5]->bg);
        $this->assertEqualsWithDelta($rgb[0], $rgb[1], 5, 'Desaturated bg should be grey');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- tests/Canvas/FilterPrimitiveTest.php`
Expected: FAIL — `ColorMatrixPrimitive` class does not exist

- [ ] **Step 3: Implement ColorMatrixPrimitive**

Create `library/draw/ColorMatrixPrimitive.php`:

```php
<?php

namespace draw;

class ColorMatrixPrimitive implements FilterPrimitive
{
    public function __construct(
        private readonly string $type,
        private readonly array $values,
        private readonly ?string $input = null,
        private readonly ?string $result = null,
    ) {
    }

    public function getInput(): ?string
    {
        return $this->input;
    }

    public function getResult(): ?string
    {
        return $this->result;
    }

    public function apply(Canvas $input, FilterPipeline $pipeline): Canvas
    {
        $matrix = $this->buildMatrix();
        $output = Canvas::createBlank($input->w, $input->h, $input->halfblocks);

        for ($y = 0; $y < $input->h; $y++) {
            for ($x = 0; $x < $input->w; $x++) {
                $sp = $input->data[$y][$x];
                $dp = $output->data[$y][$x];

                if ($sp->fg !== null) {
                    $rgb = IrcPalette::getRgb($sp->fg);
                    $this->applyMatrix($dp, 'fg', $rgb, $sp->fgAlpha, $matrix);
                }

                if ($sp->bg !== null) {
                    $rgb = IrcPalette::getRgb($sp->bg);
                    $this->applyMatrix($dp, 'bg', $rgb, $sp->bgAlpha, $matrix);
                }
            }
        }

        if ($this->result !== null) {
            $pipeline->setResult($this->result, $output);
        }

        return $output;
    }

    private function buildMatrix(): array
    {
        return match ($this->type) {
            'matrix' => $this->values,
            'saturate' => $this->buildSaturateMatrix($this->values[0] ?? 1.0),
            'hueRotate' => $this->buildHueRotateMatrix($this->values[0] ?? 0.0),
            'luminanceToAlpha' => [
                0, 0, 0, 0, 0,
                0, 0, 0, 0, 0,
                0, 0, 0, 0, 0,
                0.2126, 0.7152, 0.0722, 0, 0,
            ],
            default => [
                1, 0, 0, 0, 0,
                0, 1, 0, 0, 0,
                0, 0, 1, 0, 0,
                0, 0, 0, 1, 0,
            ],
        };
    }

    private function buildSaturateMatrix(float $s): array
    {
        return [
            0.213 + 0.787 * $s, 0.715 - 0.715 * $s, 0.072 - 0.072 * $s, 0, 0,
            0.213 - 0.213 * $s, 0.715 + 0.285 * $s, 0.072 - 0.072 * $s, 0, 0,
            0.213 - 0.213 * $s, 0.715 - 0.715 * $s, 0.072 + 0.928 * $s, 0, 0,
            0, 0, 0, 1, 0,
        ];
    }

    private function buildHueRotateMatrix(float $angle): array
    {
        $rad = deg2rad($angle);
        $cos = cos($rad);
        $sin = sin($rad);

        $a00 = 0.213 + $cos * 0.787 - $sin * 0.213;
        $a01 = 0.715 - $cos * 0.715 - $sin * 0.715;
        $a02 = 0.072 - $cos * 0.072 + $sin * 0.928;

        $a10 = 0.213 - $cos * 0.213 + $sin * 0.143;
        $a11 = 0.715 + $cos * 0.285 + $sin * 0.140;
        $a12 = 0.072 - $cos * 0.072 - $sin * 0.283;

        $a20 = 0.213 - $cos * 0.213 - $sin * 0.787;
        $a21 = 0.715 - $cos * 0.715 + $sin * 0.715;
        $a22 = 0.072 + $cos * 0.928 + $sin * 0.072;

        return [
            $a00, $a01, $a02, 0, 0,
            $a10, $a11, $a12, 0, 0,
            $a20, $a21, $a22, 0, 0,
            0, 0, 0, 1, 0,
        ];
    }

    private function applyMatrix(Pixel $dp, string $channel, array $rgb, float $alpha, array $m): void
    {
        $r = $rgb[0] / 255.0;
        $g = $rgb[1] / 255.0;
        $b = $rgb[2] / 255.0;
        $a = $alpha;

        $nr = $m[0] * $r + $m[1] * $g + $m[2] * $b + $m[3] * $a + $m[4];
        $ng = $m[5] * $r + $m[6] * $g + $m[7] * $b + $m[8] * $a + $m[9];
        $nb = $m[10] * $r + $m[11] * $g + $m[12] * $b + $m[13] * $a + $m[14];
        $na = $m[15] * $r + $m[16] * $g + $m[17] * $b + $m[18] * $a + $m[19];

        $ir = (int) round(max(0, min(255, $nr * 255.0)));
        $ig = (int) round(max(0, min(255, $ng * 255.0)));
        $ib = (int) round(max(0, min(255, $nb * 255.0)));
        $ia = max(0.0, min(1.0, $na));

        $code = IrcPalette::nearestColor($ir, $ig, $ib);

        if ($channel === 'fg') {
            $dp->fg = $code;
            $dp->fgAlpha = $ia;
        } else {
            $dp->bg = $code;
            $dp->bgAlpha = $ia;
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test -- tests/Canvas/FilterPrimitiveTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add library/draw/ColorMatrixPrimitive.php tests/Canvas/FilterPrimitiveTest.php
git commit -m "Add ColorMatrixPrimitive (feColorMatrix) with matrix/saturate/hueRotate/luminanceToAlpha"
```

---

### Task 6: MergePrimitive

**Files:**
- Create: `library/draw/MergePrimitive.php`
- Modify: `tests/Canvas/FilterPrimitiveTest.php`

- [ ] **Step 1: Write tests for MergePrimitive**

Append to `tests/Canvas/FilterPrimitiveTest.php`:

```php
use draw\MergePrimitive;

    public function test_merge_combines_two_inputs(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(3, 3, new Color(4, null));

        $pipeline = new FilterPipeline($source);

        $other = Canvas::createBlank(10, 10);
        $other->drawPoint(7, 7, new Color(0, null));
        $pipeline->setResult('shadow', $other);

        $primitive = new MergePrimitive(['SourceGraphic', 'shadow']);
        $result = $primitive->apply($source, $pipeline);

        $this->assertSame(4, $result->data[3][3]->fg);
        $this->assertSame(0, $result->data[7][7]->fg);
    }

    public function test_merge_single_input_copies(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(5, 5, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $primitive = new MergePrimitive(['SourceGraphic']);
        $result = $primitive->apply($source, $pipeline);

        $this->assertSame(4, $result->data[5][5]->fg);
    }

    public function test_merge_empty_inputs_produces_blank(): void
    {
        $source = Canvas::createBlank(10, 10);

        $pipeline = new FilterPipeline($source);
        $primitive = new MergePrimitive([]);
        $result = $primitive->apply($source, $pipeline);

        $this->assertNull($result->data[5][5]->fg);
    }

    public function test_merge_overlapping_pixels_blend(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(5, 5, new Color(4, null));

        $pipeline = new FilterPipeline($source);

        $other = Canvas::createBlank(10, 10);
        $other->drawPoint(5, 5, new Color(0, null));
        $pipeline->setResult('layer2', $other);

        $primitive = new MergePrimitive(['layer2', 'SourceGraphic']);
        $result = $primitive->apply($source, $pipeline);

        $this->assertNotNull($result->data[5][5]->fg);
        $this->assertNotSame(4, $result->data[5][5]->fg, 'Second input should overwrite first');
    }

    public function test_merge_with_named_result(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(5, 5, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $primitive = new MergePrimitive(['SourceGraphic'], result: 'merged');
        $result = $primitive->apply($source, $pipeline);

        $this->assertSame($result, $pipeline->getResult('merged'));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- tests/Canvas/FilterPrimitiveTest.php`
Expected: FAIL — `MergePrimitive` class does not exist

- [ ] **Step 3: Implement MergePrimitive**

Create `library/draw/MergePrimitive.php`:

```php
<?php

namespace draw;

class MergePrimitive implements FilterPrimitive
{
    private readonly ?string $inputName;

    public function __construct(
        private readonly array $mergeInputs,
        private readonly ?string $result = null,
    ) {
        $this->inputName = $mergeInputs[0] ?? null;
    }

    public function getInput(): ?string
    {
        return $this->inputName;
    }

    public function getResult(): ?string
    {
        return $this->result;
    }

    public function apply(Canvas $input, FilterPipeline $pipeline): Canvas
    {
        if (empty($this->mergeInputs)) {
            $output = Canvas::createBlank($input->w, $input->h, $input->halfblocks);
            if ($this->result !== null) {
                $pipeline->setResult($this->result, $output);
            }
            return $output;
        }

        $firstInput = $pipeline->getResult($this->mergeInputs[0]);
        if ($firstInput === null) {
            $output = Canvas::createBlank($input->w, $input->h, $input->halfblocks);
            if ($this->result !== null) {
                $pipeline->setResult($this->result, $output);
            }
            return $output;
        }

        $output = Canvas::createBlank($firstInput->w, $firstInput->h, $firstInput->halfblocks);
        Compositor::blend($output, $firstInput);

        for ($i = 1; $i < count($this->mergeInputs); $i++) {
            $layer = $pipeline->getResult($this->mergeInputs[$i]);
            if ($layer !== null) {
                Compositor::blend($output, $layer);
            }
        }

        if ($this->result !== null) {
            $pipeline->setResult($this->result, $output);
        }

        return $output;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test -- tests/Canvas/FilterPrimitiveTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add library/draw/MergePrimitive.php tests/Canvas/FilterPrimitiveTest.php
git commit -m "Add MergePrimitive (feMerge) for compositing multiple named inputs"
```

---

### Task 7: DropShadowPrimitive

**Files:**
- Create: `library/draw/DropShadowPrimitive.php`
- Modify: `tests/Canvas/FilterPrimitiveTest.php`

- [ ] **Step 1: Write tests for DropShadowPrimitive**

Append to `tests/Canvas/FilterPrimitiveTest.php`:

```php
use draw\DropShadowPrimitive;

    public function test_drop_shadow_produces_shadow_offset(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(5, 5, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $primitive = new DropShadowPrimitive(2.0, 1.0, 0.5, [0, 0, 0], 1.0);
        $result = $primitive->apply($source, $pipeline);

        $this->assertNotNull($result->data[5][5]->fg, 'Original pixel should remain');
        $this->assertNotNull($result->data[6][7]->fg, 'Shadow pixel should appear at offset');
    }

    public function test_drop_shadow_zero_blur_creates_hard_shadow(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(5, 5, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $primitive = new DropShadowPrimitive(1.0, 0.0, 0.0, [0, 0, 0], 1.0);
        $result = $primitive->apply($source, $pipeline);

        $this->assertNotNull($result->data[5][6]->fg, 'Hard shadow at offset');
    }

    public function test_drop_shadow_custom_flood_color(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(5, 5, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $primitive = new DropShadowPrimitive(2.0, 1.0, 0.0, [255, 0, 0], 1.0);
        $result = $primitive->apply($source, $pipeline);

        $shadowRgb = IrcPalette::getRgb($result->data[6][7]->fg);
        $this->assertGreaterThan(200, $shadowRgb[0], 'Shadow should be reddish');
    }

    public function test_drop_shadow_with_blur_spreads(): void
    {
        $source = Canvas::createBlank(20, 10);
        $source->drawPoint(10, 5, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $primitive = new DropShadowPrimitive(3.0, 1.0, 2.0, [0, 0, 0], 1.0);
        $result = $primitive->apply($source, $pipeline);

        $shadowPixels = 0;
        $originalRgb = IrcPalette::getRgb(4);
        for ($y = 0; $y < 10; $y++) {
            for ($x = 0; $x < 20; $x++) {
                $p = $result->data[$y][$x];
                if ($p->fg !== null) {
                    $rgb = IrcPalette::getRgb($p->fg);
                    if (abs($rgb[0] - $originalRgb[0]) > 30 || abs($rgb[1] - $originalRgb[1]) > 30) {
                        $shadowPixels++;
                    }
                }
            }
        }
        $this->assertGreaterThan(0, $shadowPixels, 'Blurred shadow should spread beyond original shape');
    }

    public function test_drop_shadow_preserves_original(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(5, 5, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $primitive = new DropShadowPrimitive(5.0, 3.0, 1.0, [0, 0, 0], 1.0);
        $result = $primitive->apply($source, $pipeline);

        $this->assertSame(4, $result->data[5][5]->fg, 'Original pixel color should be preserved');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- tests/Canvas/FilterPrimitiveTest.php`
Expected: FAIL — `DropShadowPrimitive` class does not exist

- [ ] **Step 3: Implement DropShadowPrimitive**

Create `library/draw/DropShadowPrimitive.php`:

```php
<?php

namespace draw;

class DropShadowPrimitive implements FilterPrimitive
{
    public function __construct(
        private readonly float $dx,
        private readonly float $dy,
        private readonly float $stdDeviation,
        private readonly array $floodColor,
        private readonly float $floodOpacity,
        private readonly ?string $input = null,
        private readonly ?string $result = null,
    ) {
    }

    public function getInput(): ?string
    {
        return $this->input;
    }

    public function getResult(): ?string
    {
        return $this->result;
    }

    public function apply(Canvas $input, FilterPipeline $pipeline): Canvas
    {
        $sourceGraphic = $pipeline->getResult('SourceGraphic');

        $alphaCanvas = $this->extractAlpha($input);

        $blurPrimitive = new GaussianBlurPrimitive($this->stdDeviation);
        $blurredAlpha = $blurPrimitive->apply($alphaCanvas, $pipeline);

        $offsetPrimitive = new OffsetPrimitive($this->dx, $this->dy);
        $offsetShadow = $offsetPrimitive->apply($blurredAlpha, $pipeline);

        $shadowCanvas = $this->floodMask(
            $offsetShadow,
            $this->floodColor,
            $this->floodOpacity,
        );

        $output = Canvas::createBlank($input->w, $input->h, $input->halfblocks);
        Compositor::blend($output, $shadowCanvas);
        Compositor::blend($output, $sourceGraphic);

        if ($this->result !== null) {
            $pipeline->setResult($this->result, $output);
        }

        return $output;
    }

    private function extractAlpha(Canvas $input): Canvas
    {
        $alpha = Canvas::createBlank($input->w, $input->h, $input->halfblocks);

        for ($y = 0; $y < $input->h; $y++) {
            for ($x = 0; $x < $input->w; $x++) {
                $sp = $input->data[$y][$x];
                $dp = $alpha->data[$y][$x];
                if ($sp->fg !== null) {
                    $dp->fg = Color::White;
                    $dp->fgAlpha = $sp->fgAlpha;
                }
                if ($sp->bg !== null) {
                    $dp->bg = Color::White;
                    $dp->bgAlpha = $sp->bgAlpha;
                }
            }
        }

        return $alpha;
    }

    private function floodMask(Canvas $mask, array $floodColor, float $floodOpacity): Canvas
    {
        $flooded = Canvas::createBlank($mask->w, $mask->h, $mask->halfblocks);
        $floodCode = IrcPalette::nearestColor(
            (int) round($floodColor[0] * $floodOpacity),
            (int) round($floodColor[1] * $floodOpacity),
            (int) round($floodColor[2] * $floodOpacity),
        );
        $floodRgb = IrcPalette::getRgb($floodCode);

        for ($y = 0; $y < $mask->h; $y++) {
            for ($x = 0; $x < $mask->w; $x++) {
                $mp = $mask->data[$y][$x];
                $dp = $flooded->data[$y][$x];

                if ($mp->fg !== null) {
                    $maskRgb = IrcPalette::getRgb($mp->fg);
                    $maskLum = (0.2126 * $maskRgb[0] + 0.7152 * $maskRgb[1] + 0.0722 * $maskRgb[2]) / 255.0;
                    $effectiveAlpha = $maskLum * $mp->fgAlpha;
                    if ($effectiveAlpha > 0.001) {
                        $dp->fg = IrcPalette::nearestColor(
                            (int) round($floodColor[0]),
                            (int) round($floodColor[1]),
                            (int) round($floodColor[2]),
                        );
                        $dp->fgAlpha = min(1.0, $effectiveAlpha * $floodOpacity);
                    }
                }

                if ($mp->bg !== null) {
                    $maskRgb = IrcPalette::getRgb($mp->bg);
                    $maskLum = (0.2126 * $maskRgb[0] + 0.7152 * $maskRgb[1] + 0.0722 * $maskRgb[2]) / 255.0;
                    $effectiveAlpha = $maskLum * $mp->bgAlpha;
                    if ($effectiveAlpha > 0.001) {
                        $dp->bg = IrcPalette::nearestColor(
                            (int) round($floodColor[0]),
                            (int) round($floodColor[1]),
                            (int) round($floodColor[2]),
                        );
                        $dp->bgAlpha = min(1.0, $effectiveAlpha * $floodOpacity);
                    }
                }
            }
        }

        return $flooded;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test -- tests/Canvas/FilterPrimitiveTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add library/draw/DropShadowPrimitive.php tests/Canvas/FilterPrimitiveTest.php
git commit -m "Add DropShadowPrimitive (feDropShadow) shorthand"
```

---

### Task 8: FilterNode — scene tree integration

**Files:**
- Create: `library/draw/FilterNode.php`
- Modify: `library/draw/ClipNode.php` (add `FilterNode` to `computeBbox()`)
- Create: `tests/Canvas/FilterNodeTest.php`

- [ ] **Step 1: Write tests for FilterNode**

Create `tests/Canvas/FilterNodeTest.php`:

```php
<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\ClipNode;
use draw\Color;
use draw\FilterNode;
use draw\FilterRegion;
use draw\GaussianBlurPrimitive;
use draw\GradientUnits;
use draw\Group;
use draw\OffsetPrimitive;
use draw\Path;
use draw\RenderContext;
use draw\Shape;
use draw\Transform;
use PHPUnit\Framework\TestCase;

class FilterNodeTest extends TestCase
{
    public function test_filter_node_with_offset_shifts_shape(): void
    {
        $canvas = Canvas::createBlank(20, 10);

        $child = new Shape(path: Path::rect(2.0, 2.0, 3.0, 3.0), fill: new Color(4, null));

        $filterNode = new FilterNode($child, [
            new OffsetPrimitive(5.0, 0.0),
        ]);
        $filterNode->render($canvas, RenderContext::defaults());

        $this->assertNull($canvas->data[3][3]->fg, 'Original position should be empty');
        $this->assertSame(4, $canvas->data[3][8]->fg, 'Shape should be shifted right by 5');
    }

    public function test_filter_node_with_blur_spreads_shape(): void
    {
        $canvas = Canvas::createBlank(20, 10);

        $child = new Shape(path: Path::rect(5.0, 3.0, 2.0, 2.0), fill: new Color(4, null));

        $filterNode = new FilterNode($child, [
            new GaussianBlurPrimitive(1.0),
        ]);
        $filterNode->render($canvas, RenderContext::defaults());

        $this->assertNotNull($canvas->data[4][6]->fg, 'Center of shape should have color');
        $this->assertNotNull($canvas->data[4][5]->fg, 'Spread from blur');
    }

    public function test_filter_node_empty_primitives_passes_through(): void
    {
        $canvas = Canvas::createBlank(20, 10);

        $child = new Shape(path: Path::rect(2.0, 2.0, 3.0, 3.0), fill: new Color(4, null));

        $filterNode = new FilterNode($child, []);
        $filterNode->render($canvas, RenderContext::defaults());

        $this->assertSame(4, $canvas->data[3][3]->fg);
    }

    public function test_filter_node_get_children_returns_child(): void
    {
        $child = new Shape(path: Path::rect(0.0, 0.0, 10.0, 10.0), fill: new Color(4, null));
        $filterNode = new FilterNode($child, []);

        $children = $filterNode->getChildren();
        $this->assertCount(1, $children);
        $this->assertSame($child, $children[0]);
    }

    public function test_filter_node_on_group(): void
    {
        $canvas = Canvas::createBlank(20, 10);

        $group = new Group();
        $group->addChild(new Shape(path: Path::rect(0.0, 0.0, 5.0, 5.0), fill: new Color(4, null)));

        $filterNode = new FilterNode($group, [
            new OffsetPrimitive(3.0, 0.0),
        ]);
        $filterNode->render($canvas, RenderContext::defaults());

        $this->assertNull($canvas->data[2][2]->fg, 'Original position should be empty');
        $this->assertSame(4, $canvas->data[2][5]->fg, 'Group should be shifted');
    }

    public function test_filter_node_with_custom_region(): void
    {
        $canvas = Canvas::createBlank(20, 10);

        $child = new Shape(path: Path::rect(5.0, 3.0, 3.0, 3.0), fill: new Color(4, null));

        $region = new FilterRegion(0.0, 0.0, 1.0, 1.0);
        $filterNode = new FilterNode($child, [new OffsetPrimitive(1.0, 0.0)], filterRegion: $region);
        $filterNode->render($canvas, RenderContext::defaults());

        $this->assertNull($canvas->data[4][5]->fg, 'Original pos should be empty after offset');
        $this->assertSame(4, $canvas->data[4][6]->fg);
    }

    public function test_filter_node_chains_primitives(): void
    {
        $canvas = Canvas::createBlank(20, 10);

        $child = new Shape(path: Path::rect(2.0, 2.0, 3.0, 3.0), fill: new Color(4, null));

        $filterNode = new FilterNode($child, [
            new OffsetPrimitive(3.0, 0.0),
            new OffsetPrimitive(2.0, 0.0),
        ]);
        $filterNode->render($canvas, RenderContext::defaults());

        $this->assertSame(4, $canvas->data[3][7]->fg, 'Shape shifted by 3+2=5');
    }

    public function test_filter_node_restores_canvas_state(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $beforeTransform = $canvas->getTransform();

        $child = new Shape(path: Path::rect(2.0, 2.0, 3.0, 3.0), fill: new Color(4, null));
        $filterNode = new FilterNode($child, [new OffsetPrimitive(1.0, 0.0)]);
        $filterNode->render($canvas, RenderContext::defaults());

        $this->assertTrue($beforeTransform->equals($canvas->getTransform()));
    }

    public function test_filter_node_user_space_on_use_units(): void
    {
        $canvas = Canvas::createBlank(20, 10);

        $child = new Shape(path: Path::rect(5.0, 3.0, 3.0, 3.0), fill: new Color(4, null));

        $region = new FilterRegion(0.0, 0.0, 20.0, 10.0);
        $filterNode = new FilterNode(
            $child,
            [new OffsetPrimitive(1.0, 0.0)],
            filterRegion: $region,
            filterUnits: GradientUnits::UserSpaceOnUse,
        );
        $filterNode->render($canvas, RenderContext::defaults());

        $this->assertSame(4, $canvas->data[4][6]->fg);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- tests/Canvas/FilterNodeTest.php`
Expected: FAIL — `FilterNode` class does not exist

- [ ] **Step 3: Implement FilterNode**

Create `library/draw/FilterNode.php`:

```php
<?php

namespace draw;

class FilterNode implements SceneNode
{
    public function __construct(
        public readonly SceneNode $child,
        public readonly array $primitives,
        public readonly ?FilterRegion $filterRegion = null,
        public readonly GradientUnits $filterUnits = GradientUnits::ObjectBoundingBox,
        public readonly ?\Psr\Log\LoggerInterface $logger = null,
    ) {
    }

    public function getChildren(): array
    {
        return [$this->child];
    }

    public function render(Canvas $canvas, RenderContext $ctx): void
    {
        $childBbox = ClipNode::computeBbox($this->child);
        $region = $this->filterRegion ?? FilterRegion::defaults();

        if ($this->filterUnits === GradientUnits::ObjectBoundingBox && $childBbox !== null) {
            $absRegion = $region->toAbsolute(
                $childBbox['x'], $childBbox['y'],
                $childBbox['w'], $childBbox['h'],
            );
        } else {
            $absRegion = [
                'x' => $region->x,
                'y' => $region->y,
                'width' => $region->width,
                'height' => $region->height,
            ];
        }

        $regionX = (int) floor(max(0, $absRegion['x']));
        $regionY = (int) floor(max(0, $absRegion['y']));
        $regionW = (int) ceil(min($canvas->w - $regionX, $absRegion['width']));
        $regionH = (int) ceil(min($canvas->h - $regionY, $absRegion['height']));

        if ($regionW <= 0 || $regionH <= 0) {
            return;
        }

        $childCanvas = Canvas::createBlank($canvas->w, $canvas->h, $canvas->halfblocks);
        $childCanvas->setTransform($canvas->getTransform());
        $childCanvas->setDithering($canvas->getDithering());
        $this->child->render($childCanvas, $ctx);

        $pipeline = new FilterPipeline($childCanvas, $this->logger);

        $lastResult = $childCanvas;
        foreach ($this->primitives as $primitive) {
            $inputName = $primitive->getInput();
            if ($inputName !== null) {
                $resolvedInput = $pipeline->getResult($inputName);
                if ($resolvedInput === null) {
                    continue;
                }
            } else {
                $resolvedInput = $lastResult;
            }
            $lastResult = $primitive->apply($resolvedInput, $pipeline);
        }

        Compositor::blend($canvas, $lastResult);
    }
}
```

- [ ] **Step 4: Add FilterNode to ClipNode::computeBbox()**

In `library/draw/ClipNode.php`, update the `computeBbox()` method. Find this block at line 70:

```php
if ($node instanceof ClipNode || $node instanceof MaskNode) {
    return self::computeBbox($node->child);
}
```

Change it to:

```php
if ($node instanceof ClipNode || $node instanceof MaskNode || $node instanceof FilterNode) {
    return self::computeBbox($node->child);
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `composer test -- tests/Canvas/FilterNodeTest.php`
Expected: PASS

- [ ] **Step 6: Run full test suite to verify no regressions**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 7: Commit**

```bash
git add library/draw/FilterNode.php library/draw/ClipNode.php tests/Canvas/FilterNodeTest.php
git commit -m "Add FilterNode scene tree wrapper with sub-region rendering"
```

---

### Task 9: SVG parser integration

**Files:**
- Modify: `library/draw/SVGParser.php`
- Modify: `tests/Canvas/SVGParserTest.php`

- [ ] **Step 1: Write tests for SVG parser filter support**

Append to `tests/Canvas/SVGParserTest.php`:

```php
use draw\DropShadowPrimitive;
use draw\FilterNode;
use draw\GaussianBlurPrimitive;
use draw\OffsetPrimitive;
use draw\ColorMatrixPrimitive;
use draw\MergePrimitive;

    public function test_parse_string_filter_with_offset(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><filter id="f1"><feOffset dx="3" dy="0"/></filter></defs><rect x="2" y="2" width="3" height="3" fill="red" filter="url(#f1)"/></svg>';
        $doc = SVGParser::parseString($svg);

        $canvas = Canvas::createBlank(20, 10);
        $doc->render($canvas);

        $this->assertNull($canvas->data[3][3]->fg, 'Original position should be empty');
        $this->assertSame(4, $canvas->data[3][6]->fg, 'Shape should be shifted right by 3');
    }

    public function test_parse_string_filter_with_blur(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><filter id="f1"><feGaussianBlur stdDeviation="1"/></filter></defs><rect x="5" y="3" width="3" height="3" fill="red" filter="url(#f1)"/></svg>';
        $doc = SVGParser::parseString($svg);

        $canvas = Canvas::createBlank(20, 10);
        $doc->render($canvas);

        $this->assertNotNull($canvas->data[4][6]->fg, 'Center of blurred shape should have color');
    }

    public function test_parse_string_filter_with_color_matrix_saturate(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><filter id="f1"><feColorMatrix type="saturate" values="0"/></filter></defs><rect x="2" y="2" width="3" height="3" fill="red" filter="url(#f1)"/></svg>';
        $doc = SVGParser::parseString($svg);

        $canvas = Canvas::createBlank(10, 10);
        $doc->render($canvas);

        $this->assertNotNull($canvas->data[3][3]->fg);
        $rgb = IrcPalette::getRgb($canvas->data[3][3]->fg);
        $this->assertEqualsWithDelta($rgb[0], $rgb[1], 5, 'Desaturated red should be greyish');
    }

    public function test_parse_string_filter_with_drop_shadow(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><filter id="f1"><feDropShadow dx="3" dy="1" stdDeviation="0" flood-color="black"/></filter></defs><rect x="2" y="2" width="3" height="3" fill="red" filter="url(#f1)"/></svg>';
        $doc = SVGParser::parseString($svg);

        $canvas = Canvas::createBlank(20, 10);
        $doc->render($canvas);

        $this->assertSame(4, $canvas->data[3][3]->fg, 'Original rect should still be red');
        $this->assertNotNull($canvas->data[4][6]->fg, 'Shadow should appear at offset');
    }

    public function test_parse_string_filter_with_merge(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><filter id="f1"><feOffset dx="3" dy="0" result="shifted"/><feMerge><feMergeNode in="shifted"/><feMergeNode in="SourceGraphic"/></feMerge></filter></defs><rect x="2" y="2" width="3" height="3" fill="red" filter="url(#f1)"/></svg>';
        $doc = SVGParser::parseString($svg);

        $canvas = Canvas::createBlank(20, 10);
        $doc->render($canvas);

        $this->assertSame(4, $canvas->data[3][3]->fg, 'Original position from merge');
        $this->assertSame(4, $canvas->data[3][6]->fg, 'Shifted position from merge');
    }

    public function test_parse_string_filter_unknown_primitive_skipped(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><filter id="f1"><feTurbulence type="fractalNoise"/></filter></defs><rect x="2" y="2" width="3" height="3" fill="red" filter="url(#f1)"/></svg>';

        $logger = new \Psr\Log\NullLogger();
        $doc = SVGParser::parseString($svg, $logger);

        $canvas = Canvas::createBlank(10, 10);
        $doc->render($canvas);

        $this->assertSame(4, $canvas->data[3][3]->fg, 'Shape should render without filter');
    }

    public function test_parse_string_filter_missing_reference_ignored(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect x="2" y="2" width="3" height="3" fill="red" filter="url(#nonexistent)"/></svg>';
        $doc = SVGParser::parseString($svg);

        $canvas = Canvas::createBlank(10, 10);
        $doc->render($canvas);

        $this->assertSame(4, $canvas->data[3][3]->fg, 'Shape should render without filter');
    }

    public function test_parse_string_filter_in_defs_not_rendered(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><filter id="f1"><feOffset dx="3" dy="0"/></filter></defs></svg>';
        $doc = SVGParser::parseString($svg);

        $canvas = Canvas::createBlank(10, 10);
        $doc->render($canvas);

        $this->assertNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_filter_combined_with_clip(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><clipPath id="c1"><rect x="0" y="0" width="5" height="5"/></clipPath><filter id="f1"><feOffset dx="3" dy="0"/></filter></defs><rect x="2" y="2" width="5" height="5" fill="red" clip-path="url(#c1)" filter="url(#f1)"/></svg>';
        $doc = SVGParser::parseString($svg);

        $canvas = Canvas::createBlank(20, 10);
        $doc->render($canvas);

        $this->assertNull($canvas->data[3][3]->fg, 'Original clipped area should be empty after offset');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- tests/Canvas/SVGParserTest.php --filter "filter"`
Expected: FAIL — SVGParser does not handle `<filter>` elements

- [ ] **Step 3: Add filter element harvesting to SVGParser**

In `library/draw/SVGParser.php`, modify `collectAllDefs()` (line 554). Find:

```php
} elseif ($name === 'clipPath' || $name === 'mask') {
    self::parseClipMaskElement($child, $defs, $styles, $logger);
```

Change to:

```php
} elseif ($name === 'clipPath' || $name === 'mask' || $name === 'filter') {
    self::parseClipMaskElement($child, $defs, $styles, $logger);
```

- [ ] **Step 4: Add filter case to parseElement() match**

In `library/draw/SVGParser.php`, modify the `parseElement()` match (line 414). Find:

```php
        'mask' => new Group(),
        'style' => new Group(),
```

Change to:

```php
        'mask' => new Group(),
        'filter' => new Group(),
        'style' => new Group(),
```

- [ ] **Step 5: Add filter parsing method to SVGParser**

Add this method to the `SVGParser` class (before the closing brace):

```php
private static function parseFilterElement(\SimpleXMLElement $el, array $defs, array $styles, ?LoggerInterface $logger): ?FilterNode
{
    $filterUnits = match (strtolower((string)($el['filterUnits'] ?? 'objectBoundingBox'))) {
        'userspaceonuse' => GradientUnits::UserSpaceOnUse,
        default => GradientUnits::ObjectBoundingBox,
    };

    $filterRegion = null;
    $x = (string)($el['x'] ?? '');
    if ($x !== '') {
        $filterRegion = new FilterRegion(
            self::parsePercentOrFloat($el['x'], -0.1),
            self::parsePercentOrFloat($el['y'], -0.1),
            self::parsePercentOrFloat($el['width'], 1.2),
            self::parsePercentOrFloat($el['height'], 1.2),
        );
    }

    $primitives = [];
    foreach (self::svgChildren($el) as $child) {
        $name = $child->getName();
        $primitive = match ($name) {
            'feGaussianBlur' => new GaussianBlurPrimitive(
                (float)($child['stdDeviation'] ?? 0),
                input: self::parseOptionalString($child['in']),
                result: self::parseOptionalString($child['result']),
            ),
            'feOffset' => new OffsetPrimitive(
                (float)($child['dx'] ?? 0),
                (float)($child['dy'] ?? 0),
                input: self::parseOptionalString($child['in']),
                result: self::parseOptionalString($child['result']),
            ),
            'feColorMatrix' => self::parseColorMatrixPrimitive($child),
            'feMerge' => self::parseMergePrimitive($child),
            'feDropShadow' => new DropShadowPrimitive(
                (float)($child['dx'] ?? 2),
                (float)($child['dy'] ?? 2),
                (float)($child['stdDeviation'] ?? 2),
                self::parseFloodColor($child),
                (float)($child['flood-opacity'] ?? 1),
                input: self::parseOptionalString($child['in']),
                result: self::parseOptionalString($child['result']),
            ),
            default => null,
        };
        if ($primitive === null) {
            $logger?->warning("Unsupported filter primitive: <{$name}>");
            continue;
        }
        $primitives[] = $primitive;
    }

    return new FilterNode(
        new Group(),
        $primitives,
        $filterRegion,
        $filterUnits,
        $logger,
    );
}

private static function parsePercentOrFloat(\SimpleXMLElement $attr, float $default): float
{
    $val = trim((string) $attr);
    if ($val === '') {
        return $default;
    }
    if (str_ends_with($val, '%')) {
        return (float) substr($val, 0, -1) / 100.0;
    }
    return (float) $val;
}

private static function parseOptionalString(\SimpleXMLElement $attr): ?string
{
    $val = trim((string) $attr);
    return $val !== '' ? $val : null;
}

private static function parseColorMatrixPrimitive(\SimpleXMLElement $el): ColorMatrixPrimitive
{
    $type = (string)($el['type'] ?? 'matrix');
    $valuesStr = (string)($el['values'] ?? '');
    $values = array_map('floatval', preg_split('/[\s,]+/', trim($valuesStr)) ?: []);

    return new ColorMatrixPrimitive(
        $type,
        $values,
        input: self::parseOptionalString($el['in']),
        result: self::parseOptionalString($el['result']),
    );
}

private static function parseMergePrimitive(\SimpleXMLElement $el): MergePrimitive
{
    $inputs = [];
    foreach (self::svgChildren($el) as $child) {
        if ($child->getName() === 'feMergeNode') {
            $in = trim((string)($child['in'] ?? ''));
            if ($in !== '') {
                $inputs[] = $in;
            }
        }
    }
    return new MergePrimitive($inputs, result: self::parseOptionalString($el['result']));
}

private static function parseFloodColor(\SimpleXMLElement $el): array
{
    $colorStr = trim((string)($el['flood-color'] ?? 'black'));
    if ($colorStr === '' || $colorStr === 'black') {
        return [0, 0, 0];
    }
    $parsed = SvgColor::parse($colorStr);
    if ($parsed !== null) {
        return $parsed;
    }
    return [0, 0, 0];
}
```

- [ ] **Step 6: Add filter wrapping to wrapWithClipMask()**

In `library/draw/SVGParser.php`, modify `wrapWithClipMask()` (line 706). Before the final `return $node;` (line 753), add:

```php
$filterAttr = self::getEffectiveAttr($el, 'filter', $styles);
if ($filterAttr !== '' && preg_match('/^url\(#(.+)\)$/', $filterAttr, $m)) {
    $filterId = $m[1];
    if (isset($defs[$filterId]) && $defs[$filterId]->getName() === 'filter') {
        $filterEl = $defs[$filterId];
        $filterNode = self::parseFilterElement($filterEl, $defs, $styles, $logger);
        if ($filterNode !== null) {
            $filterNode = new FilterNode(
                $node,
                $filterNode->primitives,
                $filterNode->filterRegion,
                $filterNode->filterUnits,
                $logger,
            );
            $node = $filterNode;
        }
    }
}
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `composer test -- tests/Canvas/SVGParserTest.php --filter "filter"`
Expected: PASS

- [ ] **Step 8: Run full test suite**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 9: Commit**

```bash
git add library/draw/SVGParser.php tests/Canvas/SVGParserTest.php
git commit -m "Add SVG parser support for <filter> elements and filter attribute"
```

---

### Task 10: Update roadmap and final verification

**Files:**
- Modify: `docs/superpowers/specs/2026-06-02-draw-library-svg-roadmap.md`

- [ ] **Step 1: Update roadmap milestone 11**

In `docs/superpowers/specs/2026-06-02-draw-library-svg-roadmap.md`, change milestone 11 from:

```
11. **Filters** — blur, shadow, color matrix
```

To:

```
11. ~~**Filters** — blur, shadow, color matrix~~ **DONE**
    - `feGaussianBlur` — 3-pass box blur approximating Gaussian in RGB space
    - `feOffset` — pixel translation by dx/dy
    - `feColorMatrix` — per-pixel matrix (matrix, saturate, hueRotate, luminanceToAlpha)
    - `feMerge` — composite multiple named inputs via source-over
    - `feDropShadow` — shorthand expanding to blur + offset + flood + mask + merge
    - Named result chaining via `in`/`result` attributes
    - Built-in sources: SourceGraphic, SourceAlpha, BackgroundImage, BackgroundAlpha
    - Sub-region rendering via filter region + bbox computation
```

- [ ] **Step 2: Run static analysis**

Run: `composer phpstan`
Expected: No errors

- [ ] **Step 3: Run full test suite**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 4: Commit**

```bash
git add docs/superpowers/specs/2026-06-02-draw-library-svg-roadmap.md
git commit -m "Update roadmap: SVG filters milestone 11 complete"
```
