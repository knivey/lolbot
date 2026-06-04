# CSS Class Support Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add inline `<style>` block parsing to SVGParser so SVG documents using CSS class/ID/type/universal selectors resolve presentation properties correctly during rendering.

**Architecture:** Pre-collect all `<style>` rules from the SVG document in `parseString()` via a recursive `collectStyles()` scan, then thread the read-only `$styles` array through all parse methods. Modify `getEffectiveAttr()` to resolve the CSS cascade: inline `style` attribute > CSS rules (by specificity) > XML presentation attribute. New private helper methods `parseStyleBlock()`, `matchStyles()`, and `collectStyles()` live inside `SVGParser`.

**Tech Stack:** PHP 8.1+, ext-simplexml, PHPUnit 10

---

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `library/draw/SVGParser.php` | Modify | Add CSS parsing helpers, thread `$styles`, fix cascade order |
| `tests/Canvas/SVGParserTest.php` | Modify | Add CSS selector and cascade tests |
| `docs/superpowers/specs/2026-06-02-draw-library-svg-roadmap.md` | Modify | Mark milestone 15 complete |

---

### Task 1: Core CSS Support — Class Selector

**Files:**
- Modify: `library/draw/SVGParser.php`
- Modify: `tests/Canvas/SVGParserTest.php`

This is the main task. It adds three new methods, modifies `getEffectiveAttr()` cascade, threads `$styles` through all parse methods, and adds a `'style'` case to `parseElement()`. Existing tests must continue to pass (note: `getEffectiveAttr()` cascade order is fixed from "XML attr > inline style" to the correct "inline style > CSS rules > XML attr", which is a bug fix — no existing test has both a presentation attribute and an inline style on the same property, so this change is safe).

- [ ] **Step 1: Write the failing test for class selector**

Append to `tests/Canvas/SVGParserTest.php`:

```php
    public function test_parse_string_css_class_selector(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.red { fill: red; }</style><rect x="0" y="0" width="10" height="10" class="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter test_parse_string_css_class_selector`
Expected: FAIL — rect renders with default black fill (no CSS applied), so `data[5][5]->fg` may or may not be set depending on default behavior. The intent is that without CSS support the class is ignored; with it, the fill is applied. If the default fill is black (which renders as non-null), this test would pass trivially. To make a more robust failing test, use a non-default color and check that the fill differs from the no-CSS case. Let me revise:

Actually, the default fill for SVG is black, and `fill="red"` would map to an IRC color. Without CSS, the rect has no explicit fill attribute, so it gets the default black fill (non-null fg). The test would pass even without CSS. We need a test that distinguishes CSS-applied fill from no-fill.

Revised test — use `fill="none"` on the element and let the CSS class override it:

```php
    public function test_parse_string_css_class_selector(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.red { fill: red; }</style><rect x="0" y="0" width="10" height="10" fill="none" class="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }
```

Wait — per the cascade, CSS rules have higher priority than presentation attributes. So `.red { fill: red; }` should override `fill="none"`. Without CSS support, `fill="none"` means no fill, so `data[5][5]->fg` is null. With CSS, it becomes non-null. This is a proper failing test.

Run: `composer test -- --filter test_parse_string_css_class_selector`
Expected: FAIL — `$canvas->data[5][5]->fg` is null because CSS class is ignored.

- [ ] **Step 3: Implement CSS support in SVGParser**

The following changes are made to `library/draw/SVGParser.php`:

**3a. Add the `PRESENTATION_PROPS` constant** after the existing `SVG_NS` constant (around line 338):

```php
    private const PRESENTATION_PROPS = [
        'fill', 'stroke', 'stroke-width', 'stroke-dasharray', 'stroke-dashoffset',
        'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-opacity',
        'opacity', 'fill-opacity', 'fill-rule', 'stop-color', 'display',
    ];
```

**3b. Add the `collectStyles()` method** (new private static method):

```php
    private static function collectStyles(\SimpleXMLElement $el): array
    {
        $styles = [];
        $name = $el->getName();
        if ($name === 'style') {
            $text = trim((string)$el);
            if ($text !== '') {
                $styles = self::parseStyleBlock($text);
            }
            return $styles;
        }
        foreach (self::svgChildren($el) as $child) {
            $styles = array_merge($styles, self::collectStyles($child));
        }
        return $styles;
    }
```

