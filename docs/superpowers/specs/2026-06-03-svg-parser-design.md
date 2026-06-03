# SVG Parser Design

Add an SVG parser to `library/draw/` that maps SVG XML documents to the existing scene tree (Group/Shape/Path/Paint/Transform). This is **Milestone 9** from the SVG roadmap.

## Goal

Given an SVG string or file, produce a scene tree that can be rendered onto an IRC character-cell Canvas. The parser is a thin mapping layer — it creates draw library objects, it does not render.

## Public API

### SVGParser

```php
class SVGParser
{
    public static function parseString(string $svg, ?LoggerInterface $logger = null): SVGDocument;
    public static function readFile(string $path, ?LoggerInterface $logger = null): SVGDocument;
}
```

- `parseString` takes raw SVG XML and an optional PSR-3 logger. Returns an `SVGDocument`.
- `readFile` reads the file and delegates to `parseString`.
- The logger is `Psr\Log\LoggerInterface`. When `null`, all log calls are skipped (guarded by null check, no null-object wrapper).

### SVGDocument

```php
class SVGDocument
{
    public function getRoot(): SceneNode;
    public function getViewBox(): ?array;
    public function getWidth(): ?float;
    public function getHeight(): ?float;
    public function getViewBoxTransform(float $targetWidth, float $targetHeight, string $aspectRatio = 'xMidYMid meet'): ?Transform;
    public function render(Canvas $canvas, ?RenderContext $ctx = null): void;
}
```

- `getRoot()` returns the parsed scene tree (always a `Group` as root).
- `getViewBox()` returns `[x, y, w, h]` from the `<svg>` viewBox attribute, or `null`.
- `getWidth()` / `getHeight()` return the `<svg>` `width`/`height` attributes, or `null`.
- `getViewBoxTransform(targetW, targetH, aspectRatio)` computes the affine transform mapping viewBox coordinates to target canvas dimensions. Returns `null` if no viewBox is set.
- `render(canvas, ctx)` is a convenience that applies the viewBox transform and renders the scene tree.

Parsing is independent of rendering size. The same `SVGDocument` can be rendered at different canvas sizes.

## Architecture

Single `SVGParser` class with private element handler methods. A small `SvgColor` class handles color string parsing. No element handler registry, no visitor pattern — the SVG-to-scene-tree mapping is direct and procedural.

### New Files

| File | Class | Purpose |
|---|---|---|
| `library/draw/SVGParser.php` | `SVGParser` | XML walking, element dispatch, attribute parsing |
| `library/draw/SVGDocument.php` | `SVGDocument` | Parsed result holder, viewBox transform, render convenience |
| `library/draw/SvgColor.php` | `SvgColor` | CSS/SVG color string to RGB parsing |

No changes to existing files.

## Parsing Pipeline

```
SVG XML string
  → simplexml_load_string()
  → Walk tree recursively
  → Collect <defs> into id → object map
  → Build scene tree (Group/Shape nodes)
  → Return SVGDocument(root, viewBox, width, height, logger)
```

### Internal Parse Context

Passed through recursion as method parameters (not a separate class):

- `$defs`: `array<string, Paint|SceneNode>` — id-to-object map for `<defs>` content and `id` attributes
- `$logger`: `?LoggerInterface` — passed through for warnings

### Element Mapping

| SVG Element | Maps To | Handler Method |
|---|---|---|
| `<svg>` | Root `Group` | `parseSvgElement()` |
| `<g>` | `Group` with style overrides | `parseGroupElement()` |
| `<path>` | `Shape(Path)` | `parsePathElement()` |
| `<rect>` | `Shape(Path::rect())` | `parseRectElement()` |
| `<circle>` | `Shape(Path::circle())` | `parseCircleElement()` |
| `<ellipse>` | `Shape(Path::ellipse())` | `parseEllipseElement()` |
| `<line>` | `Shape(Path::line())` | `parseLineElement()` |
| `<polyline>` | `Shape(Path::polyline())` | `parsePolylineElement()` |
| `<polygon>` | `Shape(Path::polygon())` | `parsePolygonElement()` |
| `<defs>` | Children added to defs map, not scene tree | `parseDefsElement()` |
| `<linearGradient>` | `LinearGradient` in defs map | `parseLinearGradientElement()` |
| `<radialGradient>` | `RadialGradient` in defs map | `parseRadialGradientElement()` |

