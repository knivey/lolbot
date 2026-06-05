# Drawing Library SVG-Compatible Roadmap

## Goal

Evolve `library/draw/` from its current set of canvas primitives (`drawLine`,
`drawFilledEllipse`, `drawPolygon`, etc.) into a structured 2D graphics library
that can partially render SVG documents on an IRC character-cell canvas.

The guiding principle is **SVG compatibility**: wherever the SVG spec defines a
concept, we adopt that concept's semantics and naming. This gives us a
well-defined target rather than designing an API from scratch, and opens the
door to an SVG parser layer that maps directly onto our types.

## Current State

Files in `library/draw/`:

- **`Canvas.php`** — character-cell pixel grid with `drawPath`, `drawPoint`,
  `drawLine`, `drawFilledEllipse`, `drawEllipse`, `drawPolygon` (scanline fill + outline),
  `fillColor` (flood fill), `overlay`. Supports half-block rendering for 2x
  vertical resolution, transform stack, fill/stroke opacity, gradient fills/strokes.
  Output is IRC color-coded text (`\x03` codes).
- **`Color.php`** — IRC color constants, fg/bg pair, implements `Paint` interface.
- **`Pixel.php`** — single cell: fg, bg, text character, fgAlpha, bgAlpha.
- **`Path.php`** — ordered path segments (M/L/C/Q/A/Z), flatten to polygon,
  static factories for rect, circle, ellipse, polygon, polyline, line.
- **`StrokeStyle.php`** — width, dash array/offset, line cap/join, miter limit,
  opacity, accepts `Paint` interface (solid color or gradient).
- **`Transform.php`** — 2x3 affine matrix with translate, rotate, scale, skew,
  composition, and canvas transform stack.
- **`FillRule.php`** — enum: NonZero, EvenOdd.
- **`Paint.php`** — interface for solid and gradient paint sources.
- **`IrcPalette.php`** — 99-entry IRC→RGB lookup, nearestColor() with Din99 distance.
- **`Compositor.php`** — source-over blend with opacity compositing.
- **`LinearGradient.php`** — gradient along a vector with spread methods.
- **`RadialGradient.php`** — gradient along a radius with focal point and spread methods.
- **`ColorStop.php`** — value object: offset, r, g, b with validation.
- **`SpreadMethod.php`** — enum: Pad, Reflect, Repeat.
- **`GradientMath.php`** — shared trait for gradient spread/interpolation logic.

The `drawPolygon` method already implements non-zero winding rule scanline
fill with top-left pixel sampling that aligns with Bresenham outlines.

## Target Architecture

```
SVG string ──► SVGParser ──► Scene Tree ──► Renderer ──► Canvas
                                 ▲
                                 │ built from
                                 │
                          Generic Drawing API
                          (Path, Paint, StrokeStyle, Transform, ...)
                          usable without SVG
```

The generic drawing API is the library's core. The SVG parser is a thin layer
on top that maps SVG XML elements and attributes to these types. The renderer
rasterizes the scene tree onto a `Canvas`.

### Core Types (to be built)

| Type | SVG counterpart | Purpose |
|------|----------------|---------|
| `Path` | `<path d="...">` | Ordered list of path segments; can be filled or stroked |
| `PathSegment` | M/L/C/Q/A/Z commands | Individual path command (MoveTo, LineTo, CubicBezier, etc.) |
| `Paint` | `fill`/`stroke` attribute values | Solid color, gradient, pattern, or none |
| `StrokeStyle` | `stroke-*` attributes | Width, dash array, line cap, line join, miter limit |
| `Transform` | `transform` attribute | 2x3 affine matrix (translate, rotate, scale, skew, matrix) |
| `FillRule` | `fill-rule` attribute | Enum: NonZero, EvenOdd |
| `Gradient` | `<linearGradient>`, `<radialGradient>` | Color stops along a vector or radius |
| `ClipPath` | `<clipPath>` | A path used as a clipping region |
| `Mask` | `<mask>` | A luminance or alpha mask |
| `Scene` / `Group` | `<svg>`, `<g>` | Container of renderable elements with inherited properties |

### Rendering Pipeline

1. **Parse** — SVG XML → typed objects (Path, Paint, Transform, etc.)
2. **Build scene tree** — groups with inherited paint/transform properties
3. **Flatten transforms** — apply transform stack to get canvas-space coordinates
4. **Flatten Béziers/arcs** — convert curves to line segments at a tolerance
   derived from viewBox-to-canvas scale
5. **Rasterize** — for each element:
   - Fill: scanline convert the flattened polygon with the fill rule
   - Stroke: expand stroked path to a fillable region (or Bresenham for width=1)
   - Apply clip/mask
6. **Composite** — blend onto the Canvas

## Feature Tiers

### Tier 1 — Foundation (essential for basic SVGs)

**Path API and SVG path commands:**
- `Path` class: an ordered list of segments with a current-point cursor
- SVG path `d` commands: `M`, `L`, `H`, `V`, `C`, `Q`, `S`, `T`, `A`, `Z`
  (absolute and relative variants)
- Smooth shorthand commands (`S`/`T`) that infer control points from the
  previous curve
