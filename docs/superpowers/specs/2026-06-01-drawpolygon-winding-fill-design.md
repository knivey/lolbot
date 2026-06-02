# drawPolygon: non-zero winding polygon fill primitive

## Problem

The `@stars` artbot command draws 5-pointed star outlines by connecting 10
vertices with Bresenham line segments, then flood-fills the interior from the
centroid. At sharp corners of the star (tips and concave armpits), the
rasterized line segments do not form a 4-connected seal. The 4-way flood fill
in `Canvas::fillColor` therefore correctly treats the corner pixel as outside
the boundary and refuses to fill it — leaving visible stranded unfilled pixels
that look like notches in the star's outline.

This is not a bug in `fillColor`; it is the inherent limitation of
outline-then-flood-fill on a discrete pixel grid. The proper fix is to add a
polygon primitive that does not depend on outline continuity for filling.

## Scope

Add a new `Canvas::drawPolygon` primitive that performs scanline polygon fill
using the non-zero winding rule. Refactor `@stars` to use it. Existing
primitives (`fillColor`, `drawLine`, `drawPoint`) are unchanged.

Set up PHPUnit and add tests covering the new primitive, including a direct
regression test for the stars corner-stranding bug.

## Algorithm: non-zero winding rule scanline fill

Input: ordered list of vertex coordinates `[[$x, $y], ...]` defining a closed
polygon (last vertex implicitly connects to first).

```
Iterate Y over the polygon's bounding box (floor(minY) to ceil(maxY) of all
vertices), snapped to integer scanlines. `drawPoint` already bounds-checks
against canvas dimensions, so out-of-canvas scanlines are harmless.

For each scanline Y:
  intersections = []
  For each edge (v[i], v[i+1]):
    Use half-open convention: include edge iff min(y0,y1) <= Y < max(y0,y1)
      (handles horizontal edges and vertices-on-scanline uniformly)
    If included:
      x_intersect = x0 + (x1-x0) * (Y - y0) / (y1 - y0)
      direction   = +1 if y1 > y0 else -1
      intersections.push( (x_intersect, direction) )
  Sort intersections by x
  Walk left-to-right, maintaining running winding count
  Fill pixels where winding count != 0
```

### Pixel snapping

- Outline: round vertex coords to int before passing to `drawLine` (consistent
  with how `stars` already rounded its trig output).
- Fill: keep float X intersections; for each non-zero-winding span fill
  `ceil(xLeft)` to `floor(xRight)` inclusive. This keeps fill strictly inside
  the geometric boundary so the outline covers the seam.

### Why non-zero winding (not even-odd)

The current `@stars` command traces vertices in perimeter order, so the
polygon is simple and both rules produce identical output. Non-zero winding
is chosen over even-odd because:

- It generalizes correctly to future self-intersecting shapes (e.g., a
  pentagram drawn as five crossing strokes will fill the central pentagon,
  which matches SVG `nonzero` semantics).
- The additional cost is one sign bit per edge — negligible.

## API

A single method on `draw\Canvas`:

```php
/**
 * Draw a closed polygon with optional fill and outline.
 *
 * Fill is applied first via scanline conversion using the non-zero winding
 * rule; outline is drawn on top via drawLine so it cleanly covers the fill
 * boundary. Polygon is implicitly closed (last vertex connects to first).
 *
 * @param array<int, array{0: int|float, 1: int|float}> $points [[$x, $y], ...]
 */
public function drawPolygon(
    array $points,
    ?Color $fillColor,
    ?Color $outlineColor,
    string $text = ''
): void
```

- `fillColor = null`    → outline only
- `outlineColor = null` → fill only
- both set              → fill then outline, with the method in full control of
                          ordering and any fill inset needed
- both null             → no-op (early return)

Vertex shape `[$x, $y]` matches what `@stars` already builds in its `$points`
array — drop-in compatible.

### Why one combined method, not separate fill/stroke

Letting the primitive own both operations guarantees the fill is properly
contained within the outline (e.g., inset by 1px so the outline covers any
sub-pixel seam) and removes the caller's obligation to remember call
ordering. The caller picks which operations apply via the nullable color
parameters.

## Stars command integration