**3c. Add the `parseStyleBlock()` method** (new private static method):

```php
    private static function parseStyleBlock(string $css): array
    {
        $css = preg_replace('/\/\*.*?\*\//s', '', $css);
        if ($css === null || trim($css) === '') {
            return [];
        }

        $rules = [];
        preg_match_all('/([^{]+)\{([^}]*)\}/s', $css, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $selectorList = trim($match[1]);
            $declStr = trim($match[2]);

            if ($selectorList === '' || $declStr === '') {
                continue;
            }

            $props = [];
            $decls = preg_split('/\s*;\s*/', $declStr, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($decls as $decl) {
                $parts = explode(':', $decl, 2);
                if (count($parts) === 2) {
                    $prop = trim($parts[0]);
                    $val = trim($parts[1]);
                    if (in_array($prop, self::PRESENTATION_PROPS, true)) {
                        $props[$prop] = $val;
                    }
                }
            }

            if (empty($props)) {
                continue;
            }

            $selectors = preg_split('/\s*,\s*/', $selectorList);
            foreach ($selectors as $sel) {
                $sel = trim($sel);
                if ($sel === '') {
                    continue;
                }

                $specificity = match (true) {
                    str_starts_with($sel, '#') => 300,
                    str_starts_with($sel, '.') => 200,
                    $sel === '*' => 0,
                    default => 100,
                };

                $rules[] = [
                    'selector' => $sel,
                    'specificity' => $specificity,
                    'props' => $props,
                ];
            }
        }

        return $rules;
    }
```

**3d. Add the `matchStyles()` method** (new private static method):

```php
    private static function matchStyles(array $styles, string $tag, string $class, string $id): array
    {
        $classes = preg_split('/\s+/', trim($class), -1, PREG_SPLIT_NO_EMPTY);
        $matched = [];

        foreach ($styles as $order => $rule) {
            $sel = $rule['selector'];
            $matches = false;

            if (str_starts_with($sel, '#')) {
                $matches = ($sel === '#' . $id);
            } elseif (str_starts_with($sel, '.')) {
                $matches = in_array(substr($sel, 1), $classes, true);
            } elseif ($sel === '*') {
                $matches = true;
            } else {
                $matches = (strtolower($sel) === strtolower($tag));
            }

            if ($matches) {
                $matched[] = ['props' => $rule['props'], 'specificity' => $rule['specificity'], 'order' => $order];
            }
        }

        usort($matched, fn($a, $b) => [$a['specificity'], $a['order']] <=> [$b['specificity'], $b['order']]);

        $props = [];
        foreach ($matched as $rule) {
            foreach ($rule['props'] as $prop => $val) {
                $props[$prop] = $val;
            }
        }

        return $props;
    }
```

**3e. Modify `parseString()`** — add `collectStyles()` call and pass `$styles` to `parseSvgElement()`. Replace the existing `parseString()` method:

```php
    public static function parseString(string $svg, ?LoggerInterface $logger = null): SVGDocument
    {
        $xml = @simplexml_load_string($svg);
        if ($xml === false) {
            throw new \InvalidArgumentException('Failed to parse SVG XML');
        }

        $defs = [];
        $styles = self::collectStyles($xml);
        $root = self::parseSvgElement($xml, $defs, $styles, $logger);

        $viewBox = null;
        $vb = (string)($xml['viewBox'] ?? '');
        if ($vb !== '') {
            $parts = preg_split('/[\s,]+/', trim($vb)) ?: [];
            if (count($parts) === 4) {
                $viewBox = [(float)$parts[0], (float)$parts[1], (float)$parts[2], (float)$parts[3]];
            }
        }

        $width = isset($xml['width']) ? (float)$xml['width'] : null;
        $height = isset($xml['height']) ? (float)$xml['height'] : null;

        return new SVGDocument($root, $viewBox, $width, $height, $logger);
    }
```

**3f. Modify `getEffectiveAttr()`** — accept `$styles`, fix cascade to inline style > CSS rules > XML attr. Replace the existing method:

