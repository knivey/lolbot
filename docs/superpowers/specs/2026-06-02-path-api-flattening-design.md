# Path API and Flattening — Design Spec

## Goal

Add a `Path` class and path segment type system to `library/draw/`, enabling
SVG-style path construction (moveTo, lineTo, cubic/quadratic Béziers, arcs,
closePath) with Bézier and arc flattening to polygon vertices. Integrate with
`Canvas` via `drawPath()` supporting multi-subpath fill and outline.

This is **Milestone 1** from the SVG roadmap. It does NOT include the SVG
`d`-string parser, transforms, or basic shape convenience methods — those are
later milestones that build on this foundation.

## Current State

`library/draw/` has three files:

- **`Canvas.php`** — `drawPoint`, `drawLine`, `drawFilledEllipse`,
  `drawEllipse`, `drawPolygon` (scanline fill + outline), `fillColor`,
  `overlay`. The `fillPolygonScanline` method handles a single polygon with
  non-zero winding rule and top-left pixel sampling.
- **`Color.php`** — IRC 16-color palette constants, fg/bg pair.
- **`Pixel.php`** — single cell: fg, bg, text character.

`drawPolygon` snaps float vertices to integers, then fills (scanline) and
outlines (Bresenham) using the same snapped polygon. It accepts a single
polygon — no multi-subpath support.

## Design

### Segment Type System

An interface + 6 concrete classes, all in `library/draw/PathSegment.php`:

Since the Path class resolves smooth curves to canonical CubicBezier /
QuadraticBezier at insertion time (with explicit control points), the stored
segments are always self-contained. `flatten()` only needs the start point:

```php
interface PathSegment {
    /**
     * Flatten this segment into polygon vertices starting from the given point.
     *
     * @param float $startX Current point X when this segment begins.
     * @param float $startY Current point Y when this segment begins.
     * @return array<array{float, float}> Vertices produced by this segment
     *         (excluding the start point, which the caller already has).
     */
    public function flatten(float $startX, float $startY): array;

    /** Returns the endpoint of this segment (where the cursor lands). */
    public function endPoint(): array;
}
```

#### Concrete Segment Classes

| Class | Properties | flatten() behavior |
|-------|-----------|-------------------|
| `MoveTo(float $x, float $y)` | x, y | Returns `[]` (no vertices) |
| `LineTo(float $x, float $y)` | x, y | Returns `[[x, y]]` |
| `CubicBezier(float $c1x, float $c1y, float $c2x, float $c2y, float $x, float $y)` | Two control points + endpoint | Recursive De Casteljau subdivision |
| `QuadraticBezier(float $cpx, float $cpy, float $x, float $y)` | One control point + endpoint | Recursive De Casteljau subdivision |
| `EllipticalArc(float $rx, float $ry, float $xAxisRot, bool $largeArc, bool $sweep, float $x, float $y)` | Radii, rotation, flags, endpoint | Convert to cubic Béziers, then flatten |
| `ClosePath()` | None | Returns `[]` (handled by Path::flatten) |

### Path Class

`library/draw/Path.php`:

```php
class Path {
    /** @var array<PathSegment> */
    private array $segments = [];

    private float $currentX = 0.0;
    private float $currentY = 0.0;
    private float $subpathStartX = 0.0;
    private float $subpathStartY = 0.0;
    private bool $hasCurrentPoint = false;

    // --- Builder methods (return $this for chaining) ---

    public function moveTo(float $x, float $y): self;
    public function lineTo(float $x, float $y): self;
    public function horizontalLineTo(float $x): self;
    public function verticalLineTo(float $y): self;
    public function cubicTo(float $c1x, float $c1y, float $c2x, float $c2y, float $x, float $y): self;
    public function smoothCubicTo(float $c2x, float $c2y, float $x, float $y): self;
    public function quadTo(float $cpx, float $cpy, float $x, float $y): self;
    public function smoothQuadTo(float $x, float $y): self;
    public function arcTo(float $rx, float $ry, float $xAxisRot, bool $largeArc, bool $sweep, float $x, float $y): self;
    public function closePath(): self;

    // --- Query ---

    /** @return array{float, float} */
    public function getCurrentPoint(): array;
    public function isEmpty(): bool;

    // --- Flatten ---

    /**
     * Flatten all segments into polygon vertices grouped by subpath.
     *
     * Each subpath is an array of [x, y] float pairs. ClosePath segments
     * implicitly close a subpath. MoveTo starts a new subpath.
     *
     * Degenerate subpaths (single MoveTo with no drawing commands) are omitted.
     * Trailing subpaths without ClosePath are included (open paths are treated
     * as closed for fill purposes, matching SVG semantics).
     *
     * @param float $tolerance Maximum deviation from true curve, in canvas units.
     * @return array<array<array{float, float}>> List of subpaths, each a vertex list.
     */
    public function flatten(float $tolerance = 0.5): array;
}
```

#### Smooth Curve Resolution

`smoothCubicTo(c2x, c2y, x, y)` reflects the previous cubic segment's second
control point through the current point to compute c1:

```
c1 = currentPoint + (currentPoint - prevC2)  // if previous was C or S
c1 = currentPoint                              // if previous was anything else
```

`smoothQuadTo(x, y)` reflects the previous quadratic segment's control point:

```
cp = currentPoint + (currentPoint - prevCp)   // if previous was Q or T
cp = currentPoint                               // if previous was anything else
```

The smooth-cubic reflection only considers the previous C/S segment; a
previous Q/T segment does NOT contribute its control point. Likewise
smooth-quadratic only considers the previous Q/T segment. This matches SVG
spec behavior.

Path tracks the previous segment's type and relevant control point internally.

#### Implicit LineTo After MoveTo

If any drawing command (lineTo, cubicTo, etc.) is called when `hasCurrentPoint`
is false, the Path implicitly performs a `moveTo(0, 0)` first. This matches
SVG behavior.

### Bézier Flattening

#### Cubic Bézier: De Casteljau Subdivision

Given `P0, P1, P2, P3` (start, control1, control2, end):

1. Compute flatness: maximum perpendicular distance from P1 and P2 to the
   line P0→P3. If both are within `tolerance`, output line `[P3x, P3y]`.
2. Otherwise, subdivide at t=0.5:
   ```
   Q0 = (P0 + P1) / 2
   Q1 = (P1 + P2) / 2
   Q2 = (P2 + P3) / 2
   R0 = (Q0 + Q1) / 2
   R1 = (Q1 + Q2) / 2
   S  = (R0 + R1) / 2   ← midpoint on curve
   ```
   Recurse on `[P0, Q0, R0, S]` and `[S, R1, Q2, P3]`.

#### Quadratic Bézier: Same Approach

Given `P0, P1, P2`:

1. Flatness: distance from P1 to line P0→P2.
2. Subdivide at t=0.5:
   ```
   Q0 = (P0 + P1) / 2
   Q1 = (P1 + P2) / 2
   S  = (Q0 + Q1) / 2   ← midpoint on curve
   ```
   Recurse on `[P0, Q0, S]` and `[S, Q1, P2]`.

#### Recursion Depth Limit

Max recursion depth of ~20 to prevent stack overflow on degenerate curves.
At depth 20, the segment is subdivided into ~1M pieces — more than enough for
any terminal canvas.

### Arc Conversion (Endpoint → Center Parameterization)

Follows SVG spec Appendix F.6.5 to convert an `EllipticalArc` to one or more
cubic Bézier segments:

1. Compute center `(cx, cy)` and angles `theta1`, `dtheta` from the endpoint
   parameters.
2. Split the arc at 90-degree boundaries (so each piece spans ≤ 90°).
3. For each ≤ 90° piece, compute a cubic Bézier approximation using the
   standard formula for circular arc-to-Bézier conversion (control points at
   `k = 4/3 * tan(angle/4)` along the tangent).
4. Flatten the resulting cubics.

