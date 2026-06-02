# StrokeStyle Design

## Goal

Add full stroke styling to the draw library: configurable line width, dash patterns,
line caps, line joins, and miter limit. Replace the current `?Color $outlineColor`
parameter on `drawPath()` with a `?StrokeStyle $stroke` parameter.

Opacity is explicitly excluded — deferred to a future compositing milestone.

## Types

### `LineCap` enum — `library/draw/LineCap.php`

- `Butt` — stroke ends exactly at the endpoint (default)
- `Round` — semicircle cap centered on the endpoint
- `Square` — rectangular extension of width/2 beyond the endpoint

### `LineJoin` enum — `library/draw/LineJoin.php`

- `Miter` — extend offset edges until they intersect, clipped at miterLimit (default)
- `Round` — arc between offset endpoints at the vertex
- `Bevel` — straight line between the two offset endpoints

### `StrokeStyle` class — `library/draw/StrokeStyle.php`

Immutable value object. Constructor:

```
StrokeStyle(Color $color, float $width = 1.0, ?array $dashArray = null,
            float $dashOffset = 0.0, LineCap $lineCap = LineCap::Butt,
            LineJoin $lineJoin = LineJoin::Miter, float $miterLimit = 4.0)
```

Read-only via public readonly properties.

## API Change

`Canvas::drawPath()` signature changes from:

```php
?Color $outlineColor
```

to:

```php
?StrokeStyle $stroke
```

Passing `null` means no stroke. The stroke color moves into `StrokeStyle`. All callers
must update from `new Color(...)` outline args to `new StrokeStyle(new Color(...))`.

## Stroke Rasterization

### width == 1

Fast path: Bresenham via existing `drawLineInternal`. Caps, joins, and miter limit are
irrelevant at 1px resolution. Dashes still apply (toggled on/off segments, each drawn
with Bresenham).

### width > 1

Polygon expansion approach:

1. Flatten the path to line segments (already done before this point).
2. Apply dash pattern if present — walk the flattened path by cumulative length,
   toggle on/off per dashArray/dashOffset. Each "on" segment becomes an independent
   sub-path for stroke expansion.
3. For each sub-path, compute perpendicular offset curves at ±width/2 on both sides
   of each segment.
4. At each vertex, connect the offset curves with the chosen join:
   - **Miter**: extend offset edges to their intersection point. If the miter length
     exceeds `miterLimit × (width / 2)`, fall back to bevel.
   - **Round**: insert arc polygon vertices between the offset endpoints at the vertex.
   - **Bevel**: straight line connecting the two offset endpoints.
5. At open path endpoints (not closed sub-paths), apply the chosen cap:
   - **Butt**: flat end perpendicular to the segment at the endpoint.
   - **Round**: semicircle polygon of radius width/2 centered on the endpoint.
   - **Square**: rectangular extension of width/2 beyond the endpoint, perpendicular
     to the segment.
6. Fill the resulting polygon via `fillPolygonScanlineMulti` with NonZero rule.

### Dash pattern

`dashArray` is an array of alternating on/off lengths (e.g., `[5, 3]` = 5px on, 3px off).
`dashOffset` shifts the starting position into the pattern.

For each flattened path, compute cumulative segment lengths. Walk along the path,
toggling on/off based on the dash pattern. Emit "on" segments as separate sub-paths.
If `dashArray` is null or empty, the entire path is one "on" segment (solid line).

## Caller Updates

Files that call `drawPath()` with an outline color must be updated:

- `artbot_scripts/drawing.php` — all art commands that pass an outline color
- `scripts/stocks/stocks.php` — chart box borders and price lines
- `tests/Canvas/CanvasTest.php` — all test assertions that use outline color

## Scope

- Includes: width, dashArray, dashOffset, lineCap, lineJoin, miterLimit
- Excludes: stroke-opacity, fill-opacity, general compositing
- The `drawLineInternal` Bresenham helper remains as-is for the width==1 fast path
