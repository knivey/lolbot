# Basic Shapes — Design Spec

## Overview

Add static factory methods to `Path` that construct paths from SVG basic shape
parameters. Replace the public `Canvas::drawEllipse()` and
`Canvas::drawFilledEllipse()` methods with the new Path-based API.

## Factory Methods on `Path`

All methods are `public static` and return a new `Path` instance.

### `Path::rect(float $x, float $y, float $w, float $h, float $rx = 0, float $ry = 0): self`

Constructs a closed rectangular path. When `$rx`/`$ry` > 0, corners are rounded
using `arcTo()`. SVG semantics: radii are clamped to half the smallest
dimension (`min($w/2, $h/2)`). If only one radius is provided, the other
defaults to match.

Path construction (no rounded corners):
```
moveTo(x, y) → lineTo(x+w, y) → lineTo(x+w, y+h) → lineTo(x, y+h) → closePath
```

Path construction (rounded corners):
```
moveTo(x+rx, y)
→ lineTo(x+w-rx, y) → arcTo(rx, ry, 0, false, true, x+w, y+ry)
→ lineTo(x+w, y+h-ry) → arcTo(rx, ry, 0, false, true, x+w-rx, y+h)
→ lineTo(x+rx, y+h) → arcTo(rx, ry, 0, false, true, x, y+h-ry)
→ lineTo(x, y+ry) → arcTo(rx, ry, 0, false, true, x+rx, y)
→ closePath
```

### `Path::circle(float $cx, float $cy, float $r): self`

Four quarter-arc approximation of a circle using `arcTo()`. Closed path.
Clockwise traversal starting from 3 o'clock:

```
moveTo(cx+r, cy)
→ arcTo(r, r, 0, false, true, cx, cy+r)     // 3 o'clock → 6 o'clock
→ arcTo(r, r, 0, false, true, cx-r, cy)     // 6 o'clock → 9 o'clock
→ arcTo(r, r, 0, false, true, cx, cy-r)     // 9 o'clock → 12 o'clock
→ arcTo(r, r, 0, false, true, cx+r, cy)     // 12 o'clock → 3 o'clock
→ closePath
```

Each quarter arc spans 90°, so `largeArc=false`. `sweep=true` for clockwise.

### `Path::ellipse(float $cx, float $cy, float $rx, float $ry): self`

Same as circle but with separate x/y radii. Four quarter-arcs:

```
moveTo(cx+rx, cy)
→ arcTo(rx, ry, 0, false, true, cx, cy+ry)
→ arcTo(rx, ry, 0, false, true, cx-rx, cy)
→ arcTo(rx, ry, 0, false, true, cx, cy-ry)
→ arcTo(rx, ry, 0, false, true, cx+rx, cy)
→ closePath
```

### `Path::line(float $x1, float $y1, float $x2, float $y2): self`

Open path with a single line segment:

```
moveTo(x1, y1) → lineTo(x2, y2)
```

### `Path::polyline(array $points): self`

Open path through all points. Points are `array<array{float, float}>`.

```
moveTo(points[0]) → lineTo(points[1]) → ... → lineTo(points[n-1])
```

Throws `InvalidArgumentException` if fewer than 2 points.

### `Path::polygon(array $points): self`

Closed path through all points. Points are `array<array{float, float}>`.

```
moveTo(points[0]) → lineTo(points[1]) → ... → lineTo(points[n-1]) → closePath
```

Throws `InvalidArgumentException` if fewer than 2 points.

## Canvas Method Removals

### Remove

- `Canvas::drawEllipse()` — replaced by `$canvas->drawPath(Path::ellipse(...), null, $color)`
- `Canvas::drawFilledEllipse()` — replaced by `$canvas->drawPath(Path::ellipse(...), $fill, null)`

### Keep

- `Canvas::drawLine()` — used internally by drawPath/drawPolygon, and externally by stocks script
- `Canvas::drawPolygon()` — used internally by drawPath, and externally by tests/stocks

## Caller Updates

### `artbot_scripts/drawing.php`

| Command | Change |
|---------|--------|
| `lineTest` | `$art->drawLine(...)` → `$art->drawPath(Path::line(...), null, new Color(04, 0), "x")` |
| `filledEllipseTest` | `$art->drawFilledEllipse(...)` → `$art->drawPath(Path::ellipse(...), new Color(04, 0), new Color(04, 0), "x")` |
| `ellipseTest` | `$art->drawEllipse(...)` → `$art->drawPath(Path::ellipse(...), null, new Color(04, 0), "x")` — note: the `$segments` and `$rotate` params have no Path equivalent; these test commands lose that functionality |
| `circles` | `$art->drawEllipse(...)` → `$art->drawPath(Path::ellipse(...), null, $color)` |
| `pentagons` | `$art->drawEllipse(...)` → remains as-is or uses `Path::polygon` with rotated vertices — pentagons with segment=5 and rotation can be done via polygon math |

### `ellipseTest` and `pentagons` — segment/rotation parameters

The old `drawEllipse` accepted `$segments` (polygon vertex count) and `$rotate` (rotation angle).
These have no equivalent in the Path basic-shape factories. Two options:

1. **Remove the test commands** — they were debug commands anyway
2. **Rewrite using Path::polygon** — compute rotated polygon vertices manually

Recommendation: rewrite `pentagons` using Path::polygon with rotated vertex math
(since that's what drawEllipse was doing under the hood for low segment counts).
Remove `ellipseTest` and `filledEllipseTest` as they were debug-only commands
(their functionality is covered by the general Path API).

## File Changes

| File | Action |
|------|--------|
| `library/draw/Path.php` | Add 6 static factory methods |
| `library/draw/Canvas.php` | Remove `drawEllipse()` and `drawFilledEllipse()` |
| `artbot_scripts/drawing.php` | Update callers |
| `tests/Canvas/PathTest.php` | Add tests for each factory method |

## Test Plan

For each factory method, test:
1. **Geometry** — flatten the path and verify vertex positions match expectations
2. **Rendering** — drawPath the shape on a small canvas and verify pixels
3. **Edge cases** — degenerate rect (zero width/height), polygon with 2 points,
   rounded rect with rx > w/2 (clamping)
