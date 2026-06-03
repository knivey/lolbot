# Shader Block Dithering Design

## Problem

Halfblock mode with ordered dithering can exceed IRC line length limits. An 80-column dithered halfblock line reaches ~720 bytes worst case (every pixel pair changes both fg and bg), but IRC allows only ~457 bytes of content. Solid art (lines, shapes with flat colors) is fine — long color runs compress well. The explosion only happens in gradient/dithered regions where every pixel has a different color.

## Solution: Hybrid Shader Block Dithering

When both pixels in a halfblock pair would be dithered, replace the `▀` character with a shade character (`░▒▓`) that visually blends the two pixels' best palette colors. Solid pixels (exact palette match, part of clean lines/shapes) keep `▀` to preserve sharp resolution.

### Shade Characters

| Char | Codepoint | Coverage | Blend level |
|------|-----------|----------|-------------|
| ░    | U+2591    | 25%      | Light shade |
| ▒    | U+2592    | 50%      | Medium shade |
| ▓    | U+2593    | 75%      | Dark shade |

These blend the fg color into the bg color at the glyph level. The IRC client's font rendering determines the actual visual blend.

### Pixel Metadata

Add dithering metadata to `Pixel`:

- `bool $dithered = false` — whether this pixel was dithered (not an exact palette match, and dithering was active)
- `int $secondBest = -1` — the second-best palette code (the one NOT chosen by the Bayer threshold)
- `float $t = 0.0` — the RGB-space projection fraction (0.0–1.0) toward second-best

These are set during quantization in `Canvas` when `IrcPalette::nearestColor()` is called with a dithering mode. A new method `IrcPalette::nearestColorWithMeta()` returns a value object with `code`, `secondBest`, `t`, and `dithered` fields instead of just an int.

### Rendering Logic

In `Canvas::__toString()` halfblock path, for each pixel pair (top=pixel1, bottom=pixel2):

1. **Both pixels dithered** → emit shade character:
   - fg = pixel1's best palette code (already stored in `pixel1->fg`)
   - bg = pixel2's best palette code (already stored in `pixel2->fg`)
   - Average the two `t` values → `░` if < 0.33, `▒` if < 0.66, `▓` if ≥ 0.66
   - This produces the same fg/bg color codes as current halfblocks, just a different character

2. **Either pixel not dithered** → current `▀` / space behavior unchanged

3. **Neither pixel has fg** → current space behavior unchanged

### Line Length Impact

Shade characters are 3 bytes UTF-8 (same as `▀`). Color codes are identical. The only change is the character glyph — line lengths remain exactly the same as non-dithered halfblock output. No truncation risk.

### Scope

- `Pixel` — add `$dithered`, `$secondBest`, `$t` fields
- `IrcPalette` — add `nearestColorWithMeta()` method returning a result value object
- `Canvas` — call `nearestColorWithMeta()` instead of `nearestColor()` when dithering is active, store metadata on pixels
- `Canvas::__toString()` — check pixel dithering metadata to choose shade char vs `▀`
- No changes to Paint, gradient, or scene tree code

### Out of Scope

- Standalone shader-block-only render mode (no halfblocks at all)
- Sub-pixel rendering or anti-aliasing
- Custom shade character sets
- Non-halfblock rendering path changes
