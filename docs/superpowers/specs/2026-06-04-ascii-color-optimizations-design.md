# ASCII Command Color Optimization

Port the SVG/draw library's optimized color matching and Lab-space downscaling to the `@ascii` command (`artbot_scripts/urlimg.php`).

## Problem

The `@ascii` command uses brute-force color matching (no caching, no low-lumen fix) and relies solely on Imagick Lanczos for downscaling, which operates in RGB space and can produce hue shifts. The SVG rendering pipeline in `library/draw/` already has:

- **Adaptive Din99/RGB hybrid** with low-lumen fix (L < 25 falls back to Euclidean RGB)
- **Caching** (4096-entry RGB cache, 8192-entry Lab cache)
- **Lab-space supersample downscale** in `Canvas::resampleTo()`

## Decisions

| Decision | Choice |
|---|---|
| Color algorithm | Replace all 4 inline matchers with `IrcPalette` adaptive hybrid |
| Dithering | Not ported |
| Downscaling | 4x oversample + Lab block averaging, on by default |
| Fallback | `--no-downsample` flag skips Lab averaging, uses Imagick resize + `IrcPalette` per-pixel |
| `--16` flag | Kept, limits palette search to indices 0-15 |
| Removed flags | `--quality`, `--lab`, `--rgb` |
| Memory | No Canvas; pixel data stays in Imagick, only final IRC grid in PHP |

## Changes

### `artbot_scripts/urlimg.php`

**Remove:**
- Inline `$palette` array (~100 lines of `Color` objects, lines 139-239)
- `getClosestMatchDin99()`, `getClosestMatchCIEDE2000()`, `getClosestMatchEuclideanLab()`, `getClosestMatchEuclideanRGB()` functions
- `--quality`, `--lab`, `--rgb` flag definitions and handling

**Add:**
- `use library\draw\IrcPalette;` import
- `--no-downsample` flag definition

**Modify the pixel conversion loop:**

Default path (with downsample):
1. Load image into Imagick
2. Apply gamma/brightness/saturation adjustments as before
3. Resize to `targetW * 4` x `targetH * 4` via Imagick Lanczos
4. For each target pixel (tx, ty):
   - Source block is pixels `[(tx*4)..(tx*4+3), (ty*4)..(ty*4+3)]` in the intermediate image
   - Read each pixel RGB via `$img->getImagePixelColor($sx, $sy)`
   - Convert RGB to Lab, accumulate L/a/b sums
   - Call `IrcPalette::nearestColorFromLab(avgL, avga, avgb)`
   - If `--16`, call `IrcPalette::nearestColorFromLab(avgL, avga, avgb, true)` to limit search

`--no-downsample` path:
1. Load image, apply adjustments
2. Resize directly to target dimensions via Imagick Lanczos (current behavior)
3. For each pixel, call `IrcPalette::nearestColor(r, g, b, Dithering::None, x, y)`
   - If `--16`, call with a limit parameter

**Keep unchanged:**
- Image fetching, temp file handling
- `--width`, `--halfblock`, `--block`, `--saturation`, `--brightness`, `--gamma`, `--render2`, `--edit`, `--16`
- Halfblock rendering, character selection, IRC color code emission

### `library/draw/IrcPalette.php`

Add `$limit16 = false` parameter to:
- `nearestColorFromLab(float $L, float $a, float $b, bool $limit16 = false): int`
- `nearestColor(int $r, int $g, int $b, Dithering $mode, int $x, int $y, bool $limit16 = false): int`

When `$limit16` is true, only iterate palette indices 0-15 instead of 0-98.

## Performance

- 4x oversample on 80x54 target: 320x216 intermediate = ~69K pixels
- Each pixel: `getImagePixelColor()` + RGB->Lab + cache lookup
- IrcPalette caching means repeated colors hit cache quickly
- No Canvas allocation; only the final ~4K-element IRC grid lives in PHP memory