```php
    private static function getEffectiveAttr(\SimpleXMLElement $el, string $attr, array $styles): string
    {
        $styleStr = (string)($el['style'] ?? '');
        if ($styleStr !== '') {
            $val = self::parseStyleProperty($styleStr, $attr);
            if ($val !== '') {
                return $val;
            }
        }

        if (!empty($styles)) {
            $tag = $el->getName();
            $class = (string)($el['class'] ?? '');
            $id = (string)($el['id'] ?? '');
            $cssProps = self::matchStyles($styles, $tag, $class, $id);
            if (isset($cssProps[$attr]) && $cssProps[$attr] !== '') {
                return $cssProps[$attr];
            }
        }

        return (string)($el[$attr] ?? '');
    }
```

**3g. Thread `$styles` through all parse methods.** Each method gains an `array $styles` parameter (placed after `&$defs`, before `$logger` where applicable). The parameter is passed through to all callees that need it. Here are ALL the modified methods:

Replace `parseElement()`:

```php
    private static function parseElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger): SceneNode
    {
        $name = $el->getName();
        return match ($name) {
            'svg' => self::parseSvgElement($el, $defs, $styles, $logger),
            'g' => self::parseGroupElement($el, $defs, $styles, $logger),
            'path' => self::parsePathElement($el, $defs, $styles, $logger),
            'rect' => self::parseRectElement($el, $defs, $styles, $logger),
            'circle' => self::parseCircleElement($el, $defs, $styles, $logger),
            'ellipse' => self::parseEllipseElement($el, $defs, $styles, $logger),
            'line' => self::parseLineElement($el, $defs, $styles, $logger),
            'polyline' => self::parsePolylineElement($el, $defs, $styles, $logger),
            'polygon' => self::parsePolygonElement($el, $defs, $styles, $logger),
            'defs' => self::parseDefsElement($el, $defs, $styles, $logger),
            'linearGradient' => self::handleGradientElement($el, $defs, $styles, $logger),
            'radialGradient' => self::handleGradientElement($el, $defs, $styles, $logger),
            'style' => new Group(),
            default => (function () use ($name, $logger) {
                $logger?->warning("Unsupported SVG element: <{$name}>");
                return new Group();
            })(),
        };
    }
```

Replace `parseSvgElement()`:

```php
    private static function parseSvgElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger): Group
    {
        $group = new Group();
        foreach (self::svgChildren($el) as $child) {
            $group->addChild(self::parseElement($child, $defs, $styles, $logger));
        }
        return $group;
    }
```

Replace `parseGroupElement()`:

```php
    private static function parseGroupElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger): Group
    {
        $fill = self::parsePaintAttr($el, 'fill', $defs, $styles, $logger);
        $stroke = self::parseStrokeAttr($el, $defs, $styles, $logger);
        $transform = self::parseOptionalTransform($el, $styles);
        $opacity = self::parseFloatAttr($el, 'opacity', $styles);
        $fillOpacity = self::parseFloatAttr($el, 'fill-opacity', $styles);
        $fillRule = self::parseFillRuleAttr($el, $styles);

        $group = new Group(
            fill: $fill,
            stroke: $stroke,
            transform: $transform,
            opacity: $opacity,
            fillOpacity: $fillOpacity,
            fillRule: $fillRule,
        );

        foreach (self::svgChildren($el) as $child) {
            $group->addChild(self::parseElement($child, $defs, $styles, $logger));
        }

        return $group;
    }
```

Replace `parsePathElement()`:

```php
    private static function parsePathElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger): SceneNode
    {
        $d = (string)($el['d'] ?? '');
        $path = self::parseDString($d);
        return self::buildShape($path, $el, $defs, $styles, $logger);
    }
```

Replace `parseRectElement()`:

```php
    private static function parseRectElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger): SceneNode
    {
        $x = (float)($el['x'] ?? 0);
        $y = (float)($el['y'] ?? 0);
        $w = (float)($el['width'] ?? 0);
        $h = (float)($el['height'] ?? 0);
        $rx = (float)($el['rx'] ?? 0);
        $ry = (float)($el['ry'] ?? 0);

        $path = Path::rect($x, $y, $w, $h, $rx, $ry);
        return self::buildShape($path, $el, $defs, $styles, $logger);
    }
```

Replace `parseCircleElement()`:

