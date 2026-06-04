# `@rain` Command Design

## Summary

A new art bot command that renders multiple randomly-scaled copies of a user-provided SVG "falling" through a generated sky scene. Fixed canvas 100x120 characters, 3x supersample, no options.

## Command Interface

- **Name:** `rain`
- **Syntax:** `@rain <url>`
- **File:** `artbot_scripts/rain.php`
- **Registered in:** `artbots.php` via `require_once`
- **No options** — all parameters are fixed or randomized internally.

## Rendering Pipeline

### 1. Fetch & Parse SVG

Same pattern as the existing `@svg` command (`artbot_scripts/svg.php`):
- Validate URL (http/https only)
- HTTP GET with 2MB body limit via Amp HTTP client
- Check HTTP 200 status
- Verify content is SVG via content-type header or `<svg` tag presence
- Parse with `SVGParser::parseString($body, $bot->log)`
- Extract SVG dimensions from `getWidth()`/`getHeight()` or `getViewBox()`

### 2. Canvas Setup

- **Display size:** 100 wide x 120 high (halfblocks enabled)
- **Supersample factor:** 3x → internal render size 300x360
- Create canvas: `Canvas::createBlank(300, 360, true)`

### 3. Sky Background

Drawn directly onto the supersampled canvas:

**Sky gradient:**
- A `LinearGradient` from top (y=0) to bottom (y=360) using one of 3-5 predefined sky palettes, picked randomly each invocation.
- Palettes: sunrise, midday, sunset, golden hour, twilight — each defined as an array of `[stop_position, r, g, b]` values.

**Sun:**
- A `RadialGradient` disc positioned in the upper portion of the canvas (y roughly 30-80 in supersampled space).
- Center is bright white/yellow, edges fade into the sky gradient color.

**Clouds:**
- 2-4 simple cloud shapes, each composed of 2-3 overlapping `Path::ellipse` fills.
- White or very light gray (`Color(0, null)` or similar).
- Randomly positioned across the upper 60% of the canvas.
- Each cloud ellipse has slight size variation for organic feel.

### 4. Generate SVG Copies

- **Count:** Random integer between 5 and 8 inclusive.
- **Scale:** For each copy, pick a random scale factor such that the copy's width is between 20% and 60% of the canvas width (60-180 pixels in supersampled space). Aspect ratio is preserved from the original SVG dimensions.
- **Sort:** Sort all copies by scale ascending (smallest drawn first = furthest away).

### 5. Placement with Overlap Avoidance

For each copy (in sorted order):

1. Compute its bounding box: `(w, h)` based on scale factor and original SVG aspect ratio.
2. Generate a random `(x, y)` position within canvas bounds (allowing partial overflow up to 20% of copy size on any edge).
3. Check overlap against all already-placed copies: compute intersection area. If `intersection_area / min(copy_area, placed_area) > 0.5`, reject and try a new position.
4. Cap at 20 attempts; on failure, allow the position anyway (graceful degradation).
5. Record the accepted position.

### 6. Render Each SVG Copy

For each copy (smallest first):

1. Create a temp canvas sized to the copy's `(w, h)` with halfblocks.
2. Apply SVG viewBox transform to map SVG coordinate space to the temp canvas size.
3. Call `$doc->render($tempCanvas)` to render the SVG onto the temp canvas.
4. Composite the temp canvas onto the main canvas at position `(x, y)` by copying non-null pixels.

### 7. Motion Lines

For each SVG copy, draw 3-5 short motion/speed lines above it:

- Lines originate from points along the top edge of the copy's bounding box.
- They extend upward (negative Y) with slight random horizontal spread.
- Line length is proportional to copy scale (larger copies get longer lines).
- Color: white or light sky color with slight transparency (opacity ~0.5-0.7).
- Drawn using `Path::line` + `StrokeStyle` with a thin width (1-2 pixels in supersampled space).
- Only draw motion lines for copies in the lower ~70% of the canvas (lines on very high copies would be off-screen).

### 8. Resample & Output

- `$canvas->resampleTo(100, 120)` to downscale from 300x360 to 100x120.
- Convert to string, split on newlines, `pumpToChan($bot, $args->chan, $lines)`.

### 9. Error Handling

Same pattern as `@svg`:
- Invalid URL → notice with usage
- Non-200 HTTP → notice with status
- Non-SVG content → notice
- Parse failure → notice
- Generic exception → notice with message

## Sky Palettes

Five predefined palettes:

| Name      | Top color         | Mid color          | Bottom color       |
|-----------|-------------------|--------------------|--------------------|
| Sunrise   | Deep indigo       | Warm orange        | Pale gold          |
| Day       | Deep blue         | Medium blue        | Light blue         |
| Sunset    | Dark purple       | Hot pink/orange    | Amber              |
| Golden    | Warm amber        | Gold               | Pale yellow        |
| Twilight  | Dark navy         | Deep teal          | Muted purple-blue  |

Each palette has 3-4 gradient stops with specific RGB values.

## Dependencies

- `draw\Canvas` — canvas creation, drawing, resampling
- `draw\SVGParser` — SVG parsing
- `draw\SVGDocument` — SVG rendering
- `draw\Path` — shape primitives (rect, ellipse, line)
- `draw\Color` — IRC palette colors
- `draw\LinearGradient` / `draw\RadialGradient` — sky/sun gradients
- `draw\ColorStop` — gradient stop definitions
- `draw\StrokeStyle` — motion line styling
- `knivey\cmdr\attributes\Cmd`, `Syntax` — command registration
- `Amp\Http\Client\HttpClientBuilder` — HTTP fetching
- `pumpToChan()` — output helper (global function from art-common.php)

## File Structure

```
artbot_scripts/rain.php          ← New file (the command)
artbots.php                       ← Add require_once for rain.php
```
