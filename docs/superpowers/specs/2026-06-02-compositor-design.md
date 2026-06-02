# Compositor Design

## Goal

Add alpha compositing (source-over) to `library/draw/` so that rendering operations
can be done at partial opacity. This unblocks stroke-opacity, fill-opacity, and
future group opacity.

## Design Decisions

- **Source-over only** — the standard SVG default blend mode. No multiply,
  screen, overlay, etc. YAGNI for IRC art.
- **Separate Compositor class** — rendering code stays oblivious to alpha. The
  Compositor owns all blending logic.
- **Render full, composite after** — draw to a temp canvas at full opacity, then
  composite onto the destination with a given opacity. Clean separation.
- **Separate fg/bg alpha** — in half-block mode, fg (upper half) and bg (lower
  half) are visually independent elements. They need independent alpha for
  correct compositing.
- **Blend in RGB, quantize back** — IRC color codes are indices, not colors. The
  compositor converts src/dst codes → RGB, blends in RGB space, then quantizes
  the result back to the nearest IRC color code.

## Changes

### Pixel — add alpha channels

```php
class Pixel {
    public ?int $fg = null;
    public ?int $bg = null;
    public float $fgAlpha = 1.0;
    public float $bgAlpha = 1.0;
    public string $text = ' ';
}
```

- `fgAlpha` and `bgAlpha` range `[0.0, 1.0]`, default `1.0` (fully opaque).
- A pixel with `fg = null` is still "empty" regardless of `fgAlpha`. Alpha only
  matters when the corresponding color is non-null.
- Backward compatible: existing code creates Pixels with full opacity by default.

### IrcPalette — RGB lookup + nearest-color matching

A new class `draw\IrcPalette` providing:

```php
class IrcPalette {
    /** Get RGB values for an IRC color code (0–98). */
    public static function getRgb(int $ircCode): array;

    /** Find the nearest IRC color code for an arbitrary RGB value. */
    public static function nearestColor(int $r, int $g, int $b): int;

    /** Get the Itwmw\ColorDifference\Color object for an IRC code. */
    public static function getColor(int $ircCode): Color;
}
```

- Static methods. Palette built once via lazy static initialization.
- The palette data is the same 99 RGB entries (indices 0–98) used in
  `artbot_scripts/urlimg.php`.
- `nearestColor()` uses Din99 color-space distance by default (same default as
  the `!ascii` command). Uses the `Itwmw\ColorDifference` package, which is
  already a project dependency.
- Reference implementation: `getClosestMatchDin99()` in
  `artbot_scripts/urlimg.php` lines 497–512.

### Compositor — source-over blending

A new class `draw\Compositor` with one core method:

```php
class Compositor {
    /**
     * Source-over composite: blend $src onto $dst at the given opacity.
     *
     * For each pixel, fg and bg are blended independently:
     * 1. Skip if src half has no color (e.g. src fg is null → skip fg blend)
     * 2. If dst half has no color, copy src color directly (no blend needed)
     * 3. Both present:
     *    a. Look up src IRC code → RGB, dst IRC code → RGB (via IrcPalette)
     *    b. Compute effective alpha: src pixel alpha × $opacity param
     *    c. Blend: resultRGB = srcRGB × effectiveAlpha + dstRGB × (1 − effectiveAlpha)
     *    d. Quantize resultRGB → nearest IRC color code (via IrcPalette)
     *    e. Write result to dst pixel, set dst alpha to 1.0
     *
     * Text: if src pixel has non-default text (not ' '), overwrite dst text.
     * Text is not blended — it is binary.
     *
     * @throws \InvalidArgumentException if canvases have different dimensions
     */
    public static function blend(Canvas $dst, Canvas $src, float $opacity = 1.0): void;
}
```

**Size constraint:** `$dst` and `$src` must have the same dimensions. Throws
`\InvalidArgumentException` on mismatch (same contract as `Canvas::overlay()`).

**Alpha resolution:** After blending, destination pixel alpha values are reset
to `1.0`. The compositor resolves alpha into a concrete IRC color — destination
pixels are always fully opaque after compositing. Only source pixels carry
meaningful alpha (set by whatever rendered them).

**When `$opacity` is 1.0 and all source pixel alphas are 1.0:** equivalent to
the current `Canvas::overlay()` behavior (copy non-null pixels). The compositor
is a superset of overlay.

### Canvas — no changes to rendering methods

`drawPath`, `fillPolygonScanline`, `drawLineInternal`, `drawPoint`, etc. remain
unchanged. They write pixels at full opacity (alpha stays at default 1.0).
Rendering is always full color; compositing is a separate step.

`overlay()` stays as-is for the fast copy path. When alpha blending is needed,
use `Compositor::blend()` instead:

```php
// Fast copy, no blending (existing):
$canvas->overlay($otherCanvas);

// Alpha compositing:
Compositor::blend($canvas, $otherCanvas, 0.5);
```

`__toString()` is unchanged — it reads `fg`/`bg` color codes and emits IRC
color codes. Composited pixels are fully opaque with resolved IRC colors.

## Usage Pattern — stroke-opacity (future)

This compositor is a prerequisite for adding stroke-opacity to StrokeStyle. The
pattern will be:

```php
// Render stroke to a temporary canvas at full color
$strokeCanvas = Canvas::createBlank($w, $h);
$strokeCanvas->drawPath($path, null, $strokeStyle);

// Composite onto main canvas at the desired opacity
Compositor::blend($canvas, $strokeCanvas, $strokeStyle->opacity);
```

Same pattern applies for fill-opacity and group opacity.

## Out of Scope

- **stroke-opacity / fill-opacity** — next task, depends on this compositor
- **Group opacity** — needs scene tree / groups (much later in roadmap)
- **Blend modes beyond source-over** — YAGNI
- **Dithering during quantization** — single nearest-color only; can be added
  later for gradients