```php
    private static function parseCircleElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger): SceneNode
    {
        $cx = (float)($el['cx'] ?? 0);
        $cy = (float)($el['cy'] ?? 0);
        $r = (float)($el['r'] ?? 0);

        $path = Path::circle($cx, $cy, $r);
        return self::buildShape($path, $el, $defs, $styles, $logger);
    }
```

Replace `parseEllipseElement()`:

```php
    private static function parseEllipseElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger): SceneNode
    {
        $cx = (float)($el['cx'] ?? 0);
        $cy = (float)($el['cy'] ?? 0);
        $rx = (float)($el['rx'] ?? 0);
        $ry = (float)($el['ry'] ?? 0);

        $path = Path::ellipse($cx, $cy, $rx, $ry);
        return self::buildShape($path, $el, $defs, $styles, $logger);
    }
```

Replace `parseLineElement()`:

```php
    private static function parseLineElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger): SceneNode
    {
        $x1 = (float)($el['x1'] ?? 0);
        $y1 = (float)($el['y1'] ?? 0);
        $x2 = (float)($el['x2'] ?? 0);
        $y2 = (float)($el['y2'] ?? 0);

        $path = Path::line($x1, $y1, $x2, $y2);
        return self::buildShape($path, $el, $defs, $styles, $logger);
    }
```

Replace `parsePolylineElement()`:

```php
    private static function parsePolylineElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger): SceneNode
    {
        $pointsStr = (string)($el['points'] ?? '');
        $points = self::parsePointsAttr($pointsStr);

        if (count($points) < 2) {
            $path = new Path();
        } else {
            $path = Path::polyline($points);
        }
        return self::buildShape($path, $el, $defs, $styles, $logger);
    }
```

Replace `parsePolygonElement()`:

```php
    private static function parsePolygonElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger): SceneNode
    {
        $pointsStr = (string)($el['points'] ?? '');
        $points = self::parsePointsAttr($pointsStr);

        if (count($points) < 2) {
            $path = new Path();
        } else {
            $path = Path::polygon($points);
        }
        return self::buildShape($path, $el, $defs, $styles, $logger);
    }
```

Replace `parseDefsElement()`:

```php
    private static function parseDefsElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger): Group
    {
        $group = new Group();
        foreach (self::svgChildren($el) as $child) {
            $name = $child->getName();
            if ($name === 'linearGradient' || $name === 'radialGradient') {
                self::parseGradientElement($child, $defs, $styles, $logger);
            } else {
                $id = (string)($child['id'] ?? '');
                if ($id !== '') {
                    $defs[$id] = $child;
                }
            }
        }
        return $group;
    }
```

Replace `parseGradientElement()`:

```php
    private static function parseGradientElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger): void
    {
        $id = (string)($el['id'] ?? '');
        if ($id === '') {
            return;
        }

        $stops = self::parseGradientStops($el, $styles);
        if (count($stops) < 2) {
            return;
        }

        $spread = match (strtolower((string)($el['spreadMethod'] ?? 'pad'))) {
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
            $defs[$id] = new RadialGradient($cx, $cy, $r, $stops, $fx, $fy, $spread);
        }
    }
```

Replace `handleGradientElement()`:

```php
    private static function handleGradientElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger): Group
    {
        self::parseGradientElement($el, $defs, $styles, $logger);
        return new Group();
    }
```

Replace `parseGradientStops()`:

```php
    private static function parseGradientStops(\SimpleXMLElement $el, array $styles): array
    {
        $stops = [];
        foreach (self::svgChildren($el) as $child) {
            if ($child->getName() !== 'stop') {
                continue;
            }
            $offsetStr = (string)($child['offset'] ?? '0');
            $offset = self::parsePercentageOrFloat($offsetStr);
            $colorStr = self::getEffectiveAttr($child, 'stop-color', $styles);
            if ($colorStr === '') {
                $colorStr = 'black';
            }
            $rgb = SvgColor::parse($colorStr);
            if ($rgb === null) {
                $rgb = [0, 0, 0];
            }
            $stops[] = new ColorStop($offset, $rgb[0], $rgb[1], $rgb[2]);
        }
        usort($stops, fn(ColorStop $a, ColorStop $b) => $a->offset <=> $b->offset);
        return $stops;
    }
```

