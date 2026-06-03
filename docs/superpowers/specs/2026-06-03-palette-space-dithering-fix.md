# Fix: Palette-Space Ordered Dithering

## Problem

The current ordered dithering adds a uniform RGB offset to all channels before palette quantization. This has two bugs:

1. **One-sided band dithering:** The ±15 offset only crosses palette boundaries when the input is near a boundary. In the center of a band, the offset is too small to reach any neighbor, so no dithering happens. Result: dithering appears only on the edge of each band where the input is transitioning, not uniformly throughout.

2. **No effect on bright/saturated colors:** Bright colors clamp at 255, saturated colors only shift the dominant channel. A color like (200,200,50) gets zero dithering effect because both R and G clamp.

## Solution

Replace the RGB-space additive offset with palette-space threshold dithering:

1. Find the **two nearest** palette colors (Din99 distance)
2. Compute the **interpolation fraction** `t` = `dist(input, best) / dist(best, second_best)`, clamped to [0, 1]
   - `t ≈ 0` → input is very close to the best match, strongly prefer it
   - `t ≈ 1` → input is equidistant between both, 50/50 split
3. Compare `t` against the normalized Bayer threshold: `threshold = ($bayer + 0.5) / 16.0`
   - If `t < threshold` → pick the best (nearest) palette color
   - If `t ≥ threshold` → pick the second-best palette color

This guarantees:
- Every pixel in a gradient band has a chance to dither between the two adjacent palette colors
- The dithering pattern is uniform across the band (not clustered on one edge)
- No RGB clamping issues — the decision is purely in palette-distance space

## Changes

### `IrcPalette` — replace dithering logic in `nearestColor`

Remove `DITHER_STRENGTH` constant (no longer needed). Keep `BAYER_4X4`.

When `Dithering::Ordered4x4`:
1. Compute Din99 distance from input to ALL palette entries
2. Find the best (smallest distance) and second-best entries
3. Compute `t = bestDist / (bestDist + secondBestDist)` (0 = close to best, 1 = equidistant)
4. Look up Bayer threshold: `($bayer + 0.5) / 16.0` (maps 0-15 to 0.03125-0.96875)
5. If `t >= threshold`, return second-best; otherwise return best

The `nearestColor` method already iterates the full palette to find the best match. The dithering version just needs to track the second-best too. Cache key remains the RGB values (no dithering state in the key since the dithered result depends on position).

**Important:** The cache must NOT be used for dithered lookups — the same RGB at different positions produces different results. The dithered path skips the cache entirely.

### No other files change

The Canvas, Paint, Scene tree, and demo code all pass dithering params through correctly already. Only the quantization logic inside `IrcPalette::nearestColor` changes.

## Tests

Update the dithering tests in `IrcPaletteTest` — the existing tests should still pass since they test:
- Default is no dithering ✓ (unchanged)
- Dithering changes results for some pixels ✓ (still true, different mechanism)
- Clamps to valid range ✓ (still returns palette codes 0-98)
- Position-dependent ✓ (still true)
- Wraps at 4x4 ✓ (still true)

The Bayer center test may need adjustment since the threshold values change.
