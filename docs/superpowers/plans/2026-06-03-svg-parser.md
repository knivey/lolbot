# SVG Parser Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an SVG parser that maps SVG XML documents to the existing draw library scene tree, enabling SVG rendering on IRC character-cell canvases.

**Architecture:** Three new files in `library/draw/`. `SvgColor` handles CSS/SVG color string parsing to RGB. `SVGDocument` holds the parsed scene tree with viewBox metadata and render convenience. `SVGParser` walks SimpleXML trees, dispatches to element handler methods, and builds Group/Shape/Paint objects using the existing draw library types.

**Tech Stack:** PHP 8.1+, ext-simplexml, PSR-3 LoggerInterface (psr/log 3.x already in vendor)

---

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `library/draw/SvgColor.php` | Create | Parse CSS color strings (#hex, rgb(), named) to [r,g,b] |
| `library/draw/SVGDocument.php` | Create | Hold parsed scene tree, viewBox metadata, render convenience |
| `library/draw/SVGParser.php` | Create | Walk SVG XML, dispatch to element handlers, build scene tree |
| `tests/Canvas/SvgColorTest.php` | Create | Test color parsing |
| `tests/Canvas/SVGParserTest.php` | Create | Test parser and document |
| `docs/superpowers/specs/2026-06-02-draw-library-svg-roadmap.md` | Modify | Mark milestone 9 complete |

---

### Task 1: SvgColor — Named Colors Map

**Files:**
- Create: `library/draw/SvgColor.php`
- Test: `tests/Canvas/SvgColorTest.php`

- [ ] **Step 1: Write the failing test for named colors**

```php
<?php

namespace Tests\Canvas;

use draw\SvgColor;
use PHPUnit\Framework\TestCase;

class SvgColorTest extends TestCase
{
    public function test_named_color_red(): void
    {
        $result = SvgColor::parse('red');
        $this->assertSame([255, 0, 0], $result);
    }

    public function test_named_color_blue(): void
    {
        $result = SvgColor::parse('blue');
        $this->assertSame([0, 0, 255], $result);
    }

    public function test_named_color_cornflowerblue(): void
    {
        $result = SvgColor::parse('cornflowerblue');
        $this->assertSame([100, 149, 237], $result);
    }

    public function test_named_color_black(): void
    {
        $result = SvgColor::parse('black');
        $this->assertSame([0, 0, 0], $result);
    }

    public function test_named_color_white(): void
    {
        $result = SvgColor::parse('white');
        $this->assertSame([255, 255, 255], $result);
    }

    public function test_named_color_is_case_insensitive(): void
    {
        $result = SvgColor::parse('ReD');
        $this->assertSame([255, 0, 0], $result);
    }

    public function test_none_returns_null(): void
    {
        $result = SvgColor::parse('none');
        $this->assertNull($result);
    }

    public function test_transparent_returns_null(): void
    {
        $result = SvgColor::parse('transparent');
        $this->assertNull($result);
    }

    public function test_currentcolor_returns_null(): void
    {
        $result = SvgColor::parse('currentColor');
        $this->assertNull($result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter SvgColorTest`
Expected: FAIL — class `draw\SvgColor` not found

- [ ] **Step 3: Write implementation — SvgColor with named colors and none/transparent/currentColor**

Create `library/draw/SvgColor.php`:

```php
<?php

namespace draw;

class SvgColor
{
    private static ?array $namedColors = null;

    public static function parse(string $color): ?array
    {
        $color = trim($color);
        $lower = strtolower($color);

        if ($lower === 'none' || $lower === 'transparent' || $lower === 'currentcolor') {
            return null;
        }

        if (str_starts_with($color, '#')) {
            return self::parseHex($color);
        }

        if (str_starts_with($lower, 'rgb')) {
            return self::parseRgb($color);
        }

        $named = self::getNamedColors();
        if (isset($named[$lower])) {
            return $named[$lower];
        }

        return null;
    }

    private static function parseHex(string $color): ?array
    {
        $hex = substr($color, 1);
        $len = strlen($hex);

        if ($len === 3) {
            $r = hexdec($hex[0] . $hex[0]);
            $g = hexdec($hex[1] . $hex[1]);
            $b = hexdec($hex[2] . $hex[2]);
            return [(int)$r, (int)$g, (int)$b];
        }

        if ($len === 6 || $len === 8) {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
            return [(int)$r, (int)$g, (int)$b];
        }

        return null;
    }

    private static function parseRgb(string $color): ?array
    {
        if (!preg_match('/rgba?\(\s*([\d.]+%?)\s*[,\s]\s*([\d.]+%?)\s*[,\s]\s*([\d.]+%?)/i', $color, $m)) {
            return null;
        }

        $r = self::clampComponent($m[1]);
        $g = self::clampComponent($m[2]);
        $b = self::clampComponent($m[3]);

        return [$r, $g, $b];
    }

    private static function clampComponent(string $val): int
    {
        if (str_ends_with($val, '%')) {
            $pct = (float)$val;
            return (int)round($pct * 2.55);
        }
        return (int)min(255, max(0, (float)$val));
    }

    private static function getNamedColors(): array
    {
        if (self::$namedColors !== null) {
            return self::$namedColors;
        }

        self::$namedColors = [
            'aliceblue' => [240, 248, 255],
            'antiquewhite' => [250, 235, 215],
            'aqua' => [0, 255, 255],
            'aquamarine' => [127, 255, 212],
            'azure' => [240, 255, 255],
            'beige' => [245, 245, 220],
            'bisque' => [255, 228, 196],
            'black' => [0, 0, 0],
            'blanchedalmond' => [255, 235, 205],
            'blue' => [0, 0, 255],
            'blueviolet' => [138, 43, 226],
            'brown' => [165, 42, 42],
            'burlywood' => [222, 184, 135],
            'cadetblue' => [95, 158, 160],
            'chartreuse' => [127, 255, 0],
            'chocolate' => [210, 105, 30],
            'coral' => [255, 127, 80],
            'cornflowerblue' => [100, 149, 237],
            'cornsilk' => [255, 248, 220],
            'crimson' => [220, 20, 60],
            'cyan' => [0, 255, 255],
            'darkblue' => [0, 0, 139],
            'darkcyan' => [0, 139, 139],
            'darkgoldenrod' => [184, 134, 11],
            'darkgray' => [169, 169, 169],
            'darkgreen' => [0, 100, 0],
            'darkgrey' => [169, 169, 169],
            'darkkhaki' => [189, 183, 107],
            'darkmagenta' => [139, 0, 139],
            'darkolivegreen' => [85, 107, 47],
            'darkorange' => [255, 140, 0],
            'darkorchid' => [153, 50, 204],
            'darkred' => [139, 0, 0],
            'darksalmon' => [233, 150, 122],
            'darkseagreen' => [143, 188, 143],
            'darkslateblue' => [72, 61, 139],
            'darkslategray' => [47, 79, 79],
            'darkslategrey' => [47, 79, 79],
            'darkturquoise' => [0, 206, 209],
            'darkviolet' => [148, 0, 211],
            'deeppink' => [255, 20, 147],
            'deepskyblue' => [0, 191, 255],
            'dimgray' => [105, 105, 105],
            'dimgrey' => [105, 105, 105],
            'dodgerblue' => [30, 144, 255],
            'firebrick' => [178, 34, 34],
            'floralwhite' => [255, 250, 240],
            'forestgreen' => [34, 139, 34],
            'fuchsia' => [255, 0, 255],
            'gainsboro' => [220, 220, 220],
            'ghostwhite' => [248, 248, 255],
            'gold' => [255, 215, 0],
            'goldenrod' => [218, 165, 32],
            'gray' => [128, 128, 128],
            'green' => [0, 128, 0],
            'greenyellow' => [173, 255, 47],
            'grey' => [128, 128, 128],
            'honeydew' => [240, 255, 240],
            'hotpink' => [255, 105, 180],
            'indianred' => [205, 92, 92],
            'indigo' => [75, 0, 130],
            'ivory' => [255, 255, 240],
            'khaki' => [240, 230, 140],
            'lavender' => [230, 230, 250],
            'lavenderblush' => [255, 240, 245],
            'lawngreen' => [124, 252, 0],
            'lemonchiffon' => [255, 250, 205],
            'lightblue' => [173, 216, 230],
            'lightcoral' => [240, 128, 128],
            'lightcyan' => [224, 255, 255],
            'lightgoldenrodyellow' => [250, 250, 210],
            'lightgray' => [211, 211, 211],
            'lightgreen' => [144, 238, 144],
            'lightgrey' => [211, 211, 211],
            'lightpink' => [255, 182, 193],
            'lightsalmon' => [255, 160, 122],
            'lightseagreen' => [32, 178, 170],
            'lightskyblue' => [135, 206, 250],
            'lightslategray' => [119, 136, 153],
            'lightslategrey' => [119, 136, 153],
            'lightsteelblue' => [176, 196, 222],
            'lightyellow' => [255, 255, 224],
            'lime' => [0, 255, 0],
            'limegreen' => [50, 205, 50],
            'linen' => [250, 240, 230],
            'magenta' => [255, 0, 255],
            'maroon' => [128, 0, 0],
            'mediumaquamarine' => [102, 205, 170],
            'mediumblue' => [0, 0, 205],
            'mediumorchid' => [186, 85, 211],
            'mediumpurple' => [147, 111, 219],
            'mediumseagreen' => [60, 179, 113],
            'mediumslateblue' => [123, 104, 238],
            'mediumspringgreen' => [0, 250, 154],
            'mediumturquoise' => [72, 209, 204],
            'mediumvioletred' => [199, 21, 133],
            'midnightblue' => [25, 25, 112],
            'mintcream' => [245, 255, 250],
            'mistyrose' => [255, 228, 225],
            'moccasin' => [255, 228, 181],
            'navajowhite' => [255, 222, 173],
            'navy' => [0, 0, 128],
            'oldlace' => [253, 245, 230],
            'olive' => [128, 128, 0],
            'olivedrab' => [107, 142, 35],
            'orange' => [255, 165, 0],
            'orangered' => [255, 69, 0],
            'orchid' => [218, 112, 214],
            'palegoldenrod' => [238, 232, 170],
            'palegreen' => [152, 251, 152],
            'paleturquoise' => [175, 238, 238],
            'palevioletred' => [219, 112, 147],
            'papayawhip' => [255, 239, 213],
            'peachpuff' => [255, 218, 185],
            'peru' => [205, 133, 63],
            'pink' => [255, 192, 203],
            'plum' => [221, 160, 221],
            'powderblue' => [176, 224, 230],
            'purple' => [128, 0, 128],
            'rebeccapurple' => [102, 51, 153],
            'red' => [255, 0, 0],
            'rosybrown' => [188, 143, 143],
            'royalblue' => [65, 105, 225],
            'saddlebrown' => [139, 69, 19],
            'salmon' => [250, 128, 114],
            'sandybrown' => [244, 164, 96],
            'seagreen' => [46, 139, 87],
            'seashell' => [255, 245, 238],
            'sienna' => [160, 82, 45],
            'silver' => [192, 192, 192],
            'skyblue' => [135, 206, 235],
            'slateblue' => [106, 90, 205],
            'slategray' => [112, 128, 144],
            'slategrey' => [112, 128, 144],
            'snow' => [255, 250, 250],
            'springgreen' => [0, 255, 127],
            'steelblue' => [70, 130, 180],
            'tan' => [210, 180, 140],
            'teal' => [0, 128, 128],
            'thistle' => [216, 191, 216],
            'tomato' => [255, 99, 71],
            'turquoise' => [64, 224, 208],
            'violet' => [238, 130, 238],
            'wheat' => [245, 222, 179],
            'white' => [255, 255, 255],
            'whitesmoke' => [245, 245, 245],
            'yellow' => [255, 255, 0],
            'yellowgreen' => [154, 205, 50],
        ];

        return self::$namedColors;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter SvgColorTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add library/draw/SvgColor.php tests/Canvas/SvgColorTest.php
git commit -m "Add SvgColor class with CSS named colors and hex/rgb parsing"
```

---

### Task 2: SvgColor — Hex and rgb() Parsing Tests

**Files:**
- Modify: `tests/Canvas/SvgColorTest.php`
- No source changes (already implemented in Task 1)

- [ ] **Step 1: Add hex and rgb tests to SvgColorTest**

Append these tests to the `SvgColorTest` class:

```php
    public function test_hex_3_digit(): void
    {
        $result = SvgColor::parse('#f00');
        $this->assertSame([255, 0, 0], $result);
    }

    public function test_hex_6_digit(): void
    {
        $result = SvgColor::parse('#ff0000');
        $this->assertSame([255, 0, 0], $result);
    }

    public function test_hex_8_digit_ignores_alpha(): void
    {
        $result = SvgColor::parse('#ff000080');
        $this->assertSame([255, 0, 0], $result);
    }

    public function test_hex_uppercase(): void
    {
        $result = SvgColor::parse('#FF0000');
        $this->assertSame([255, 0, 0], $result);
    }

    public function test_hex_invalid_length_returns_null(): void
    {
        $result = SvgColor::parse('#ff');
        $this->assertNull($result);
    }

    public function test_rgb_functional(): void
    {
        $result = SvgColor::parse('rgb(255, 128, 0)');
        $this->assertSame([255, 128, 0], $result);
    }

    public function test_rgba_functional_ignores_alpha(): void
    {
        $result = SvgColor::parse('rgba(255, 128, 0, 0.5)');
        $this->assertSame([255, 128, 0], $result);
    }

    public function test_rgb_percent(): void
    {
        $result = SvgColor::parse('rgb(100%, 0%, 50%)');
        $this->assertSame([255, 0, 128], $result);
    }

    public function test_rgb_clamps_to_255(): void
    {
        $result = SvgColor::parse('rgb(300, -10, 128)');
        $this->assertSame([255, 0, 128], $result);
    }

    public function test_unknown_string_returns_null(): void
    {
        $result = SvgColor::parse('notacolor');
        $this->assertNull($result);
    }
```

- [ ] **Step 2: Run tests**

Run: `composer test -- --filter SvgColorTest`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add tests/Canvas/SvgColorTest.php
git commit -m "Add SvgColor hex and rgb() parsing tests"
```

---

### Task 3: SVGDocument

**Files:**
- Create: `library/draw/SVGDocument.php`
- Test: `tests/Canvas/SVGDocumentTest.php`

- [ ] **Step 1: Write the failing test for SVGDocument**

Create `tests/Canvas/SVGDocumentTest.php`:

```php
<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\Color;
use draw\Group;
use draw\Path;
use draw\RenderContext;
use draw\SVGDocument;
use draw\Shape;
use draw\Transform;
use PHPUnit\Framework\TestCase;

class SVGDocumentTest extends TestCase
{
    public function test_getRoot_returns_group(): void
    {
        $root = new Group();
        $doc = new SVGDocument($root);
        $this->assertSame($root, $doc->getRoot());
    }

    public function test_viewBox_defaults_null(): void
    {
        $doc = new SVGDocument(new Group());
        $this->assertNull($doc->getViewBox());
    }

    public function test_viewBox_stored(): void
    {
        $doc = new SVGDocument(new Group(), viewBox: [0.0, 0.0, 100.0, 100.0]);
        $this->assertSame([0.0, 0.0, 100.0, 100.0], $doc->getViewBox());
    }

    public function test_width_height_defaults_null(): void
    {
        $doc = new SVGDocument(new Group());
        $this->assertNull($doc->getWidth());
        $this->assertNull($doc->getHeight());
    }

    public function test_width_height_stored(): void
    {
        $doc = new SVGDocument(new Group(), width: 200.0, height: 100.0);
        $this->assertSame(200.0, $doc->getWidth());
        $this->assertSame(100.0, $doc->getHeight());
    }

    public function test_getViewBoxTransform_no_viewBox_returns_null(): void
    {
        $doc = new SVGDocument(new Group());
        $this->assertNull($doc->getViewBoxTransform(80, 24));
    }

    public function test_getViewBoxTransform_uniform_scale_meet(): void
    {
        $doc = new SVGDocument(new Group(), viewBox: [0.0, 0.0, 100.0, 100.0]);
        $t = $doc->getViewBoxTransform(100.0, 100.0);
        $this->assertNotNull($t);
        $result = $t->apply(50.0, 50.0);
        $this->assertEqualsWithDelta(50.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(50.0, $result[1], 0.001);
    }

    public function test_getViewBoxTransform_meet_scales_to_smaller(): void
    {
        $doc = new SVGDocument(new Group(), viewBox: [0.0, 0.0, 100.0, 100.0]);
        $t = $doc->getViewBoxTransform(200.0, 100.0);
        $this->assertNotNull($t);
        $result = $t->apply(0.0, 0.0);
        $this->assertEqualsWithDelta(50.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(0.0, $result[1], 0.001);
        $br = $t->apply(100.0, 100.0);
        $this->assertEqualsWithDelta(150.0, $br[0], 0.001);
        $this->assertEqualsWithDelta(100.0, $br[1], 0.001);
    }

    public function test_getViewBoxTransform_slice_scales_to_larger(): void
    {
        $doc = new SVGDocument(new Group(), viewBox: [0.0, 0.0, 100.0, 100.0]);
        $t = $doc->getViewBoxTransform(200.0, 100.0, 'xMidYMid slice');
        $this->assertNotNull($t);
        $result = $t->apply(0.0, 0.0);
        $this->assertEqualsWithDelta(0.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(-50.0, $result[1], 0.001);
    }

    public function test_getViewBoxTransform_none_stretches(): void
    {
        $doc = new SVGDocument(new Group(), viewBox: [0.0, 0.0, 100.0, 100.0]);
        $t = $doc->getViewBoxTransform(200.0, 50.0, 'none');
        $this->assertNotNull($t);
        $result = $t->apply(100.0, 100.0);
        $this->assertEqualsWithDelta(200.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(50.0, $result[1], 0.001);
    }

    public function test_getViewBoxTransform_with_offset_viewBox(): void
    {
        $doc = new SVGDocument(new Group(), viewBox: [50.0, 50.0, 100.0, 100.0]);
        $t = $doc->getViewBoxTransform(100.0, 100.0);
        $result = $t->apply(50.0, 50.0);
        $this->assertEqualsWithDelta(0.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(0.0, $result[1], 0.001);
    }

    public function test_render_applies_viewBox_transform(): void
    {
        $shape = new Shape(path: Path::rect(0, 0, 10, 10), fill: new Color(4, null));
        $root = new Group();
        $root->addChild($shape);
        $doc = new SVGDocument($root, viewBox: [0.0, 0.0, 10.0, 10.0]);

        $canvas = Canvas::createBlank(20, 20);
        $doc->render($canvas);

        $this->assertNotNull($canvas->data[0][0]->fg);
        $this->assertNotNull($canvas->data[19][19]->fg);
    }

    public function test_render_without_viewBox(): void
    {
        $shape = new Shape(path: Path::rect(0, 0, 10, 10), fill: new Color(4, null));
        $root = new Group();
        $root->addChild($shape);
        $doc = new SVGDocument($root);

        $canvas = Canvas::createBlank(10, 10);
        $doc->render($canvas);

        $this->assertNotNull($canvas->data[0][0]->fg);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter SVGDocumentTest`
Expected: FAIL — class `draw\SVGDocument` not found

- [ ] **Step 3: Write SVGDocument implementation**

Create `library/draw/SVGDocument.php`:

```php
<?php

namespace draw;

use Psr\Log\LoggerInterface;

class SVGDocument
{
    public function __construct(
        private readonly SceneNode $root,
        private readonly ?array $viewBox = null,
        private readonly ?float $width = null,
        private readonly ?float $height = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function getRoot(): SceneNode
    {
        return $this->root;
    }

    public function getViewBox(): ?array
    {
        return $this->viewBox;
    }

    public function getWidth(): ?float
    {
        return $this->width;
    }

    public function getHeight(): ?float
    {
        return $this->height;
    }

    public function getViewBoxTransform(float $targetWidth, float $targetHeight, string $aspectRatio = 'xMidYMid meet'): ?Transform
    {
        if ($this->viewBox === null) {
            return null;
        }

        [$vbX, $vbY, $vbW, $vbH] = $this->viewBox;

        if ($vbW <= 0 || $vbH <= 0) {
            return null;
        }

        $scaleX = $targetWidth / $vbW;
        $scaleY = $targetHeight / $vbH;

        $parts = preg_split('/\s+/', trim($aspectRatio));
        $align = $parts[0] ?? 'xMidYMid';
        $meetOrSlice = $parts[1] ?? 'meet';

        if ($align === 'none') {
            $sx = $scaleX;
            $sy = $scaleY;
            $tx = 0.0;
            $ty = 0.0;
        } else {
            if ($meetOrSlice === 'meet') {
                $sx = min($scaleX, $scaleY);
                $sy = $sx;
            } else {
                $sx = max($scaleX, $scaleY);
                $sy = $sx;
            }

            $alignX = substr($align, 1, 3);
            $alignY = substr($align, 4, 4);

            $tx = match ($alignX) {
                'Min' => 0.0,
                'Max' => $targetWidth - $vbW * $sx,
                default => ($targetWidth - $vbW * $sx) / 2.0,
            };
            $ty = match ($alignY) {
                'Min' => 0.0,
                'Max' => $targetHeight - $vbH * $sy,
                default => ($targetHeight - $vbH * $sy) / 2.0,
            };
        }

        return Transform::translate($tx, $ty)
            ->multiply(Transform::scale($sx, $sy))
            ->multiply(Transform::translate(-$vbX, -$vbY));
    }

    public function render(Canvas $canvas, ?RenderContext $ctx = null): void
    {
        $ctx = $ctx ?? RenderContext::defaults();

        if ($this->viewBox !== null) {
            $t = $this->getViewBoxTransform($canvas->w, $canvas->h);
            if ($t !== null) {
                $canvas->save();
                $canvas->concatTransform($t);
            }
        }

        $this->root->render($canvas, $ctx);

        if ($this->viewBox !== null) {
            $canvas->restore();
        }
    }
}
```

- [ ] **Step 4: Run tests**

Run: `composer test -- --filter SVGDocumentTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add library/draw/SVGDocument.php tests/Canvas/SVGDocumentTest.php
git commit -m "Add SVGDocument with viewBox transform and render convenience"
```

---

### Task 4: SVGParser — SVG Path `d` String Parsing

**Files:**
- Create: `library/draw/SVGParser.php` (initial, with `parseDString` only)
- Test: `tests/Canvas/SVGParserTest.php` (initial)

- [ ] **Step 1: Write the failing tests for path `d` string parsing**

Create `tests/Canvas/SVGParserTest.php`:

```php
<?php

namespace Tests\Canvas;

use draw\Path;
use draw\SVGParser;
use PHPUnit\Framework\TestCase;

class SVGParserTest extends TestCase
{
    public function test_parse_d_moveto_lineto(): void
    {
        $path = SVGParser::parseDString('M 10 20 L 30 40');
        $this->assertSame([30.0, 40.0], $path->getCurrentPoint());
        $subpaths = $path->flatten();
        $this->assertCount(1, $subpaths);
        $this->assertEqualsWithDelta(10.0, $subpaths[0]['vertices'][0][0], 0.001);
        $this->assertEqualsWithDelta(40.0, $subpaths[0]['vertices'][1][1], 0.001);
    }

    public function test_parse_d_relative_moveto_lineto(): void
    {
        $path = SVGParser::parseDString('M 10 20 l 5 10');
        $this->assertSame([15.0, 30.0], $path->getCurrentPoint());
    }

    public function test_parse_d_implicit_lineto_repeat(): void
    {
        $path = SVGParser::parseDString('M 0 0 L 10 10 20 20');
        $this->assertSame([20.0, 20.0], $path->getCurrentPoint());
    }

    public function test_parse_d_horizontal_vertical(): void
    {
        $path = SVGParser::parseDString('M 10 20 H 30 V 50');
        $this->assertSame([30.0, 50.0], $path->getCurrentPoint());
    }

    public function test_parse_d_closepath(): void
    {
        $path = SVGParser::parseDString('M 10 20 L 30 40 Z');
        $subpaths = $path->flatten();
        $this->assertTrue($subpaths[0]['closed']);
    }

    public function test_parse_d_cubic_bezier(): void
    {
        $path = SVGParser::parseDString('M 0 0 C 10 20 30 40 50 60');
        $this->assertSame([50.0, 60.0], $path->getCurrentPoint());
    }

    public function test_parse_d_smooth_cubic(): void
    {
        $path = SVGParser::parseDString('M 0 0 C 10 20 30 40 50 60 S 90 100 110 120');
        $this->assertSame([110.0, 120.0], $path->getCurrentPoint());
    }

    public function test_parse_d_quadratic_bezier(): void
    {
        $path = SVGParser::parseDString('M 0 0 Q 25 50 50 0');
        $this->assertSame([50.0, 0.0], $path->getCurrentPoint());
    }

    public function test_parse_d_smooth_quadratic(): void
    {
        $path = SVGParser::parseDString('M 0 0 Q 25 50 50 0 T 100 0');
        $this->assertSame([100.0, 0.0], $path->getCurrentPoint());
    }

    public function test_parse_d_arc(): void
    {
        $path = SVGParser::parseDString('M 0 0 A 25 25 0 0 1 50 50');
        $this->assertSame([50.0, 50.0], $path->getCurrentPoint());
    }

    public function test_parse_d_multiple_subpaths(): void
    {
        $path = SVGParser::parseDString('M 0 0 L 10 10 M 20 20 L 30 30');
        $subpaths = $path->flatten();
        $this->assertCount(2, $subpaths);
    }

    public function test_parse_d_comma_separated(): void
    {
        $path = SVGParser::parseDString('M 10,20 L 30,40');
        $this->assertSame([30.0, 40.0], $path->getCurrentPoint());
    }

    public function test_parse_d_negative_as_delimiter(): void
    {
        $path = SVGParser::parseDString('M 10-20L30-40');
        $this->assertSame([30.0, -40.0], $path->getCurrentPoint());
    }

    public function test_parse_d_arc_flags_no_separator(): void
    {
        $path = SVGParser::parseDString('M 0 0 A 25 25 0 01 50 50');
        $this->assertSame([50.0, 50.0], $path->getCurrentPoint());
    }

    public function test_parse_d_empty_string_returns_empty_path(): void
    {
        $path = SVGParser::parseDString('');
        $this->assertTrue($path->isEmpty());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter SVGParserTest`
Expected: FAIL — class `draw\SVGParser` not found

- [ ] **Step 3: Write SVGParser with parseDString implementation**

Create `library/draw/SVGParser.php`. The `parseDString` method tokenizes the `d` attribute and maps commands to Path builder calls. Public static methods `parseString` and `readFile` will be added in Task 6; for now, only `parseDString` is public.

```php
<?php

namespace draw;

use Psr\Log\LoggerInterface;

class SVGParser
{
    public static function parseDString(string $d): Path
    {
        $path = new Path();
        $d = trim($d);
        if ($d === '') {
            return $path;
        }

        $tokens = self::tokenizeD($d);
        $i = 0;
        $n = count($tokens);
        $prevCmd = '';

        while ($i < $n) {
            $tok = $tokens[$i];

            if (strlen($tok) === 1 && ctype_alpha($tok)) {
                $cmd = $tok;
                $i++;
            } else {
                $cmd = $prevCmd;
                if ($cmd === '') {
                    $i++;
                    continue;
                }
            }

            $upper = strtoupper($cmd);
            $relative = $cmd !== $upper;

            switch ($upper) {
                case 'M':
                    $x = (float)$tokens[$i++];
                    $y = (float)$tokens[$i++];
                    if ($relative && $prevCmd !== '') {
                        $cp = $path->getCurrentPoint();
                        $x += $cp[0];
                        $y += $cp[1];
                    }
                    $path->moveTo($x, $y);
                    $prevCmd = $relative ? 'l' : 'L';
                    break;

                case 'L':
                    $x = (float)$tokens[$i++];
                    $y = (float)$tokens[$i++];
                    if ($relative) {
                        $cp = $path->getCurrentPoint();
                        $x += $cp[0];
                        $y += $cp[1];
                    }
                    $path->lineTo($x, $y);
                    break;

                case 'H':
                    $x = (float)$tokens[$i++];
                    if ($relative) {
                        $cp = $path->getCurrentPoint();
                        $x += $cp[0];
                    }
                    $path->horizontalLineTo($x);
                    break;

                case 'V':
                    $y = (float)$tokens[$i++];
                    if ($relative) {
                        $cp = $path->getCurrentPoint();
                        $y += $cp[1];
                    }
                    $path->verticalLineTo($y);
                    break;

                case 'C':
                    $c1x = (float)$tokens[$i++];
                    $c1y = (float)$tokens[$i++];
                    $c2x = (float)$tokens[$i++];
                    $c2y = (float)$tokens[$i++];
                    $x = (float)$tokens[$i++];
                    $y = (float)$tokens[$i++];
                    if ($relative) {
                        $cp = $path->getCurrentPoint();
                        $c1x += $cp[0]; $c1y += $cp[1];
                        $c2x += $cp[0]; $c2y += $cp[1];
                        $x += $cp[0]; $y += $cp[1];
                    }
                    $path->cubicTo($c1x, $c1y, $c2x, $c2y, $x, $y);
                    break;

                case 'S':
                    $c2x = (float)$tokens[$i++];
                    $c2y = (float)$tokens[$i++];
                    $x = (float)$tokens[$i++];
                    $y = (float)$tokens[$i++];
                    if ($relative) {
                        $cp = $path->getCurrentPoint();
                        $c2x += $cp[0]; $c2y += $cp[1];
                        $x += $cp[0]; $y += $cp[1];
                    }
                    $path->smoothCubicTo($c2x, $c2y, $x, $y);
                    break;

                case 'Q':
                    $cpx = (float)$tokens[$i++];
                    $cpy = (float)$tokens[$i++];
                    $x = (float)$tokens[$i++];
                    $y = (float)$tokens[$i++];
                    if ($relative) {
                        $cp = $path->getCurrentPoint();
                        $cpx += $cp[0]; $cpy += $cp[1];
                        $x += $cp[0]; $y += $cp[1];
                    }
                    $path->quadTo($cpx, $cpy, $x, $y);
                    break;

                case 'T':
                    $x = (float)$tokens[$i++];
                    $y = (float)$tokens[$i++];
                    if ($relative) {
                        $cp = $path->getCurrentPoint();
                        $x += $cp[0]; $y += $cp[1];
                    }
                    $path->smoothQuadTo($x, $y);
                    break;

                case 'A':
                    $rx = (float)$tokens[$i++];
                    $ry = (float)$tokens[$i++];
                    $rot = (float)$tokens[$i++];
                    $largeArc = (bool)(int)$tokens[$i++];
                    $sweep = (bool)(int)$tokens[$i++];
                    $x = (float)$tokens[$i++];
                    $y = (float)$tokens[$i++];
                    if ($relative) {
                        $cp = $path->getCurrentPoint();
                        $x += $cp[0]; $y += $cp[1];
                    }
                    $path->arcTo($rx, $ry, $rot, $largeArc, $sweep, $x, $y);
                    break;

                case 'Z':
                    $path->closePath();
                    break;

                default:
                    $i++;
                    break;
            }

            if ($upper !== 'M') {
                $prevCmd = $cmd;
            }
        }

        return $path;
    }

    private static function tokenizeD(string $d): array
    {
        $tokens = [];
        $len = strlen($d);
        $i = 0;

        while ($i < $len) {
            $ch = $d[$i];

            if (ctype_space($ch) || $ch === ',') {
                $i++;
                continue;
            }

            if (ctype_alpha($ch)) {
                $tokens[] = $ch;
                $i++;
                continue;
            }

            if ($ch === '-' || $ch === '+' || $ch === '.' || ctype_digit($ch)) {
                $start = $i;
                if ($ch === '-' || $ch === '+') {
                    $i++;
                }
                $hasDot = false;
                while ($i < $len && (ctype_digit($d[$i]) || ($d[$i] === '.' && !$hasDot))) {
                    if ($d[$i] === '.') {
                        $hasDot = true;
                    }
                    $i++;
                }
                $tokens[] = substr($d, $start, $i - $start);
                continue;
            }

            $i++;
        }

        return $tokens;
    }
}
```

- [ ] **Step 4: Run tests**

Run: `composer test -- --filter SVGParserTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add library/draw/SVGParser.php tests/Canvas/SVGParserTest.php
git commit -m "Add SVGParser with SVG path d string parsing"
```

---

### Task 5: SVGParser — Transform String Parsing

**Files:**
- Modify: `library/draw/SVGParser.php`
- Modify: `tests/Canvas/SVGParserTest.php`

- [ ] **Step 1: Add failing tests for transform string parsing**

Append to `SVGParserTest`:

```php
    public function test_parse_transform_translate(): void
    {
        $t = SVGParser::parseTransform('translate(10, 20)');
        $result = $t->apply(0.0, 0.0);
        $this->assertEqualsWithDelta(10.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(20.0, $result[1], 0.001);
    }

    public function test_parse_transform_translate_one_arg(): void
    {
        $t = SVGParser::parseTransform('translate(10)');
        $result = $t->apply(0.0, 0.0);
        $this->assertEqualsWithDelta(10.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(0.0, $result[1], 0.001);
    }

    public function test_parse_transform_scale(): void
    {
        $t = SVGParser::parseTransform('scale(2, 3)');
        $result = $t->apply(10.0, 10.0);
        $this->assertEqualsWithDelta(20.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(30.0, $result[1], 0.001);
    }

    public function test_parse_transform_scale_one_arg(): void
    {
        $t = SVGParser::parseTransform('scale(2)');
        $result = $t->apply(10.0, 10.0);
        $this->assertEqualsWithDelta(20.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(20.0, $result[1], 0.001);
    }

    public function test_parse_transform_rotate(): void
    {
        $t = SVGParser::parseTransform('rotate(90)');
        $result = $t->apply(1.0, 0.0);
        $this->assertEqualsWithDelta(0.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(1.0, $result[1], 0.001);
    }

    public function test_parse_transform_rotate_with_center(): void
    {
        $t = SVGParser::parseTransform('rotate(90, 50, 50)');
        $result = $t->apply(51.0, 50.0);
        $this->assertEqualsWithDelta(50.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(51.0, $result[1], 0.001);
    }

    public function test_parse_transform_skewX(): void
    {
        $t = SVGParser::parseTransform('skewX(45)');
        $result = $t->apply(1.0, 1.0);
        $this->assertEqualsWithDelta(2.0, $result[0], 0.01);
        $this->assertEqualsWithDelta(1.0, $result[1], 0.001);
    }

    public function test_parse_transform_skewY(): void
    {
        $t = SVGParser::parseTransform('skewY(45)');
        $result = $t->apply(1.0, 1.0);
        $this->assertEqualsWithDelta(1.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(2.0, $result[1], 0.01);
    }

    public function test_parse_transform_matrix(): void
    {
        $t = SVGParser::parseTransform('matrix(2, 0, 0, 3, 10, 20)');
        $result = $t->apply(5.0, 5.0);
        $this->assertEqualsWithDelta(20.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(35.0, $result[1], 0.001);
    }

    public function test_parse_transform_chained(): void
    {
        $t = SVGParser::parseTransform('translate(10, 0) scale(2)');
        $result = $t->apply(5.0, 5.0);
        $this->assertEqualsWithDelta(20.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(10.0, $result[1], 0.001);
    }

    public function test_parse_transform_empty_returns_identity(): void
    {
        $t = SVGParser::parseTransform('');
        $this->assertTrue(Transform::identity()->equals($t));
    }
```

Add `use draw\Transform;` to the test file imports if not already present.

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter SVGParserTest::test_parse_transform`
Expected: FAIL — method `parseTransform` not found

- [ ] **Step 3: Add `parseTransform` to SVGParser**

Add this public static method to `SVGParser`:

```php
    public static function parseTransform(string $s): Transform
    {
        $s = trim($s);
        if ($s === '') {
            return Transform::identity();
        }

        $result = Transform::identity();

        preg_match_all('/(\w+)\s*\(([^)]*)\)/', $s, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $func = strtolower($match[1]);
            $args = preg_split('/[\s,]+/', trim($match[2]));
            $args = array_map('floatval', $args);

            $t = match ($func) {
                'translate' => Transform::translate($args[0], $args[1] ?? 0.0),
                'scale' => Transform::scale($args[0], $args[1] ?? $args[0]),
                'rotate' => Transform::rotate(deg2rad($args[0]), ($args[1] ?? 0.0), ($args[2] ?? 0.0)),
                'skewx' => Transform::skewX(deg2rad($args[0])),
                'skewy' => Transform::skewY(deg2rad($args[0])),
                'matrix' => Transform::matrix($args[0], $args[1], $args[2], $args[3], $args[4], $args[5]),
                default => Transform::identity(),
            };

            $result = $result->multiply($t);
        }

        return $result;
    }
```

- [ ] **Step 4: Run tests**

Run: `composer test -- --filter SVGParserTest::test_parse_transform`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add library/draw/SVGParser.php tests/Canvas/SVGParserTest.php
git commit -m "Add SVG transform string parsing to SVGParser"
```

---

### Task 6: SVGParser — Shape Element Parsers

**Files:**
- Modify: `library/draw/SVGParser.php`
- Modify: `tests/Canvas/SVGParserTest.php`

This task adds private element handler methods for shape elements and the public `parseString`/`readFile` entry points. The handlers parse SVG attributes and construct Shape/Group objects.

- [ ] **Step 1: Add failing tests for full SVG parsing**

Append to `SVGParserTest`:

```php
    public function test_parse_string_rect(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect x="5" y="5" width="10" height="10" fill="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(20, 20);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
        $this->assertNull($canvas->data[0][0]->fg);
    }

    public function test_parse_string_circle(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="10" r="5" fill="blue"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(20, 20);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[10][10]->fg);
    }

    public function test_parse_string_ellipse(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><ellipse cx="15" cy="10" rx="10" ry="5" fill="green"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(30, 20);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[10][15]->fg);
    }

    public function test_parse_string_line(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><line x1="0" y1="5" x2="19" y2="5" stroke="white"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(20, 10);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][10]->fg);
    }

    public function test_parse_string_polyline(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><polyline points="0,0 10,0 10,10" stroke="white"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(20, 20);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[0][5]->fg);
    }

    public function test_parse_string_polygon(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><polygon points="5,0 10,10 0,10" fill="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_path_element(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><path d="M 0 0 L 10 0 L 10 10 L 0 10 Z" fill="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_group(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><g fill="red"><rect x="0" y="0" width="5" height="5"/></g></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(10, 10);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[2][2]->fg);
    }

    public function test_parse_string_viewBox(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect x="0" y="0" width="50" height="50" fill="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $this->assertSame([0.0, 0.0, 100.0, 100.0], $doc->getViewBox());
        $canvas = Canvas::createBlank(40, 40);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[10][10]->fg);
    }

    public function test_parse_string_transform_attribute(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect x="0" y="0" width="5" height="5" fill="red" transform="translate(5,5)"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNull($canvas->data[0][0]->fg);
        $this->assertNotNull($canvas->data[7][7]->fg);
    }

    public function test_parse_string_unknown_element_ignored(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect x="0" y="0" width="10" height="10" fill="red"/><text>Hello</text></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_opacity(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect x="0" y="0" width="10" height="10" fill="red" opacity="0.5"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_fill_none(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect x="0" y="0" width="10" height="10" fill="none" stroke="white"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[0][5]->fg);
    }
```

Add these imports to the test file: `use draw\Canvas;`, `use draw\SVGDocument;`.

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter SVGParserTest::test_parse_string`
Expected: FAIL — `parseString` method not found or not working

- [ ] **Step 3: Add parseString, readFile, and element handler methods to SVGParser**

Add the following methods to `SVGParser`. The full class now has `parseDString`, `parseTransform`, `parseString`, `readFile`, and private helpers.

```php
    public static function parseString(string $svg, ?LoggerInterface $logger = null): SVGDocument
    {
        $xml = @simplexml_load_string($svg);
        if ($xml === false) {
            throw new \InvalidArgumentException('Failed to parse SVG XML');
        }

        $defs = [];
        $root = self::parseElement($xml, $defs, $logger);

        $viewBox = null;
        $vb = (string)($xml['viewBox'] ?? '');
        if ($vb !== '') {
            $parts = preg_split('/[\s,]+/', trim($vb));
            if (count($parts) === 4) {
                $viewBox = [(float)$parts[0], (float)$parts[1], (float)$parts[2], (float)$parts[3]];
            }
        }

        $width = isset($xml['width']) ? (float)$xml['width'] : null;
        $height = isset($xml['height']) ? (float)$xml['height'] : null;

        return new SVGDocument($root, $viewBox, $width, $height, $logger);
    }

    public static function readFile(string $path, ?LoggerInterface $logger = null): SVGDocument
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \InvalidArgumentException("Failed to read SVG file: $path");
        }
        return self::parseString($content, $logger);
    }

    private static function parseElement(\SimpleXMLElement $el, array &$defs, ?LoggerInterface $logger): SceneNode
    {
        $name = $el->getName();
        $ns = $el->getNamespaces();
        $localName = $name;

        return match ($localName) {
            'svg' => self::parseSvgElement($el, $defs, $logger),
            'g' => self::parseGroupElement($el, $defs, $logger),
            'path' => self::parsePathElement($el, $defs, $logger),
            'rect' => self::parseRectElement($el, $defs, $logger),
            'circle' => self::parseCircleElement($el, $defs, $logger),
            'ellipse' => self::parseEllipseElement($el, $defs, $logger),
            'line' => self::parseLineElement($el, $defs, $logger),
            'polyline' => self::parsePolylineElement($el, $defs, $logger),
            'polygon' => self::parsePolygonElement($el, $defs, $logger),
            'defs' => self::parseDefsElement($el, $defs, $logger),
            'linearGradient', 'radialGradient' => self::parseDefsElement($el->xpath('..')[0] ?? $el, $defs, $logger),
            default => new Group(),
        };
    }

    private static function parseSvgElement(\SimpleXMLElement $el, array &$defs, ?LoggerInterface $logger): Group
    {
        $group = new Group(transform: self::parseOptionalTransform($el));

        foreach ($el->children() as $child) {
            $childName = $child->getName();
            if ($childName === 'defs') {
                self::parseDefsElement($child, $defs, $logger);
                continue;
            }
            if ($childName === 'linearGradient' || $childName === 'radialGradient') {
                self::parseGradientElement($child, $defs, $logger);
                continue;
            }
            $node = self::parseElement($child, $defs, $logger);
            if ($node !== null) {
                $group->addChild($node);
            }
        }

        return $group;
    }

    private static function parseGroupElement(\SimpleXMLElement $el, array &$defs, ?LoggerInterface $logger): Group
    {
        $group = new Group(
            fill: self::parsePaintAttr($el, 'fill', $defs, $logger),
            stroke: self::parseStrokeAttr($el, $defs, $logger),
            transform: self::parseOptionalTransform($el),
            opacity: self::parseFloatAttr($el, 'opacity'),
            fillOpacity: self::parseFloatAttr($el, 'fill-opacity'),
            fillRule: self::parseFillRuleAttr($el),
        );

        foreach ($el->children() as $child) {
            $childName = $child->getName();
            if ($childName === 'linearGradient' || $childName === 'radialGradient') {
                self::parseGradientElement($child, $defs, $logger);
                continue;
            }
            $node = self::parseElement($child, $defs, $logger);
            if ($node !== null) {
                $group->addChild($node);
            }
        }

        return $group;
    }

    private static function parsePathElement(\SimpleXMLElement $el, array &$defs, ?LoggerInterface $logger): Shape
    {
        $d = (string)($el['d'] ?? '');
        $path = self::parseDString($d);
        return self::buildShape($path, $el, $defs, $logger);
    }

    private static function parseRectElement(\SimpleXMLElement $el, array &$defs, ?LoggerInterface $logger): Shape
    {
        $x = (float)($el['x'] ?? 0);
        $y = (float)($el['y'] ?? 0);
        $w = (float)($el['width'] ?? 0);
        $h = (float)($el['height'] ?? 0);
        $rx = (float)($el['rx'] ?? 0);
        $ry = (float)($el['ry'] ?? 0);
        $path = Path::rect($x, $y, $w, $h, $rx, $ry);
        return self::buildShape($path, $el, $defs, $logger);
    }

    private static function parseCircleElement(\SimpleXMLElement $el, array &$defs, ?LoggerInterface $logger): Shape
    {
        $cx = (float)($el['cx'] ?? 0);
        $cy = (float)($el['cy'] ?? 0);
        $r = (float)($el['r'] ?? 0);
        $path = Path::circle($cx, $cy, $r);
        return self::buildShape($path, $el, $defs, $logger);
    }

    private static function parseEllipseElement(\SimpleXMLElement $el, array &$defs, ?LoggerInterface $logger): Shape
    {
        $cx = (float)($el['cx'] ?? 0);
        $cy = (float)($el['cy'] ?? 0);
        $rx = (float)($el['rx'] ?? 0);
        $ry = (float)($el['ry'] ?? 0);
        $path = Path::ellipse($cx, $cy, $rx, $ry);
        return self::buildShape($path, $el, $defs, $logger);
    }

    private static function parseLineElement(\SimpleXMLElement $el, array &$defs, ?LoggerInterface $logger): Shape
    {
        $x1 = (float)($el['x1'] ?? 0);
        $y1 = (float)($el['y1'] ?? 0);
        $x2 = (float)($el['x2'] ?? 0);
        $y2 = (float)($el['y2'] ?? 0);
        $path = Path::line($x1, $y1, $x2, $y2);
        return self::buildShape($path, $el, $defs, $logger);
    }

    private static function parsePolylineElement(\SimpleXMLElement $el, array &$defs, ?LoggerInterface $logger): Shape
    {
        $points = self::parsePointsAttr((string)($el['points'] ?? ''));
        $path = Path::polyline($points);
        return self::buildShape($path, $el, $defs, $logger);
    }

    private static function parsePolygonElement(\SimpleXMLElement $el, array &$defs, ?LoggerInterface $logger): Shape
    {
        $points = self::parsePointsAttr((string)($el['points'] ?? ''));
        $path = Path::polygon($points);
        return self::buildShape($path, $el, $defs, $logger);
    }

    private static function parseDefsElement(\SimpleXMLElement $el, array &$defs, ?LoggerInterface $logger): Group
    {
        foreach ($el->children() as $child) {
            $childName = $child->getName();
            if ($childName === 'linearGradient' || $childName === 'radialGradient') {
                self::parseGradientElement($child, $defs, $logger);
            }
        }
        return new Group();
    }

    private static function buildShape(Path $path, \SimpleXMLElement $el, array &$defs, ?LoggerInterface $logger): Shape
    {
        return new Shape(
            path: $path,
            fill: self::parsePaintAttr($el, 'fill', $defs, $logger),
            stroke: self::parseStrokeAttr($el, $defs, $logger),
            transform: self::parseOptionalTransform($el),
            opacity: self::parseFloatAttr($el, 'opacity'),
            fillOpacity: self::parseFloatAttr($el, 'fill-opacity'),
            fillRule: self::parseFillRuleAttr($el),
        );
    }

    private static function parsePaintAttr(\SimpleXMLElement $el, string $attr, array &$defs, ?LoggerInterface $logger): ?Paint
    {
        $val = (string)($el[$attr] ?? '');
        if ($val === '' || $val === 'none') {
            return null;
        }

        if (preg_match('/^url\(#(.+)\)$/', $val, $m)) {
            $id = $m[1];
            if (isset($defs[$id])) {
                return $defs[$id];
            }
            $logger?->warning("SVG reference not found: #{$id}");
            return null;
        }

        $rgb = SvgColor::parse($val);
        if ($rgb === null) {
            return null;
        }

        $code = IrcPalette::nearestColor($rgb[0], $rgb[1], $rgb[2]);
        return new Color($code, null);
    }

    private static function parseStrokeAttr(\SimpleXMLElement $el, array &$defs, ?LoggerInterface $logger): ?StrokeStyle
    {
        $strokeVal = (string)($el['stroke'] ?? '');
        if ($strokeVal === '' || $strokeVal === 'none') {
            return null;
        }

        $paint = null;
        if (preg_match('/^url\(#(.+)\)$/', $strokeVal, $m)) {
            $id = $m[1];
            if (isset($defs[$id])) {
                $paint = $defs[$id];
            } else {
                $logger?->warning("SVG stroke reference not found: #{$id}");
                return null;
            }
        } else {
            $rgb = SvgColor::parse($strokeVal);
            if ($rgb === null) {
                return null;
            }
            $code = IrcPalette::nearestColor($rgb[0], $rgb[1], $rgb[2]);
            $paint = new Color($code, null);
        }

        $width = (float)($el['stroke-width'] ?? 1.0);
        if ($width < 0) {
            $width = 1.0;
        }

        $dashArray = null;
        $dashStr = (string)($el['stroke-dasharray'] ?? '');
        if ($dashStr !== '' && $dashStr !== 'none') {
            $dashArray = array_map('floatval', preg_split('/[\s,]+/', trim($dashStr)));
            $dashArray = array_filter($dashArray, fn($v) => $v > 0);
            if (empty($dashArray)) {
                $dashArray = null;
            }
        }

        $lineCap = match ((string)($el['stroke-linecap'] ?? 'butt')) {
            'round' => LineCap::Round,
            'square' => LineCap::Square,
            default => LineCap::Butt,
        };

        $lineJoin = match ((string)($el['stroke-linejoin'] ?? 'miter')) {
            'round' => LineJoin::Round,
            'bevel' => LineJoin::Bevel,
            default => LineJoin::Miter,
        };

        $miterLimit = (float)($el['stroke-miterlimit'] ?? 4.0);
        $strokeOpacity = self::parseFloatAttr($el, 'stroke-opacity') ?? 1.0;

        return new StrokeStyle(
            paint: $paint,
            width: $width,
            dashArray: $dashArray,
            dashOffset: (float)($el['stroke-dashoffset'] ?? 0.0),
            lineCap: $lineCap,
            lineJoin: $lineJoin,
            miterLimit: $miterLimit,
            opacity: $strokeOpacity,
        );
    }

    private static function parseOptionalTransform(\SimpleXMLElement $el): ?Transform
    {
        $val = (string)($el['transform'] ?? '');
        if ($val === '') {
            return null;
        }
        $t = self::parseTransform($val);
        if (Transform::identity()->equals($t)) {
            return null;
        }
        return $t;
    }

    private static function parseFloatAttr(\SimpleXMLElement $el, string $attr): ?float
    {
        $val = (string)($el[$attr] ?? '');
        if ($val === '') {
            return null;
        }
        return (float)$val;
    }

    private static function parseFillRuleAttr(\SimpleXMLElement $el): ?FillRule
    {
        $val = (string)($el['fill-rule'] ?? '');
        return match ($val) {
            'evenodd' => FillRule::EvenOdd,
            'nonzero' => FillRule::NonZero,
            default => null,
        };
    }

    private static function parsePointsAttr(string $s): array
    {
        $nums = preg_split('/[\s,]+/', trim($s));
        $points = [];
        for ($i = 0; $i + 1 < count($nums); $i += 2) {
            $points[] = [(float)$nums[$i], (float)$nums[$i + 1]];
        }
        return $points;
    }
```

- [ ] **Step 4: Run tests**

Run: `composer test -- --filter SVGParserTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add library/draw/SVGParser.php tests/Canvas/SVGParserTest.php
git commit -m "Add SVGParser shape/group/transform element parsing with parseString/readFile"
```

---

### Task 7: SVGParser — Gradient Element Parsing

**Files:**
- Modify: `library/draw/SVGParser.php`
- Modify: `tests/Canvas/SVGParserTest.php`

- [ ] **Step 1: Add failing tests for gradient parsing**

Append to `SVGParserTest`:

```php
    public function test_parse_string_linear_gradient(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g1" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#ff0000"/><stop offset="1" stop-color="#0000ff"/></linearGradient></defs><rect x="0" y="0" width="20" height="10" fill="url(#g1)"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(20, 10);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
        $this->assertNotNull($canvas->data[5][15]->fg);
    }

    public function test_parse_string_radial_gradient(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><radialGradient id="rg" cx="10" cy="10" r="10"><stop offset="0" stop-color="white"/><stop offset="1" stop-color="black"/></radialGradient></defs><rect x="0" y="0" width="20" height="20" fill="url(#rg)"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(20, 20);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[10][10]->fg);
    }

    public function test_parse_string_gradient_with_percentage_stops(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g2" x1="0%" y1="0%" x2="100%" y2="0%"><stop offset="0%" stop-color="red"/><stop offset="100%" stop-color="blue"/></linearGradient></defs><rect x="0" y="0" width="10" height="10" fill="url(#g2)"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(10, 10);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_missing_gradient_ref_no_fill(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect x="0" y="0" width="10" height="10" fill="url(#missing)"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(10, 10);
        $doc->render($canvas);
        $this->assertNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_gradient_spread_reflect(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g3" x1="0" y1="0" x2="5" y2="0" spreadMethod="reflect"><stop offset="0" stop-color="red"/><stop offset="1" stop-color="blue"/></linearGradient></defs><rect x="0" y="0" width="20" height="5" fill="url(#g3)"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(20, 5);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[2][10]->fg);
    }
```

Add these imports to the test file: `use draw\Color;`, `use draw\SVGDocument;` (if not already present).

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter SVGParserTest::test_parse_string_linear_gradient`
Expected: FAIL — gradient not resolved

- [ ] **Step 3: Add gradient parsing methods to SVGParser**

Add `parseGradientElement` and `parseGradientStops` private methods:

```php
    private static function parseGradientElement(\SimpleXMLElement $el, array &$defs, ?LoggerInterface $logger): void
    {
        $id = (string)($el['id'] ?? '');
        if ($id === '') {
            return;
        }

        $stops = self::parseGradientStops($el);
        if (count($stops) < 2) {
            return;
        }

        $spread = match ((string)($el['spreadMethod'] ?? 'pad')) {
            'reflect' => SpreadMethod::Reflect,
            'repeat' => SpreadMethod::Repeat,
            default => SpreadMethod::Pad,
        };

        $name = $el->getName();
        if ($name === 'linearGradient') {
            $x1 = self::parseGradientCoord($el, 'x1', 0.0);
            $y1 = self::parseGradientCoord($el, 'y1', 0.0);
            $x2 = self::parseGradientCoord($el, 'x2', 1.0);
            $y2 = self::parseGradientCoord($el, 'y2', 0.0);
            $defs[$id] = new LinearGradient($x1, $y1, $x2, $y2, $stops, $spread);
        } elseif ($name === 'radialGradient') {
            $cx = self::parseGradientCoord($el, 'cx', 0.5);
            $cy = self::parseGradientCoord($el, 'cy', 0.5);
            $r = self::parseGradientCoord($el, 'r', 0.5);
            $fx = self::parseOptionalGradientCoord($el, 'fx');
            $fy = self::parseOptionalGradientCoord($el, 'fy');
            if ($r <= 0) {
                return;
            }
            $defs[$id] = new RadialGradient($cx, $cy, $r, $stops, $fx, $fy, $spread);
        }
    }

    private static function parseGradientStops(\SimpleXMLElement $el): array
    {
        $stops = [];
        foreach ($el->children() as $child) {
            if ($child->getName() !== 'stop') {
                continue;
            }
            $offsetStr = (string)($child['offset'] ?? '0');
            $offset = self::parsePercentageOrFloat($offsetStr);
            $colorStr = (string)($child['stop-color'] ?? '#000000');
            $rgb = SvgColor::parse($colorStr);
            if ($rgb === null) {
                $rgb = [0, 0, 0];
            }
            $stops[] = new ColorStop($offset, $rgb[0], $rgb[1], $rgb[2]);
        }
        usort($stops, fn($a, $b) => $a->offset <=> $b->offset);
        return $stops;
    }

    private static function parseGradientCoord(\SimpleXMLElement $el, string $attr, float $default): float
    {
        $val = (string)($el[$attr] ?? '');
        if ($val === '') {
            return $default;
        }
        return self::parsePercentageOrFloat($val);
    }

    private static function parseOptionalGradientCoord(\SimpleXMLElement $el, string $attr): ?float
    {
        $val = (string)($el[$attr] ?? '');
        if ($val === '') {
            return null;
        }
        return self::parsePercentageOrFloat($val);
    }

    private static function parsePercentageOrFloat(string $val): float
    {
        $val = trim($val);
        if (str_ends_with($val, '%')) {
            return (float)substr($val, 0, -1) / 100.0;
        }
        return (float)$val;
    }
```

Also update `parseSvgElement` to call `parseGradientElement` for top-level gradients and `parseGroupElement` for child gradients. The existing code in Task 6 already handles this via the children loop — verify it dispatches to `parseGradientElement` when it encounters a gradient outside `<defs>`.

- [ ] **Step 4: Run tests**

Run: `composer test -- --filter SVGParserTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add library/draw/SVGParser.php tests/Canvas/SVGParserTest.php
git commit -m "Add gradient element parsing to SVGParser"
```

---

### Task 8: SVGParser — Logging

**Files:**
- Modify: `library/draw/SVGParser.php`
- Modify: `tests/Canvas/SVGParserTest.php`

- [ ] **Step 1: Add failing tests for logging**

Append to `SVGParserTest`:

```php
    public function test_parse_string_logs_unknown_element(): void
    {
        $logger = new class extends \Psr\Log\AbstractLogger {
            public array $warnings = [];
            public function log($level, $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warnings[] = $message;
                }
            }
        };
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><text x="0" y="0">hello</text></svg>';
        SVGParser::parseString($svg, $logger);
        $this->assertNotEmpty($logger->warnings);
    }

    public function test_parse_string_no_logger_no_error(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><text x="0" y="0">hello</text><rect x="0" y="0" width="5" height="5" fill="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(10, 10);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[2][2]->fg);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter SVGParserTest::test_parse_string_logs_unknown`
Expected: FAIL — unknown elements not logged

- [ ] **Step 3: Add logging for unknown elements**

In `parseElement`, update the `default` case in the match expression:

```php
            default => (function() use ($localName, $logger) {
                $logger?->warning("Unsupported SVG element: <{$localName}>");
                return new Group();
            })(),
```

- [ ] **Step 4: Run tests**

Run: `composer test -- --filter SVGParserTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add library/draw/SVGParser.php tests/Canvas/SVGParserTest.php
git commit -m "Add logging for unsupported SVG elements"
```

---

### Task 9: Integration Test — Full SVG Rendering

**Files:**
- Modify: `tests/Canvas/SVGParserTest.php`

- [ ] **Step 1: Add integration tests**

Append to `SVGParserTest`:

```php
    public function test_integration_nested_groups_with_styles(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><g fill="red"><g transform="translate(5,5)"><rect x="0" y="0" width="5" height="5"/></g></g></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNull($canvas->data[2][2]->fg);
        $this->assertNotNull($canvas->data[7][7]->fg);
    }

    public function test_integration_viewbox_with_shapes(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><circle cx="100" cy="100" r="50" fill="blue"/><rect x="0" y="0" width="200" height="200" fill="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(40, 40);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[20][20]->fg);
    }

    public function test_integration_fill_and_stroke(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect x="2" y="2" width="16" height="6" fill="red" stroke="white" stroke-width="2"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(20, 10);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
        $this->assertNotNull($canvas->data[2][5]->fg);
    }

    public function test_integration_readFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'svgtest_');
        file_put_contents($tmpFile, '<svg xmlns="http://www.w3.org/2000/svg"><rect x="0" y="0" width="10" height="10" fill="red"/></svg>');
        try {
            $doc = SVGParser::readFile($tmpFile);
            $canvas = Canvas::createBlank(10, 10);
            $doc->render($canvas);
            $this->assertNotNull($canvas->data[5][5]->fg);
        } finally {
            unlink($tmpFile);
        }
    }

    public function test_integration_malformed_xml_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SVGParser::parseString('<not valid xml');
    }

    public function test_integration_nonexistent_file_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SVGParser::readFile('/nonexistent/path/to/file.svg');
    }

    public function test_integration_complex_path(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><path d="M 10 0 L 20 20 L 0 20 Z" fill="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(25, 25);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[10][10]->fg);
    }

    public function test_integration_evenodd_fill_rule(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><path d="M 0 0 L 20 0 L 20 20 L 0 20 Z M 5 5 L 15 5 L 15 15 L 5 15 Z" fill="red" fill-rule="evenodd"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(25, 25);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[2][2]->fg);
        $this->assertNull($canvas->data[10][10]->fg);
    }
```

- [ ] **Step 2: Run all tests**

Run: `composer test -- --filter SVGParserTest`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add tests/Canvas/SVGParserTest.php
git commit -m "Add SVG parser integration tests"
```

---

### Task 10: Update Roadmap

**Files:**
- Modify: `docs/superpowers/specs/2026-06-02-draw-library-svg-roadmap.md`

- [ ] **Step 1: Mark milestone 9 as done**

Find the line:
```
9. **SVG parser** — XML parser mapping SVG elements to scene tree
```
Replace with:
```
9. ~~**SVG parser** — XML parser mapping SVG elements to scene tree~~ **DONE**
```

- [ ] **Step 2: Commit**

```bash
git add docs/superpowers/specs/2026-06-02-draw-library-svg-roadmap.md
git commit -m "Update roadmap: SVG parser milestone complete"
```

---

### Task 11: Final Verification

- [ ] **Step 1: Run full test suite**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 2: Run static analysis**

Run: `composer phpstan`
Expected: No errors

- [ ] **Step 3: Run formatter**

Run: `vendor/bin/php-cs-fixer fix`
Expected: No changes or auto-fixed