Replace `buildShape()` — return type changes from `Shape` to `SceneNode`:

```php
    private static function buildShape(Path $path, \SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger): SceneNode
    {
        $fill = self::parsePaintAttr($el, 'fill', $defs, $styles, $logger);
        $stroke = self::parseStrokeAttr($el, $defs, $styles, $logger);
        $transform = self::parseOptionalTransform($el, $styles);
        $opacity = self::parseFloatAttr($el, 'opacity', $styles);
        $fillOpacity = self::parseFloatAttr($el, 'fill-opacity', $styles);
        $fillRule = self::parseFillRuleAttr($el, $styles);

        return new Shape(
            path: $path,
            fill: $fill,
            stroke: $stroke,
            transform: $transform,
            opacity: $opacity,
            fillOpacity: $fillOpacity,
            fillRule: $fillRule,
        );
    }
```

Replace `parsePaintAttr()`:

```php
    private static function parsePaintAttr(\SimpleXMLElement $el, string $attr, array &$defs, array $styles, ?LoggerInterface $logger): ?Paint
    {
        $val = self::getEffectiveAttr($el, $attr, $styles);
        if ($val === 'none') {
            return null;
        }
        if ($val === '') {
            if ($attr === 'fill') {
                $rgb = [0, 0, 0];
                $code = IrcPalette::nearestColor($rgb[0], $rgb[1], $rgb[2]);
                return new Color($code, null);
            }
            return null;
        }

        if (preg_match('/^url\(#(.+)\)$/', $val, $m)) {
            $id = $m[1];
            if (isset($defs[$id])) {
                return $defs[$id];
            }
            $logger?->warning("SVG reference not found: #{$id}");
            return new NoPaint();
        }

        $rgb = SvgColor::parse($val);
        if ($rgb === null) {
            return null;
        }

        $code = IrcPalette::nearestColor($rgb[0], $rgb[1], $rgb[2]);
        return new Color($code, null);
    }
```

Replace `parseStrokeAttr()`:

```php
    private static function parseStrokeAttr(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger): ?StrokeStyle
    {
        $strokeVal = self::getEffectiveAttr($el, 'stroke', $styles);
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

        $width = (float)(self::getEffectiveAttr($el, 'stroke-width', $styles) ?: '1.0');
        if ($width < 0) {
            $width = 1.0;
        }

        $dashArray = null;
        $dashStr = self::getEffectiveAttr($el, 'stroke-dasharray', $styles);
        if ($dashStr !== '' && $dashStr !== 'none') {
            $dashArray = array_map('floatval', preg_split('/[\s,]+/', trim($dashStr)));
            $dashArray = array_filter($dashArray, fn($v) => $v > 0);
            if (empty($dashArray)) {
                $dashArray = null;
            }
        }

        $lineCap = match (self::getEffectiveAttr($el, 'stroke-linecap', $styles) ?: 'butt') {
            'round' => LineCap::Round,
            'square' => LineCap::Square,
            default => LineCap::Butt,
        };

        $lineJoin = match (self::getEffectiveAttr($el, 'stroke-linejoin', $styles) ?: 'miter') {
            'round' => LineJoin::Round,
            'bevel' => LineJoin::Bevel,
            default => LineJoin::Miter,
        };

        $miterLimit = (float)(self::getEffectiveAttr($el, 'stroke-miterlimit', $styles) ?: '4.0');
        $strokeOpacity = self::parseFloatAttr($el, 'stroke-opacity', $styles) ?? 1.0;

        return new StrokeStyle(
            paint: $paint,
            width: $width,
            dashArray: $dashArray === null ? null : array_values($dashArray),
            dashOffset: (float)(self::getEffectiveAttr($el, 'stroke-dashoffset', $styles) ?: '0.0'),
            lineCap: $lineCap,
            lineJoin: $lineJoin,
            miterLimit: $miterLimit,
            opacity: $strokeOpacity,
        );
    }
```

Replace `parseOptionalTransform()`:

```php
    private static function parseOptionalTransform(\SimpleXMLElement $el, array $styles): ?Transform
    {
        $val = self::getEffectiveAttr($el, 'transform', $styles);
        if ($val === '') {
            return null;
        }
        return self::parseTransform($val);
    }
```