`artbot_scripts/drawing.php`, `stars()` function. Per-star changes only;
outer loop, canvas sizes, vertex math, and `pumpToChan` output are unchanged.

### Before (per star)

1. Create temp `$tart` canvas
2. Loop `drawLine` between consecutive vertices (manual outline)
3. 60% chance: `fillColor($x, $y, ...)` from centroid ← bug source
4. `overlay` onto `$art`

### After (per star)

1. Create temp `$tart` canvas
2. Pick `$fillColor` and `$outlineColor` independently (matches existing
   random-per-star behavior)
3. Compute `$points` exactly as today (no vertex-math changes)
4. `$willFill = random_int(0, 4) > 1;` (preserves the 60% chance)
5. `$tart->drawPolygon($points, $willFill ? $fillColor : null, $outlineColor);`
6. `overlay` onto `$art`

One call replaces the manual outline loop + flood fill. The corner-stranding
bug is gone because fill no longer depends on outline continuity.

## Robustness

All algorithm edge cases are handled by the half-open convention
(`min(y0,y1) <= Y < max(y0,y1)`):

- **Horizontal edges** → excluded (no Y span)
- **Vertex exactly on a scanline** → counted by exactly one of the two edges
  sharing it, never both (no double-counting)
- **Polygon with < 3 vertices** → early return, no-op
- **Out-of-bounds vertices** → `drawPoint` already bounds-checks via
  `isset($this->data[$y][$x])`; safe
- **Self-intersecting polygon** → winding rule fills regions with non-zero
  winding count (well-defined semantics, no crash, no garbage)
- **Both colors null** → early return, no-op

## PHPUnit setup

Project currently has no test framework (per AGENTS.md). Add it.

### Composer / tooling

- `composer require --dev phpunit/phpunit:^10` — supports PHP 8.1+
  (project's minimum)
- `phpunit.xml` at project root, using composer's PSR-4 autoload — **not**
  `bootstrap.php` (which wires up DB/IRC and is not needed for pure-canvas
  tests)
- `tests/` directory at project root
- `composer.json` scripts: add `"test": "vendor/bin/phpunit"`
- Update AGENTS.md to note PHPUnit is the test framework and the run command
  is `composer test`

### Test cases (`tests/Canvas/CanvasTest.php`)

1. **Square fill + outline** — 4-vertex square. Assert interior pixels have
   fill color, boundary pixels have outline color.
2. **Square fill only** (`outlineColor = null`) — interior filled, boundary
   NOT colored.
3. **Square outline only** (`fillColor = null`) — only boundary colored.
4. **Both colors null** — canvas unchanged.
5. **< 3 vertices** — no-op (canvas unchanged).
6. **Polygon fully outside canvas bounds** — no exceptions, no rogue pixels.
7. **Stars corner-stranding regression** — fixed-coordinate 5-pointed star
   (deterministic, no `rand`). Use an independent pure-PHP winding-number
   point-in-polygon oracle (written inline in the test file, not a shared
   util) to assert: for every pixel in the bounding box, if the oracle says
   "inside," the canvas shows fill color OR outline color. This directly
   encodes "no inside pixel is left unfilled" using a separately-derived
   truth function — no golden output needed.

Test 7 is the regression test that proves the bug is dead. The others are
baseline coverage for the new primitive.

### Out of scope for tests

The existing `fillColor` method is not tested. The bug is not in `fillColor`
(it is doing the right thing given its inputs), and we are not changing it.
Leave it alone for now; future tests can cover it.

## Verification

After implementation:

- `composer phpstan` — static analysis must pass
- `vendor/bin/psalm` — static analysis must pass
- `composer test` — new PHPUnit suite must pass, including the
  corner-stranding regression test
- Manual: `php artbots.php`, trigger `@stars` and `@stars --lines 100` in a
  test channel, visually confirm corners no longer have stranded unfilled
  pixels

## Files touched

- `library/draw/Canvas.php` — add `drawPolygon` method
- `artbot_scripts/drawing.php` — refactor `stars()` to use `drawPolygon`
- `composer.json` — add PHPUnit dev dep, add `test` script
- `phpunit.xml` — new
- `tests/Canvas/CanvasTest.php` — new
- `AGENTS.md` — note PHPUnit + `composer test`
