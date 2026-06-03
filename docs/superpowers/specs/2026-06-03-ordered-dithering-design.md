# Ordered Dithering for Gradient Paints

## Problem

Gradients rendered to IRC's 99-color palette show visible banding — contiguous regions of the same IRC color with hard edges between them. This is especially noticeable on smooth linear/radial gradients.

## Solution

Add ordered (Bayer matrix) dithering to the color quantization step. Before finding the nearest IRC palette color, add a position-dependent noise offset to the RGB values. This breaks up uniform bands by pushing borderline pixels to the neighbor color, producing a textured transition instead of a hard edge.

## Approach

Dithering lives in `IrcPalette::nearestColor()` — the single quantization path used by all canvas rendering. This avoids duplicating noise+quantize logic across the 3 rendering sites in Canvas.

## Components

### `Dithering` enum

File: `library/draw/Dithering.php`

- `None` — no dithering (default)
- `Ordered4x4` — 4x4 Bayer matrix dithering

### `IrcPalette` changes

File: `library/draw/IrcPalette.php`

- `nearestColor()` gains optional params: `Dithering $mode = Dithering::None, int $x = 0, int $y = 0`
- A `BAYER_4X4` constant holds the 4x4 threshold matrix
- When mode is `Ordered4x4`:
  1. Look up `BAYER_4X4[$y % 4][$x % 4]` → value in [0, 15]
  2. Normalize to `[-1.0, +1.0]` range: `($val - 7.5) / 8.0`
  3. Multiply by strength (fixed at 16.0 for 99-color IRC palette)
  4. Add offset to each RGB channel, clamp to [0, 255]
  5. Proceed with normal palette search
- Existing calls without the new params continue to work (default `None`)

### `Canvas` changes

File: `library/draw/Canvas.php`

- New property: `private Dithering $dithering = Dithering::None`
- Setter: `setDithering(Dithering $mode): void`
- Getter: `getDithering(): Dithering`
- All 3 quantization sites (`drawPoint`, `drawLineInternal`, `fillPolygonScanlineMulti` fill span) pass `$this->dithering, $x, $y` to `nearestColor()`
- Solid-color fast path unchanged — dithering a solid color has no useful effect
- When paint has a dithering override, use `$paint->getDithering() ?? $this->dithering`

### `Paint` interface changes

File: `library/draw/Paint.php`

- New method: `getDithering(): ?Dithering`
  - Returns `null` to use canvas default, or a specific `Dithering` value to override

### `Color` changes

File: `library/draw/Color.php`

- `getDithering()` returns `null` — solid colors never need dithering

### `LinearGradient` changes

File: `library/draw/LinearGradient.php`

- New constructor param: `?Dithering $dithering = null`
- `getDithering()` returns `$this->dithering`

### `RadialGradient` changes

File: `library/draw/RadialGradient.php`

- New constructor param: `?Dithering $dithering = null`
- `getDithering()` returns `$this->dithering`

### Scene tree integration

Files: `RenderContext.php`, `Shape.php`, `Group.php`

- `RenderContext` gets `?Dithering $dithering` property
  - `merge()` follows same pattern as other properties: child's non-null value overrides parent's
- `Shape` and `Group` get optional `?Dithering $dithering` constructor param
- At render time, resolved dithering is passed to canvas

## Bayer 4x4 Matrix

```
[ [  0,  8,  2, 10 ],
  [ 12,  4, 14,  6 ],
  [  3, 11,  1,  9 ],
  [ 15,  7, 13,  5 ] ]
```

Standard ordered dithering matrix. Values range 0–15, centered at 7.5.

## Dithering Strength

Fixed at 16.0 (out of 255). This means each channel can shift by up to ±16, which is enough to push borderline pixels across palette boundaries without introducing excessive noise. The value can be made configurable later if needed.

## API Usage

```php
// Canvas default — all gradients dithered
$canvas->setDithering(Dithering::Ordered4x4);
$canvas->drawPath($path, $gradient, $stroke);

// Per-gradient override — this gradient dithers regardless of canvas default
$ditheredGrad = new LinearGradient(0, 0, 80, 0, $stops, dithering: Dithering::Ordered4x4);

// Per-gradient override — disable dithering for this gradient even if canvas defaults to it
$smoothGrad = new LinearGradient(0, 0, 80, 0, $stops, dithering: Dithering::None);

// Scene tree
$shape = new Shape($path, fill: $grad, dithering: Dithering::Ordered4x4);
$group = new Group(children: [...], dithering: Dithering::Ordered4x4);
```

## What's NOT in scope

- Floyd-Steinberg (error-diffusion) dithering — requires two-pass rendering incompatible with current single-pass rasterization
- Configurable dithering strength — fixed at 16.0 for now
- Larger Bayer matrices (8x8) — marginal improvement at IRC resolution
- Dithering solid colors — only applies to gradient paints
