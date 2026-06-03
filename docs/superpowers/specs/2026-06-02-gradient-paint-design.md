# Gradient Paint Design

## Overview

Add gradient fills and strokes to the draw library. Introduces a `Paint` interface that generalizes `Color` and gradients, allowing `drawPath()` and `StrokeStyle` to accept either solid colors or gradients (linear/radial) with configurable spread methods.

## Paint Interface

```php
interface Paint {
    public function getColorAt(float $x, float $y): array; // [r, g, b] 0-255
    public function isSolid(): bool;
}
```

- `Color` implements `Paint`: `isSolid()` returns `true`, `getColorAt()` returns the RGB of `$this->fg` via `IrcPalette::getRgb($this->fg)`. If `$this->fg` is null, returns `[0, 0, 0]`
- Gradient classes implement `Paint`: `isSolid()` returns `false`, `getColorAt()` computes position-dependent RGB

**Solid fast path:** When `isSolid()` is true, the scanline converter uses the stored IRC codes directly (`$color->fg` / `$color->bg`), skipping per-pixel `getColorAt()` + `IrcPalette::nearestColor()`.

**Gradient path:** For non-solid paints, each pixel `(x, y)` calls `$paint->getColorAt($x, $y)`, quantizes via `IrcPalette::nearestColor($r, $g, $b)`, and sets `->fg` to the resulting IRC code. The `->bg` is set to `null` for gradient pixels.

## ColorStop

```php
class ColorStop {
    public function __construct(
        public readonly float $offset, // 0.0-1.0
        public readonly int $r,
        public readonly int $g,
        public readonly int $b,
    ) {}
}
```

- `offset` must be in [0.0, 1.0], throws `InvalidArgumentException`
- RGB values must be in [0, 255], throws `InvalidArgumentException`
- Stops are sorted by offset in the gradient constructors (LinearGradient/RadialGradient)
- Duplicate offsets are allowed (produces a sharp color transition)

## SpreadMethod Enum

```php
enum SpreadMethod {
    case Pad;      // clamp to first/last stop color
    case Reflect;  // bounce back and forth
    case Repeat;   // wrap around
}
```

## LinearGradient

```php
class LinearGradient implements Paint {
    public function __construct(
        public readonly float $x1,
        public readonly float $y1,
        public readonly float $x2,
        public readonly float $y2,
        public readonly array $stops,       // ColorStop[]
        public readonly SpreadMethod $spreadMethod = SpreadMethod::Pad,
    ) {}
}
```

- At least 2 stops required, throws `InvalidArgumentException` if fewer
- Degenerate vector (x1==x2 && y1==y2) is allowed — all pixels get first stop color

**t computation:**
- `dx = x2 - x1`, `dy = y2 - y1`, `lenSq = dx*dx + dy*dy`
- If `lenSq == 0`: return first stop color
- `t = ((x - x1)*dx + (y - y1)*dy) / lenSq`
- Apply spread method to `t`, then interpolate stops

## RadialGradient

```php
class RadialGradient implements Paint {
    public function __construct(
        public readonly float $cx,
        public readonly float $cy,
        public readonly float $r,
        public readonly array $stops,       // ColorStop[]
        public readonly ?float $fx = null,  // focal point x, defaults to cx
        public readonly ?float $fy = null,  // focal point y, defaults to cy
        public readonly SpreadMethod $spreadMethod = SpreadMethod::Pad,
    ) {}
}
```

- At least 2 stops required
- `r` must be > 0, throws `InvalidArgumentException` if `r <= 0`
- Focal point defaults to `(cx, cy)` when `fx`/`fy` are null
- If focal point is provided, must be inside the circle: `sqrt((fx-cx)^2 + (fy-cy)^2) < r`, throws `InvalidArgumentException` if not

**t computation:**
- `dx = x - fx`, `dy = y - fy`, `dist = sqrt(dx*dx + dy*dy)`
- `t = dist / r`
- Apply spread method to `t`, then interpolate stops

## Spread Method Logic

Given raw `t` (may be outside [0,1]):

**Pad:** `t = max(0.0, min(1.0, t))`

**Reflect:** `t = abs(t)`. If `floor(t)` is odd, `t = 1.0 - fract(t)`, else `t = fract(t)`.

**Repeat:** `t = fract(t)` (where `fract(t) = t - floor(t)`).

## Stop Interpolation

Given `t` in [0,1] after spread method:

1. If `t <= stops[0].offset`, return `stops[0]` color
2. If `t >= stops[last].offset`, return `stops[last]` color
3. Find adjacent stops `a`, `b` where `a.offset <= t <= b.offset`
4. If `a.offset == b.offset` (duplicate), return `b` color (SVG convention: later stop wins)
5. `localT = (t - a.offset) / (b.offset - a.offset)`
6. `r = round(a.r + (b.r - a.r) * localT)` (same for g, b), clamp to [0, 255]

## API Changes

### StrokeStyle
- `public readonly Color $color` becomes `public readonly Paint $paint`
- Constructor parameter renamed: `Color $color` → `Paint $paint`
- Backward-compatible: `Color` implements `Paint`, so `new StrokeStyle(new Color(5))` still works

### Canvas::drawPath()
- `?Color $fillColor` → `?Paint $fill`
- `?StrokeStyle $stroke` unchanged (paint is inside StrokeStyle)

### Canvas::drawPoint()
- `Color $color` → `Paint $paint`
- Non-solid: calls `getColorAt($x, $y)` + quantize per pixel

### Canvas::drawLineInternal()
- `Color $color` → `Paint $paint`
- Non-solid: each Bresenham pixel gets its own gradient color

### Canvas::fillPolygonScanlineMulti()
Receives `Paint` instead of `Color`. The `fillSpan` closure:
- If `$paint->isSolid()` and it's a `Color`: use `$color->fg` / `$color->bg` directly (fast path)
- Otherwise: per-pixel `getColorAt()` + `IrcPalette::nearestColor()` → set `->fg`, `->bg = null`

### Methods unchanged (keep `Color` parameter):
- `fillColor()` — flood fill; gradient target doesn't make sense
- `overlay()` — pixel-level copy, no color computation
- `drawLine()`, `drawFilledEllipse()`, `drawEllipse()`, `drawPolygon()` — pre-Path API; users wanting gradients use `drawPath()`

## Stroke Gradient Behavior

Gradients on strokes evaluate at each pixel's canvas position (SVG semantics). The gradient coordinate space is not path-relative.

- **Width=1:** `drawLineInternal` visits each pixel, gradient evaluates per-pixel.
- **Width>1:** `expandStrokePolygon` produces a fillable polygon, gradient evaluates per-pixel across the polygon via `fillPolygonScanlineMulti`.

A horizontal linear gradient across a thick vertical stroke shows color variation across the stroke's width. A radial gradient shows concentric rings clipped to the stroke shape.

## New Files

| File | Purpose |
|------|---------|
| `library/draw/Paint.php` | Interface |
| `library/draw/ColorStop.php` | Value object for gradient stops |
| `library/draw/SpreadMethod.php` | Enum |
| `library/draw/LinearGradient.php` | Linear gradient paint |
| `library/draw/RadialGradient.php` | Radial gradient paint |
| `tests/Canvas/PaintTest.php` | Paint interface compliance tests |
| `tests/Canvas/GradientTest.php` | Gradient math and spread method tests |

## Modified Files

| File | Change |
|------|--------|
| `library/draw/Color.php` | Implement `Paint` interface |
| `library/draw/StrokeStyle.php` | `Color $color` → `Paint $paint` |
| `library/draw/Canvas.php` | `drawPath`, `drawPoint`, `drawLineInternal`, `fillPolygonScanlineMulti` accept `Paint` |
| `tests/Canvas/CanvasTest.php` | Update StrokeStyle constructor calls |

## Test Strategy

### PaintTest
- `Color` implements `Paint`, `isSolid()` returns true, `getColorAt()` returns consistent RGB
- `LinearGradient` / `RadialGradient`: `isSolid()` returns false
- `getColorAt()` returns valid [0,255] for all positions including out-of-bounds

### GradientTest
- Linear: horizontal gradient midpoint produces expected interpolated color
- Linear: vertical and diagonal gradients
- Linear: degenerate vector returns first stop
- Radial: center returns first stop, edge returns last stop
- Radial: focal point offset shifts gradient origin
- Radial: focal point outside circle throws
- Spread pad: t < 0 clamps to first stop, t > 1 clamps to last
- Spread reflect: t=1.3 → 0.7, t=2.3 → 0.3, t=-0.2 → 0.2
- Spread repeat: t=1.3 → 0.3, t=-0.3 → 0.7
- Stop interpolation: 2 stops, 3 stops, duplicate offset (sharp edge)
- Validation: bad offset, bad RGB, <2 stops, r <= 0, focal point outside circle

### CanvasTest (integration)
- `drawPath` with LinearGradient fill — pixels get different IRC codes along gradient
- `drawPath` with RadialGradient fill — center vs edge colors differ
- `drawPath` with gradient stroke (width=1 and width>1) — stroke pixels have gradient colors
- All 186 existing tests pass unchanged