- Arc command (`A`) — elliptical arc-to, flattened to line segments
- `Path::flatten(float $tolerance): array<array{float, float}>` — convert all
  segments to a polygon vertex array for fill/outline rasterization

**Basic shapes (mapped to Path internally):**
- `<rect>` — including rounded corners (`rx`, `ry`)
- `<circle>` — flattened to polygon at appropriate segment count
- `<ellipse>` — same
- `<line>`, `<polyline>`, `<polygon>`

**Transforms:**
- `Transform` class: 2x3 affine matrix `[a b c d e f]` representing
  `[[a c e], [b d f], [0 0 1]]`
- Operations: `translate(tx, ty)`, `rotate(angle, [cx, cy])`,
  `scale(sx, [sy])`, `skewX(angle)`, `skewY(angle)`, `matrix(a,b,c,d,e,f)`
- Transform stack: methods to push/pop a transform stack on Canvas
- Transform composition: `Transform::multiply(a, b)`

**Coordinate system:**
- `viewBox` mapping: affine transform from SVG user coordinates to IRC
  character cells
- `preserveAspectRatio` handling

**Paint:**
- Solid colors: named CSS colors, `#rgb`, `#rrggbb`, `rgb()`, `rgba()`
  mapped to the IRC extended color palette (colors 0–98) via nearest-color
  matching
- `fill="none"`, `stroke="none"`

**Fill rules:**
- `nonzero` (already implemented in `fillPolygonScanline`)
- `evenodd` — alternate span open/close logic in scanline converter

### Tier 2 — Rich Rendering

**Gradients:**
- `LinearGradient`: color stops along a vector (`x1, y1, x2, y2`)
- `RadialGradient`: color stops along a radius (`cx, cy, r, [fx, fy]`)
- Stop interpolation: linear RGB blend between adjacent stops
- Gradient spread methods: `pad`, `reflect`, `repeat`
- Applied per-pixel during scanline fill (compute gradient position,
  interpolate stop colors, map to nearest IRC color)
- `gradientTransform`: additional affine transform from gradient space to user space
- `gradientUnits`: `objectBoundingBox` (0–1 → shape bbox) and `userSpaceOnUse` (SVG user coordinates)
- ViewBox coordinate mapping: Canvas inverse CTM ensures gradients sample in correct coordinate space
- Nested group support: parentTransform tracking ensures gradients inside transformed groups resolve correctly

**Advanced strokes:**
- `stroke-width` > 1: convert to polygon by offsetting both sides of the path
  (Minkowski sum of path + disc of radius width/2)
- `stroke-dasharray` / `stroke-dashoffset`: dash pattern along path length
- `stroke-linecap`: `butt`, `round`, `square`
- `stroke-linejoin`: `miter`, `round`, `bevel`
- `stroke-miterlimit`
- `stroke-opacity`

**Groups and inheritance:**
- `<g>` elements with child elements inheriting `fill`, `stroke`, `transform`,
  `opacity`, etc.
- Scene tree with property cascading (child overrides parent)

**Opacity:**
- `opacity`, `fill-opacity`, `stroke-opacity`
- Render to an offscreen buffer, then composite with alpha (Compositor class built)

**Clipping and masking:**
- `clip-path` referencing a `<clipPath>` element — restricts drawing to the
  clip region (implemented as a per-pixel test during rasterization)
- `<mask>` — luminance or alpha mask controlling per-pixel visibility

### Tier 3 — Polish and Effects

**Filters:**
- `<filter>` element with filter primitives:
  - `feGaussianBlur` — box blur or Gaussian approximation on character grid
  - `feDropShadow` — offset + blurred copy
  - `feOffset` — translate the source graphic
  - `feColorMatrix` — per-pixel color matrix multiplication
  - `feMerge` — composite multiple filter results
- Filters operate on offscreen buffers

**Text:**
- `<text>` and `<tspan>` elements
- Font properties: `font-family`, `font-size`, `font-weight`, `font-style`,
  `text-anchor`, `dominant-baseline`
- Text layout within a bounding box (alignment, wrapping)
- Text on path (`<textPath>`)
- For IRC: map to existing ASCII art fonts or plain text output

**Use/Symbol/Defs:**
- `<defs>` — non-rendering container for reusable definitions
- `<use href="#id">` — instantiate a defined element
- `<symbol>` — reusable graphic template with its own viewBox

**Markers:**
- `<marker>` elements referenced by `marker-start`, `marker-mid`, `marker-end`
- Render arrowheads, dots, etc. at path vertices

### Tier 4 — IRC-Specific Enhancements

**Higher effective resolution:**
- Half-block characters (`▀▄`) for 2x vertical resolution (already partially
  supported in Canvas)
- Quarter-block characters (`▖▗▘▙▚▛▜▝▞▟`) for 2x2 sub-cell resolution
- Block element shading (`▔▁▂▃▄▅▆▇█`) as a brightness/density scale

**Color:**
- IRC extended colors (0–98): 16 standard + 83 extra colors + grayscale ramp
- Color quantization: map SVG RGB values to nearest IRC palette color using
  perceptual color-space distance (see Design Decisions below)
