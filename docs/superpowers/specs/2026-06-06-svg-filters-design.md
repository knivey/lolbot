# SVG Filters — Milestone 11

Add `<filter>` element support with five filter primitives to the draw library's
SVG rendering pipeline. Filters operate on offscreen buffers in RGB space,
de-quantizing from the IRC palette, applying pixel operations, and re-quantizing
back.

## Scope

Five filter primitives:

| Primitive | SVG Element | Description |
|-----------|-------------|-------------|
| Gaussian blur | `feGaussianBlur` | 3-pass box blur approximating Gaussian |
| Offset | `feOffset` | Translate the source graphic by dx/dy |
| Color matrix | `feColorMatrix` | Per-pixel color matrix (matrix, saturate, hueRotate, luminanceToAlpha) |
| Merge | `feMerge` | Composite multiple named inputs via source-over |
| Drop shadow | `feDropShadow` | Shorthand expanding to blur + offset + flood + composite + merge |

Built-in named sources: `SourceGraphic`, `SourceAlpha`, `BackgroundImage`,
`BackgroundAlpha`. BackgroundImage/BackgroundAlpha produce empty canvases with a
logger warning (we don't track page background).

Full named-result chaining via `in`/`result` attributes on each primitive.

## Architecture

### FilterNode (SceneNode wrapper)

`FilterNode implements SceneNode` wraps a child node, following the ClipNode/MaskNode
pattern.

Properties:
- `$child` — the scene node being filtered
- `$filterRegion` — `?FilterRegion` (x, y, width, height in fractional bbox units)
- `$primitives` — ordered list of `FilterPrimitive` objects
- `$filterUnits` — `FilterUnits` enum (ObjectBoundingBox or UserSpaceOnUse)

`getChildren()` returns `[$this->child]`.

Rendering flow:
1. Compute child bbox via `ClipNode::computeBbox()`
2. Compute absolute filter region from `$filterRegion` + child bbox, clamped to canvas bounds
3. Create a sub-region-sized offscreen canvas for the child
4. Render child onto offscreen canvas
5. Execute the primitive chain via `FilterPipeline`
6. Composite the final result back onto the main canvas at the filter region offset

FilterNode does NOT call `canvas->save()/restore()` — it renders entirely on
temp canvases and composites back, matching the ClipNode/MaskNode pattern.

### FilterPrimitive interface

```php
interface FilterPrimitive {
    public function getInput(): ?string;
    public function getResult(): ?string;
    public function apply(Canvas $input, FilterPipeline $pipeline): Canvas;
}
```

- `getInput()` — named input (null = use previous primitive's output)
- `getResult()` — named output (null = pass-through, next primitive uses this directly)
- `apply()` — receives resolved input canvas and the pipeline (for looking up named results)

### FilterPipeline (named result routing)

Manages the flow of intermediate results between primitives:

- Dict of `name => Canvas` for named results
- Pre-populated with built-in sources:
  - `SourceGraphic` — original child render (full sub-region canvas)
  - `SourceAlpha` — alpha channel only. Derived from SourceGraphic: pixels with
    fg color become white fg at the original `fgAlpha`, pixels with bg color
    become white bg at the original `bgAlpha`, text is preserved. This gives
    a luminance mask of the element's shape.
  - `BackgroundImage` — empty canvas + logger warning
  - `BackgroundAlpha` — empty canvas + logger warning
- After each primitive applies, if `getResult()` is non-null, output is stored in dict
- The last primitive's output is the filter's final output

### FilterRegion

Value object holding `x, y, width, height` as fractional values. Defaults to
SVG spec's `-10%, -10%, 120%, 120%` of the element's bounding box (represented
as `-0.1, -0.1, 1.2, 1.2`). When `filterUnits` is `ObjectBoundingBox`, these are
multiplied by the child's bbox. When `UserSpaceOnUse`, they're absolute pixel values.

The default filter region adds 10% padding on each side so that blur and offset
don't get clipped at the element's edges.

### FilterUnits enum

```
ObjectBoundingBox
UserSpaceOnUse
```

Matches SVG's `filterUnits` attribute. Determines how `filterRegion` and primitive
attributes are interpreted.

## Filter Primitives

### GaussianBlurPrimitive

Parameters: `stdDeviation` (float)

Implementation: 3-pass box blur (horizontal → vertical → horizontal) approximating
a Gaussian. Per-pass box radius: `floor(stdDeviation * sqrt(12/3) / 2 + 0.5)`.
Each pass applies a 1D box kernel of width `(2 * boxRadius + 1)`. Three passes
of box blur closely approximate a true Gaussian. Edge handling: extend edge
pixels (clamp).

Per-pixel:
1. De-quantize IRC color to RGB via `IrcPalette` lookup
2. Accumulate weighted RGB average over kernel
3. Re-quantize to nearest IRC palette color

Operates on both fg and bg channels independently. Pixels with no color (null
fg/bg) are treated as transparent and excluded from the kernel average.

The `in` attribute is respected (defaults to `SourceGraphic`).

### OffsetPrimitive

Parameters: `dx`, `dy` (float, in user space pixels)

Creates a new canvas of the same size, copies each pixel from the input at
`(x - dx, y - dy)` to output `(x, y)`. Pixels outside the input bounds are left
empty (transparent).

### ColorMatrixPrimitive

Parameters: `type` (string), `values` (array of floats)

Operates per-pixel in RGB space. Alpha is derived from the pixel's `fgAlpha`.
The matrix is applied to `[R, G, B, A, 1]` producing `[R', G', B', A']`.

Four types:

- **`matrix`** — full 20-value 4x5 matrix. Values map to rows: `[a1..a5, b1..b5, c1..c5, d1..d5]`.
  Result: `R' = a1*R + a2*G + a3*B + a4*A + a5`, etc.
- **`saturate`** — single value `s` (0–1). Scales saturation:
  ```
  R' = 0.213+0.787*s   0.715-0.715*s   0.072-0.072*s   0  0
  G' = 0.213-0.213*s   0.715+0.285*s   0.072-0.072*s   0  0
  B' = 0.213-0.213*s   0.715-0.715*s   0.072+0.928*s   0  0
  A' = 0                0               0                1  0
  ```
- **`hueRotate`** — angle in degrees. Rotates hue in color space using a rotation
  matrix derived from the angle.
- **`luminanceToAlpha`** — fixed matrix converting RGB luminance to alpha:
  ```
  A' = 0.2126*R + 0.7152*G + 0.0722*B
  R' = G' = B' = 0
  ```

After matrix application, RGB values are clamped to [0, 255], alpha is clamped to
[0, 1], and the result is re-quantized to the nearest IRC palette color.

Both fg and bg channels are processed independently.

### MergePrimitive

Parameters: list of input names (from `feMergeNode` children)

For each input name in order, composites the referenced canvas onto the output
canvas using `Compositor::blendRegion()` with opacity 1.0. The first input
initializes the output canvas; subsequent inputs are blended on top.

### DropShadowPrimitive (shorthand)

Parameters: `dx`, `dy`, `stdDeviation`, `floodColor` (RGB), `floodOpacity` (float)

Implemented as a shorthand that expands internally during `apply()`:

1. Extract alpha from input (pixels with fg/bg become white at their original
   alpha, everything else transparent) → blur with `stdDeviation`
2. Offset the blurred alpha by `dx`, `dy`
3. Flood-fill a canvas with `floodColor` at `floodOpacity`
4. Alpha-mask the flood fill with the offset blurred alpha (per-pixel: multiply
   flood pixel's alpha by the shadow alpha pixel's brightness). This is done
   inline — no `feComposite` primitive class needed.
5. Merge the shadow with the original `SourceGraphic`

This matches the SVG spec's expansion of `feDropShadow`.

## Pixel Operations (RGB Space)

All filter operations work in RGB space:

1. Read pixel's IRC color code → look up RGB from `IrcPalette` → de-quantize
2. Perform operation (blur interpolation, matrix multiply, etc.)
3. Re-quantize result back to nearest IRC palette color via `IrcPalette::nearestColor()`

The de-quantization is lossy (we round-trip through the 99-color palette), but
this is inherent to the IRC medium. The IRC palette RGB values in `IrcPalette`
serve as the RGB truth for each color code.

## SVG Parser Integration

Changes to `SVGParser`:

1. `collectAllDefs()` — add `'filter'` to the set of harvested elements. Store as
   raw `SimpleXMLElement` in `$defs` keyed by `id`.

2. New method `parseFilterElement(SimpleXMLElement $el, array $defs, ...): FilterNode` —
   parses `<filter>` attributes (x, y, width, height, filterUnits) and iterates
   child elements, dispatching to primitive parsers:
   - `feGaussianBlur` → `GaussianBlurPrimitive`
   - `feOffset` → `OffsetPrimitive`
   - `feColorMatrix` → `ColorMatrixPrimitive`
   - `feMerge` → `MergePrimitive`
   - `feDropShadow` → `DropShadowPrimitive`
   - Unknown elements logged as warning and skipped.

3. Attribute resolution — after building a Shape or Group, check for
   `filter="url(#id)"` attribute. If found, look up the parsed filter in `$defs`
   and wrap the node in a `FilterNode`. This happens in `wrapWithClipMask()`
   (or a renamed `wrapWithEffects()`), after clip/mask wrapping so the order is:
   shape → clip → mask → filter.

4. `parseElement()` match statement — add `'filter'` case returning empty Group
   when encountered inline (same pattern as `defs`, `clipPath`, `mask`).

## New Files

```
library/draw/
  FilterNode.php          — SceneNode wrapper (filter region + primitive chain)
  FilterRegion.php        — Value object: x, y, width, height (fractional/absolute)
  FilterPrimitive.php     — Interface
  FilterPipeline.php      — Named result routing + built-in sources
  FilterUnits.php         — Enum: ObjectBoundingBox, UserSpaceOnUse
  GaussianBlurPrimitive.php — feGaussianBlur
  OffsetPrimitive.php     — feOffset
  ColorMatrixPrimitive.php — feColorMatrix
  MergePrimitive.php      — feMerge
  DropShadowPrimitive.php — feDropShadow (shorthand)
```

All in the `draw\` namespace under `library/draw/`.

## Compositor Changes

`Compositor.php` needs one new method:

- `floodFill(Canvas $canvas, int $r, int $g, int $b, float $opacity, int $x, int $y, int $w, int $h)` —
  fills a rectangular region with a solid color at the given opacity. Used by
  DropShadowPrimitive's flood step.

Alternatively, DropShadowPrimitive can create a canvas and manually set pixels.

## Test Plan

Following the ClipNodeTest/MaskNodeTest pattern:

- **FilterNodeTest** — filter node wrapping shapes, filter region computation,
  state restoration, dithering preservation, empty filter
- **GaussianBlurPrimitiveTest** — blur radius, edge handling, transparent pixels,
  named input/output
- **OffsetPrimitiveTest** — positive/negative offsets, out-of-bounds
- **ColorMatrixPrimitiveTest** — all four matrix types, clamping, transparent
  pixels
- **MergePrimitiveTest** — multiple inputs, single input, empty input
- **DropShadowPrimitiveTest** — basic shadow, custom flood color, zero blur
- **FilterPipelineTest** — named results, built-in sources, chaining
- **SVGParserTest** additions — filter parsing, filter attribute resolution,
  primitive parsing, unknown primitives, DropShadow shorthand

## Non-Goals

- `feBlend`, `feComposite`, `feTurbulence`, `feDisplacementMap`, `feConvolveMatrix`,
  or any other SVG filter primitives beyond the five listed
- Animation of filter parameters
- CSS-based filter functions (e.g., `filter: blur(5px)` on non-SVG elements)
- Hardware acceleration or GPU-based rendering