Unknown elements are silently skipped (warning logged if logger present).

## SVG Path `d` String Parsing

Private method `parseDString(string $d): Path` on `SVGParser`.

Maps SVG path commands directly to `Path` builder calls:

| SVG Command | Path Method |
|---|---|
| `M/m` | `moveTo()` |
| `L/l` | `lineTo()` |
| `H/h` | `horizontalLineTo()` |
| `V/v` | `verticalLineTo()` |
| `C/c` | `cubicTo()` |
| `S/s` | `smoothCubicTo()` |
| `Q/q` | `quadTo()` |
| `T/t` | `smoothQuadTo()` |
| `A/a` | `arcTo()` |
| `Z/z` | `closePath()` |

Handles:
- **Implicit repeats**: extra coordinate pairs repeat the last command (`L 10 20 30 40` → two lineTos)
- **Relative coordinates**: lowercase commands offset from current point
- **Comma/whitespace flexibility**: `10,20`, `10 20`, `10  20` all valid; negative numbers act as delimiters (`10-20`)
- **Arc flag compression**: large-arc and sweep flags are single digits without separator (`A25,25,0,01,50,50`)

The `Path` class already tracks cursor position and smooth-curve reflection state, so parsing is a direct token-to-call mapping.

## Transform String Parsing

SVG `transform` attribute can contain chained transforms:

`transform="translate(10,20) rotate(45) scale(2)"`

Parsed into a single composite `Transform` by multiplying left-to-right:

| SVG Function | Transform Factory |
|---|---|
| `translate(tx, ty?)` | `Transform::translate(tx, ty ?? 0)` |
| `scale(sx, sy?)` | `Transform::scale(sx, sy ?? sx)` |
| `rotate(angle, cx?, cy?)` | `Transform::rotate(deg2rad(angle), cx ?? 0, cy ?? 0)` |
| `skewX(angle)` | `Transform::skewX(deg2rad(angle))` |
| `skewY(angle)` | `Transform::skewY(deg2rad(angle))` |
| `matrix(a,b,c,d,e,f)` | `Transform::matrix(a,b,c,d,e,f)` |

SVG uses degrees; `Transform` factories expect radians — convert with `deg2rad()`.

## ViewBox & preserveAspectRatio

When `<svg>` has a `viewBox` attribute (e.g., `viewBox="0 0 100 100"`):

1. Parse viewBox → `[$vbX, $vbY, $vbW, $vbH]`
2. Compute scale: `scaleX = targetW / vbW`, `scaleY = targetH / vbH`
3. Apply aspect ratio logic:
   - `meet` (default): use smaller scale, center in the larger axis
   - `slice`: use larger scale, clip overflow
   - `none`: stretch independently (no aspect preservation)
   - Alignment: `xMin`, `xMid`, `xMax` / `yMin`, `yMid`, `yMax`
4. Build transform: `translate(tx, ty) × scale(sx, sy) × translate(-vbX, -vbY)`

`getViewBoxTransform()` returns `null` if no viewBox is set.

## Gradient Definition Parsing

Gradients live inside `<defs>`:

```xml
<defs>
  <linearGradient id="grad1" x1="0%" y1="0%" x2="100%" y2="0%">
    <stop offset="0%" stop-color="red"/>
    <stop offset="100%" stop-color="blue"/>
  </linearGradient>
</defs>
```

**Attributes:**

- `id` → stored in defs map for `url(#id)` resolution
- `gradientUnits` → `userSpaceOnUse` (coordinates in SVG user space) or `objectBoundingBox` (default, coordinates 0-1 relative to bounding box)
- `x1/y1/x2/y2` (linear) or `cx/cy/r/fx/fy` (radial) → support `%` suffix (divide by 100 for objectBoundingBox)
- `spreadMethod` → `pad` (default), `reflect`, `repeat` → `SpreadMethod` enum
- `<stop>` children → `ColorStop[]` with `offset` (percentage or decimal), `stop-color`, `stop-opacity`

