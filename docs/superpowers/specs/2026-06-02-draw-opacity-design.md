# Drawing Opacity Design

## Goal

Add stroke-opacity, fill-opacity, and element-level opacity to `drawPath` using the
render-to-temp-canvas + composite pattern provided by the Compositor built in the
previous milestone.

## Design Decisions

- **Separate parameters** — stroke-opacity on `StrokeStyle`, `$fillOpacity` and
  `$opacity` as params on `drawPath`. Matches SVG's three separate properties.
- **Branching inside drawPath** — when all opacities are 1.0 (the default), use the
  same fast path as today with no temp canvas allocation. When any opacity < 1.0,
  render to a temp canvas and composite.
- **SVG compositing semantics** — element `$opacity` multiplies with fill/stroke
  opacity. A `fillOpacity=0.5` with `opacity=0.5` gives an effective fill opacity
  of 0.25.

## Changes

### StrokeStyle — add opacity property

```php
public function __construct(
    public readonly Color $color,
    public readonly float $width = 1.0,
    public readonly ?array $dashArray = null,
    public readonly float $dashOffset = 0.0,
    public readonly LineCap $lineCap = LineCap::Butt,
    public readonly LineJoin $lineJoin = LineJoin::Miter,
    public readonly float $miterLimit = 4.0,
    public readonly float $opacity = 1.0,
) {
```

- Range `[0.0, 1.0]`, default `1.0` (fully opaque).
- Clamp to `[0.0, 1.0]` on construction.
- Backward compatible — all existing StrokeStyle usage gets full opacity.

### drawPath — add fillOpacity and opacity parameters

```php
public function drawPath(
    Path $path,
    ?Color $fillColor,
    ?StrokeStyle $stroke,
    string $text = '',
    FillRule $fillRule = FillRule::NonZero,
    float $fillOpacity = 1.0,
    float $opacity = 1.0,
): void {
```

- `$fillOpacity` — opacity for the fill only (SVG `fill-opacity`). Default `1.0`.
- `$opacity` — element-level opacity, applies to the entire rendered element (SVG
  `opacity`). Default `1.0`.
- Both clamped to `[0.0, 1.0]`.

### Rendering Logic

```
if $opacity < 1.0:
    // Element opacity: render everything to temp, composite once
    $temp = Canvas::createBlank($this->w, $this->h, $this->halfblocks)
    render fill to $temp at $fillOpacity
    render stroke to $temp at $stroke->opacity
    Compositor::blend($this, $temp, $opacity)
else:
    if $fillOpacity < 1.0:
        $temp = Canvas::createBlank($this->w, $this->h, $this->halfblocks)
        render fill to $temp
        Compositor::blend($this, $temp, $fillOpacity)
    else:
        render fill directly to $this

    if $stroke !== null && $stroke->opacity < 1.0:
        $temp = Canvas::createBlank($this->w, $this->h, $this->halfblocks)
        render stroke to $temp
        Compositor::blend($this, $temp, $stroke->opacity)
    else:
        render stroke directly to $this
```

When `$opacity < 1.0`, the fill and stroke opacities are applied within the temp
canvas render. This means `$fillOpacity` and `$stroke->opacity` are passed through
to the inner render calls, and then the whole result is composited at `$opacity`.

When `$opacity == 1.0`, fill and stroke are handled independently, each getting
their own temp canvas only if needed.

When all opacities are `1.0`: same fast path as today — no temp canvas, no
compositor call.

The temp canvas inherits `$this->halfblocks` so half-block rendering works
correctly.

### Usage Examples

**50% transparent stroke:**
```php
$stroke = new StrokeStyle(new Color(4, null), width: 2.0, opacity: 0.5);
$canvas->drawPath($path, null, $stroke);
```

**50% transparent fill:**
```php
$canvas->drawPath($path, new Color(0, null), null, '', FillRule::NonZero, 0.5);
```

**50% transparent everything:**
```php
$canvas->drawPath($path, new Color(0, null), $stroke, '', FillRule::NonZero, 1.0, 0.5);
```

**Combined: 70% fill + 40% stroke at 50% element opacity:**
```php
$stroke = new StrokeStyle(new Color(4, null), opacity: 0.4);
$canvas->drawPath($path, new Color(0, null), $stroke, '', FillRule::NonZero, 0.7, 0.5);
// Effective fill: 0.7 * 0.5 = 0.35, effective stroke: 0.4 * 0.5 = 0.2
```

## Out of Scope

- **Group opacity** — needs scene tree / `<g>` elements (later milestone)
- **Per-pixel alpha from drawPath** — rendering always writes at alpha=1.0; opacity
  only controls the composite step
- **Changes to other draw methods** — `drawPoint`, `fillColor`, `overlay` unchanged;
  only `drawPath` gets opacity support
