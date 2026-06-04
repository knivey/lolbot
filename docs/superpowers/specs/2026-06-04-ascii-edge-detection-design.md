# @ascii Sobel Edge Detection

## Scope

Normal mode only. Not applied when `--halfblock`, `--block`, or text/word rendering is active.

## Algorithm

### 1. Luminance Map

After `exportImagePixels()`, compute Rec.601 luminance per pixel:

```
L = 0.299 * R + 0.587 * G + 0.114 * B
```

Values are in 0-255 range (raw byte values from `PIXEL_CHAR`).

### 2. Sobel Convolution

Apply 3x3 Sobel kernels on the sample image (`sampleW x sampleH`):

**Gx kernel:**
```
-1  0  +1
-2  0  +2
-1  0  +1
```

**Gy kernel:**
```
-1  -2  -1
 0   0   0
+1  +2  +1
```

Skip border pixels (row 0, last row, col 0, last col) — they default to 0.0 in the gradient arrays.

### 3. Block Aggregation

For each 8x8 output block, sum the Gx and Gy values over all 64 pixels:

```
sumGx = Σ gx[y][x]  for all pixels in block
sumGy = Σ gy[y][x]  for all pixels in block
magnitude = sqrt(sumGx² + sumGy²) / 64
angle = atan2(sumGy, sumGx) * 180 / π   (normalize to 0-180)
```

Summing vectors (not magnitudes) gives the dominant gradient direction and naturally cancels random noise.

### 4. Threshold and Character Selection

Default threshold: **40.0** (block-averaged magnitude).

- If `magnitude > 40.0`: select directional edge character based on gradient angle
- Else: fall through to existing `render($luminosity)` luminosity character

**Angle-to-character mapping** (gradient direction, normalized to 0-180):

| Angle range | Gradient direction | Edge direction | Character |
|-------------|-------------------|----------------|-----------|
| 0-22.5 or 157.5-180 | Horizontal | Vertical | `|` |
| 22.5-67.5 | NE/SW | NW/SE | `\` |
| 67.5-112.5 | Vertical | Horizontal | `=` |
| 112.5-157.5 | NW/SE | NE/SW | `/` |

### 5. Integration Point

In `urlimg.php`, the normal-mode branch (line ~351, `else` block after `isset($words)` check):

```php
// Before:
$str_char = render($luminosity);

// After:
$edgeChar = computeEdgeChar($gxMap, $gyMap, $srcX0, $srcY0, $blockSize, $sampleW, $blockPixels);
$str_char = $edgeChar ?? render($luminosity);
```

### 6. Performance

- Two Sobel convolutions: ~2 x 200K multiply-adds for typical 640x320 sample image
- atan2 per block: ~3200 calls for 80x40 output
- Total overhead: <50ms on top of existing ~1.1s pipeline

### 7. Character Set

Edge chars: `| \ = /`
Luminosity chars: ` . ' ` : - ~ + = ! * ? / ^ # % $ & @ W`

Note: `=` and `/` appear in both sets. This is fine — when an edge is detected, the directional meaning takes precedence over luminosity shading.

### 8. Threshold Tuning

The default of 40.0 is calibrated for block-averaged Sobel magnitude on 8-bit luminance. Expected values:

- Strong edge across a block: 50-200 average magnitude
- Soft/gradient edge: 20-80
- Flat/noise regions: 0-10

If needed, the threshold can be made into a `--edge-threshold` flag later without design changes.