### objectBoundingBox Gradients

For `gradientUnits="objectBoundingBox"`, gradient coordinates are fractions (0-1) of the filled element's bounding box. At parse time, compute the bounding box from the Path's flattened vertices and multiply gradient coordinates by the bounding box dimensions to get `userSpaceOnUse`-equivalent coordinates.

### url(#id) Resolution

When parsing `fill="url(#grad1)"`, look up `grad1` in the defs map. Definitions must appear before references (top-down parsing order covers most SVGs). If a reference is not found, treat as `none` and log a warning.

## Style Attribute Parsing

Shared across all shape and group elements:

- `fill` → Paint: Color via SvgColor, gradient via `url(#id)`, or `none`
- `stroke` → Paint: same resolution as fill
- `transform` → Transform (parse SVG transform string)
- `opacity` → float (default 1.0)
- `fill-opacity` → float (default 1.0)
- `stroke-opacity` → float (default 1.0), applied to the StrokeStyle's opacity
- `fill-rule` → FillRule enum (`nonzero` default, `evenodd`)
- `stroke-width` → float (default 1.0)
- `stroke-dasharray` → float[] or `none`
- `stroke-dashoffset` → float (default 0.0)
- `stroke-linecap` → LineCap enum (`butt` default, `round`, `square`)
- `stroke-linejoin` → LineJoin enum (`miter` default, `round`, `bevel`)
- `stroke-miterlimit` → float (default 4.0)

Missing attributes use SVG spec defaults. The `stroke` attribute defaults to `none`, so strokes are opt-in per SVG spec.

## SvgColor Class

```php
class SvgColor
{
    public static function parse(string $color): ?array;  // returns [r, g, b] or null
}
```

Handles:
- `none` → `null`
- `transparent` → `null`
- `currentColor` → `null` (no inherited color context in this pass)
- `#RGB` → expand to `#RRGGBB`, parse hex
- `#RRGGBB` → parse hex
- `#RRGGBBAA` → parse hex, ignore alpha channel
- `rgb(r, g, b)` → parse, clamp 0-255
- `rgba(r, g, b, a)` → parse, ignore alpha
- All 147 CSS named colors → lookup in static map (aliceblue through yellowgreen)

Output: `[0-255, 0-255, 0-255]` or `null`.

## Logging

Logger is `Psr\Log\LoggerInterface` (already available via `psr/log` 3.x).

| Condition | Level | Message Example |
|---|---|---|
| Unknown element skipped | Warning | `Unsupported SVG element: <text>` |
| Missing `url(#id)` reference | Warning | `Gradient reference not found: #missingGrad` |
| Invalid attribute value | Warning | `Invalid color value: 'xyz', treating as none` |
| Malformed path data | Warning | `Invalid path d command near position 42` |
| ViewBox mapping | Debug | `ViewBox 0 0 100 100 → 80x24 canvas, scale=0.24` |

When `$logger` is `null`, all log calls are guarded by null checks — no null-object wrapper overhead.

## Error Handling

- **Malformed XML**: `simplexml_load_string` returns `false`. Throw `InvalidArgumentException` with clear message.
- **Missing attributes**: Use SVG spec defaults (fill=black, stroke=none, opacity=1.0, etc.)
- **Invalid path data**: Best-effort parsing. Skip commands with wrong argument counts rather than crashing. Log warnings.
- **Invalid color values**: Treat as `none`. Log warning.
- **Unknown elements**: Skip silently. Log warning.
- **Circular references in defs**: Not handled (rare in practice; would cause infinite recursion).

## Out of Scope

These are later roadmap milestones, not part of the SVG parser:
- `<text>` elements (Milestone 12)
- `<clipPath>` / `<mask>` (Milestone 10)
- `<filter>` (Milestone 11)
- `<use>` / `<symbol>` / `<image>` (Milestone 13)
- CSS `<style>` blocks and inline `style` attributes
- SVG animations
- SVG namespaced extensions (foreignObject, RDF)
- `currentColor` resolution (needs inherited color context)