- Floyd-Steinberg dithering or ordered dithering for smooth gradients

**Unicode line drawing:**
- Box drawing characters (`─│┌┐└┘├┤┬┴┼`) for thin/thick strokes
- Double-line variants (`═║╔╗╚╝╠╣╦╩╬`)
- Auto-selection of appropriate joining characters at line intersections

## Design Decisions

### Bézier flattening

Cubic and quadratic Béziers are flattened to line segments using recursive
subdivision. The tolerance (maximum deviation from the true curve) is derived
from the viewBox-to-canvas scale: a curve that maps to 2 pixels on the canvas
needs far fewer segments than one that maps to 200 pixels.

```
tolerance = 0.5 pixels (in canvas space)
segments  = estimated from curve flatness + canvas scale
```

### Arc flattening

SVG arc (`A`) commands are converted to cubic Béziers first (using the
approach from the SVG spec's implementation notes: endpoint to center
parameterization), then flattened to line segments.

### Stroke expansion

For `stroke-width > 1`, the stroked path is expanded into a fillable polygon
by computing the parallel offset curves on both sides of the path. This is the
hardest part — offset curves of Béziers are not Béziers, so they must be
approximated. Common approach: flatten the path to line segments, offset each
segment perpendicular by `width/2`, join with miter/bevel/round at corners,
then fill the resulting polygon.

For `stroke-width == 1`, keep the fast Bresenham line path.

### Color quantization

SVG allows arbitrary RGB colors. The IRC canvas has a fixed palette of 99
colors (indices 0–98: 16 standard + 83 extended + grayscale). Strategy:

1. Map each SVG RGB value to the nearest IRC palette color using perceptual
   color-space distance
2. For gradients, optionally dither to reduce banding

**Reference implementation:** `artbot_scripts/urlimg.php` contains a working
pattern using the `Itwmw\ColorDifference` library. The `!ascii` command builds
a `$palette` array of `Color` objects indexed by IRC color code, then uses one
of several distance methods to find the closest match:

- `getClosestMatchDin99()` — Din99 color space (default, good balance of
  speed and accuracy)
- `getClosestMatchCIEDE2000()` — CIEDE2000 (highest quality, slowest)
- `getClosestMatchEuclideanLab()` — Euclidean distance in Lab space
- `getClosestMatchEuclideanRGB()` — Euclidean distance in RGB space

The draw library should implement its own color quantization following this
pattern: build an IRC palette lookup table once, then find nearest matches by
perceptual distance. The `Itwmw\ColorDifference` package is already a project
dependency.

### Fill rule implementation

The current `fillPolygonScanline` uses a winding counter (non-zero rule).
Even-odd support requires only changing the span-tracking logic: instead of
tracking winding count, toggle a boolean at each intersection.

## Non-Goals

- **Full SVG compliance** — we target a useful subset, not the entire spec
- **Font rendering** — IRC clients render text with their own fonts and metrics;
  we rely on ASCII art fonts or direct text output
- **CSS styling** — external stylesheets are out of scope; `<style>` block CSS class resolution is tracked in milestone 15
- **Animation** — IRC is static text; animation is not possible
- **SVG namespaced extensions** — no foreignObject, no RDF metadata, etc.

## Milestone Order

1. ~~**Path API** — Path class, path segments, SVG `d` string parser,
   Béziers + arc flattening, `Canvas::drawPath()`~~ **DONE**
2. ~~**Basic shapes** — rect, circle, ellipse as convenience methods on Path~~ **DONE**
3. ~~**Transform** — affine matrix, transform stack on Canvas~~ **DONE**
4. ~~**EvenOdd fill rule** — add to scanline converter~~ **DONE**
5. ~~**StrokeStyle** — width, dash, caps, joins (strokes > 1px), stroke-opacity~~ **DONE**
6. ~~**Compositor / opacity** — Pixel alpha, IrcPalette, Compositor, fill-opacity, element opacity~~ **DONE**
7. ~~**Gradient Paint** — linear, radial, color stops, stop interpolation~~ **DONE**
8. ~~**Scene tree / Groups** — `<g>` elements, property inheritance (fill, stroke, transform, opacity), child overrides parent~~ **DONE**
9. ~~**SVG parser** — XML parser mapping SVG elements to scene tree~~ **DONE**
10. ~~**Clip/Mask** — clipping regions and masks~~ **DONE**
11. **Filters** — blur, shadow, color matrix
12. **Text** — SVG text elements
13. **Use/Symbol/Defs** — reusable elements
14. **IRC enhancements** — higher resolution, better color, Unicode lines
15. ~~**CSS class support** — parse `<style>` blocks, resolve `class` attributes to fill/stroke values~~ **DONE**
    - Parse `<style>` element text content into selector→property rules (class selectors `.foo`, type selectors `path`, universal `*`, ID selectors `#foo`)
    - Track `class` attribute on each parsed element
    - Extend `getEffectiveAttr()` cascade: inline `style` → CSS class rules → presentation attribute
    - Selector specificity: ID > class > type; equal specificity → last rule wins
    - External stylesheets remain out of scope; only inline `<style>` blocks within the SVG document