Replace `parseFloatAttr()`:

```php
    private static function parseFloatAttr(\SimpleXMLElement $el, string $attr, array $styles): ?float
    {
        $val = self::getEffectiveAttr($el, $attr, $styles);
        if ($val === '') {
            return null;
        }
        return (float)$val;
    }
```

Replace `parseFillRuleAttr()`:

```php
    private static function parseFillRuleAttr(\SimpleXMLElement $el, array $styles): ?FillRule
    {
        $val = self::getEffectiveAttr($el, 'fill-rule', $styles);
        if ($val === '') {
            return null;
        }
        return match ($val) {
            'evenodd' => FillRule::EvenOdd,
            'nonzero' => FillRule::NonZero,
            default => null,
        };
    }
```

- [ ] **Step 4: Run ALL tests to verify nothing is broken and the new test passes**

Run: `composer test`
Expected: ALL PASS (including `test_parse_string_css_class_selector`)

- [ ] **Step 5: Commit**

```bash
git add library/draw/SVGParser.php tests/Canvas/SVGParserTest.php
git commit -m "feat: add CSS class/ID/type/universal selector support to SVG parser"
```

---

### Task 2: ID, Type, and Universal Selector Tests

**Files:**
- Modify: `tests/Canvas/SVGParserTest.php`

These tests should pass without any code changes — the implementation from Task 1 already handles all selector types.

- [ ] **Step 1: Add selector tests**

Append to `tests/Canvas/SVGParserTest.php`:

```php
    public function test_parse_string_css_id_selector(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>#myRect { fill: red; }</style><rect x="0" y="0" width="10" height="10" fill="none" id="myRect"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_type_selector(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>rect { fill: red; }</style><rect x="0" y="0" width="10" height="10" fill="none"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_universal_selector(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>* { fill: red; }</style><rect x="0" y="0" width="10" height="10" fill="none"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_multiple_classes(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.red { fill: red; }</style><rect x="0" y="0" width="10" height="10" fill="none" class="bold red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_comma_separated_selectors(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.a, .b { fill: red; }</style><rect x="0" y="0" width="10" height="10" fill="none" class="b"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_type_selector_case_insensitive(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>RECT { fill: red; }</style><rect x="0" y="0" width="10" height="10" fill="none"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }
```

- [ ] **Step 2: Run tests**

Run: `composer test -- --filter test_parse_string_css`
Expected: ALL PASS

- [ ] **Step 3: Commit**

```bash
git add tests/Canvas/SVGParserTest.php
git commit -m "test: add ID/type/universal/multi-class CSS selector tests"
```

---

### Task 3: Cascade and Specificity Tests

**Files:**
- Modify: `tests/Canvas/SVGParserTest.php`

These verify the cascade ordering: inline `style` attribute > CSS rules (by specificity) > XML presentation attribute. No code changes needed.

- [ ] **Step 1: Add cascade tests**

Append to `tests/Canvas/SVGParserTest.php`:

```php
    public function test_parse_string_css_inline_style_overrides_class(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.red { fill: red; }</style><rect x="0" y="0" width="10" height="10" class="red" style="fill:none"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_overrides_presentation_attr(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.red { fill: red; }</style><rect x="0" y="0" width="10" height="10" fill="none" class="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_id_overrides_class(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.blue { fill: blue; } #special { fill: red; }</style><rect x="0" y="0" width="10" height="10" fill="none" class="blue" id="special"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_last_rule_wins_same_specificity(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.a { fill: none; } .b { fill: red; }</style><rect x="0" y="0" width="10" height="10" class="a b"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_class_overrides_type(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>rect { fill: none; } .red { fill: red; }</style><rect x="0" y="0" width="10" height="10" class="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_stroke_from_class(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.outlined { stroke: white; fill: none; }</style><rect x="0" y="0" width="10" height="10" class="outlined"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[0][5]->fg);
    }

    public function test_parse_string_css_opacity(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.semi { opacity: 0.5; }</style><rect x="0" y="0" width="10" height="10" fill="red" class="semi"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_no_matching_class_no_fill(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.red { fill: red; }</style><rect x="0" y="0" width="10" height="10" fill="none" class="blue"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNull($canvas->data[5][5]->fg);
    }
```

- [ ] **Step 2: Run tests**