Edge cases:
- `rx == 0 || ry == 0`: degenerate to a LineTo.
- Start point == end point: omit (no-op per SVG spec).
- Radii scaled up if too small to span the arc (SVG spec requires this).

### Canvas Integration

#### `Canvas::drawPath()`

```php
public function drawPath(
    Path $path,
    ?Color $fillColor,
    ?Color $outlineColor,
    string $text = ''
): void {}
```

1. Call `$path->flatten()` to get subpath vertex arrays (float coordinates).
2. Snap all vertices to integers.
3. If fill: call multi-subpath `fillPolygonScanline()`.
4. If outline: for each subpath, draw lines between consecutive snapped
   vertices, plus a closing line if the subpath was closed.

#### Multi-subpath `fillPolygonScanline()`

Refactor the existing private method to accept a list of subpaths instead of
a single polygon:

```php
/**
 * @param array<array<array{int, int}>> $subpaths
 */
private function fillPolygonScanlineMulti(array $subpaths, Color $color, string $text): void
```

For each scanline Y:
1. Collect intersections from ALL subpaths' edges.
2. Sort by x.
3. Walk intersections tracking winding count across all subpaths combined.

This allows overlapping subpaths to interact (donut shapes, compound paths).

The existing single-polygon `fillPolygonScanline` is updated to delegate to
this method (wrapping the single polygon in an array), so `drawPolygon`
continues to work unchanged.

### Testing Strategy

Tests in `tests/Canvas/PathTest.php`:

**Segment flatten tests:**
- `LineTo::flatten()` returns the endpoint
- `CubicBezier::flatten()` for a straight line returns ~1 vertex
- `CubicBezier::flatten()` for a curve returns multiple vertices within tolerance
- `QuadraticBezier::flatten()` same properties
- `EllipticalArc::flatten()` for a semicircle produces reasonable segment count
- `EllipticalArc::flatten()` for a degenerate arc (rx=0) produces a line
- `ClosePath::flatten()` returns empty array
- `MoveTo::flatten()` returns empty array

**Path builder tests:**
- `moveTo` + `lineTo` chain produces correct segments
- `smoothCubicTo` reflects previous cubic control point
- `smoothQuadTo` reflects previous quadratic control point
- `closePath` resets to subpath start
- Implicit `moveTo(0,0)` when drawing without a current point
- Multiple subpaths via multiple `moveTo` calls
- Chaining returns `$this`

**Path flatten tests:**
- Simple rectangle path flattens to 4-corner polygon
- Path with cubic Béziers flattens within tolerance
- Path with arc flattens within tolerance
- Multi-subpath path produces separate vertex arrays
- ClosePath terminates a subpath; subsequent MoveTo starts a new one
- Open subpath (no ClosePath) at end of path is included

**Canvas drawPath tests:**
- Filled path produces same result as equivalent drawPolygon
- Outlined path matches drawPolygon outline
- Multi-subpath filled path (donut: outer CCW + inner CW) creates a hole
- Empty path is a no-op
- Path fully outside canvas is a no-op
- Path with both fill and outline, outline wins at boundary

**Regression:**
- Existing `drawPolygon` tests continue to pass after refactoring
  `fillPolygonScanline` to delegate to `fillPolygonScanlineMulti`

## File Structure

```
library/draw/
  Path.php           — NEW: Path class
  PathSegment.php    — NEW: interface + 6 concrete segment classes
  Canvas.php         — MODIFIED: add drawPath(), refactor fillPolygonScanline
  Color.php          — unchanged
  Pixel.php          — unchanged

tests/Canvas/
  PathTest.php       — NEW: segment, path, and drawPath tests
  CanvasTest.php     — existing (should continue to pass)
```

## Non-Goals

- SVG `d`-string parsing (Milestone 2)
- Transforms (Milestone 3)
- Basic shape convenience methods (Milestone 2)
- EvenOdd fill rule (Milestone 4)
- Stroke width > 1 (Milestone 5)
- Gradients (Milestone 6)