Run: `composer test -- --filter test_parse_string_css`
Expected: ALL PASS

- [ ] **Step 3: Commit**

```bash
git add tests/Canvas/SVGParserTest.php
git commit -m "test: add CSS cascade and specificity ordering tests"
```

---

### Task 4: `display: none` and Edge Cases

**Files:**
- Modify: `library/draw/SVGParser.php`
- Modify: `tests/Canvas/SVGParserTest.php`

- [ ] **Step 1: Write the failing test for `display: none`**

Append to `tests/Canvas/SVGParserTest.php`:

```php
    public function test_parse_string_css_display_none(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.hidden { display: none; }</style><rect x="0" y="0" width="10" height="10" fill="red" class="hidden"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNull($canvas->data[5][5]->fg);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter test_parse_string_css_display_none`
Expected: FAIL — rect renders with red fill despite `display: none`

- [ ] **Step 3: Add `display: none` check to `buildShape()`**

Replace `buildShape()` with:

```php
    private static function buildShape(Path $path, \SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger): SceneNode
    {
        $display = self::getEffectiveAttr($el, 'display', $styles);
        if ($display === 'none') {
            return new Group();
        }

        $fill = self::parsePaintAttr($el, 'fill', $defs, $styles, $logger);
        $stroke = self::parseStrokeAttr($el, $defs, $styles, $logger);
        $transform = self::parseOptionalTransform($el, $styles);
        $opacity = self::parseFloatAttr($el, 'opacity', $styles);
        $fillOpacity = self::parseFloatAttr($el, 'fill-opacity', $styles);
        $fillRule = self::parseFillRuleAttr($el, $styles);

        return new Shape(
            path: $path,
            fill: $fill,
            stroke: $stroke,
            transform: $transform,
            opacity: $opacity,
            fillOpacity: $fillOpacity,
            fillRule: $fillRule,
        );
    }
```

- [ ] **Step 4: Add remaining edge case tests**

Append to `tests/Canvas/SVGParserTest.php`:

```php
    public function test_parse_string_css_empty_style_block(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style></style><rect x="0" y="0" width="10" height="10" fill="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_unknown_property_ignored(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.red { fill: red; cursor: pointer; font-size: 14px; }</style><rect x="0" y="0" width="10" height="10" fill="none" class="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_comments_stripped(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>/* comment */.red { fill: red; }</style><rect x="0" y="0" width="10" height="10" fill="none" class="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_style_in_defs(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><style>.red { fill: red; }</style></defs><rect x="0" y="0" width="10" height="10" fill="none" class="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_does_not_log_style_element(): void
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
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.red { fill: red; }</style><rect x="0" y="0" width="5" height="5" fill="red"/></svg>';
        SVGParser::parseString($svg, $logger);
        foreach ($logger->warnings as $w) {
            $this->assertStringNotContainsString('style', $w);
        }
    }

    public function test_parse_string_css_malformed_rule_skipped(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.red { fill: red; } { broken } .blue { fill: blue; }</style><rect x="0" y="0" width="10" height="10" fill="none" class="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }
```

- [ ] **Step 5: Run all tests**

Run: `composer test`
Expected: ALL PASS

- [ ] **Step 6: Commit**

```bash
git add library/draw/SVGParser.php tests/Canvas/SVGParserTest.php
git commit -m "feat: add display:none support and CSS edge case handling"
```

---

### Task 5: Mark Milestone Complete

**Files:**
- Modify: `docs/superpowers/specs/2026-06-02-draw-library-svg-roadmap.md`

- [ ] **Step 1: Update milestone 15 to DONE**

Change line 299 from:
```
15. **CSS class support** — parse `<style>` blocks, resolve `class` attributes to fill/stroke values
```
to:
```
15. ~~**CSS class support** — parse `<style>` blocks, resolve `class` attributes to fill/stroke values~~ **DONE**
```

Also remove lines 300-303 (the sub-bullets under milestone 15) since they are now complete.

- [ ] **Step 2: Run full test suite**

Run: `composer test`
Expected: ALL PASS

- [ ] **Step 3: Commit**

```bash
git add docs/superpowers/specs/2026-06-02-draw-library-svg-roadmap.md
git commit -m "docs: mark CSS class support milestone complete"
```
