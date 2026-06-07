# SVG Text Rendering Design

## Goal

Add SVG `<text>` and `<tspan>` element support to the draw library's SVG pipeline, rendering glyph outlines as Path objects through the full existing rendering pipeline (fill, stroke, gradient, transform, clip, filter). Fonts are loaded from the system via fontconfig and parsed with `dompdf/php-font-lib`.

## Architecture

```
SVG <text> element
       │
       ▼
  SVGParser ──► TextNode (scene tree leaf)
       │
       ▼
  FontManager::resolveFont(font-family, weight, style)
       │
       ├─ fc-match ──► /path/to/font.ttf
       │
       ▼
  FontFile (php-font-lib wrapper)
       │
       ├─ cmap  ── char → glyph ID
       ├─ glyf  ── glyph ID → outline points
       ├─ hmtx  ── advance widths
       ├─ kern  ── kerning pairs
       └─ head/hhea ── ascent, descent, line height
       │
       ▼
  TextNode::render()
       │
       ├─ Layout chars: advance widths + kerning
       ├─ For each char: extract glyph SVG path → Path object
       ├─ Scale to font-size, translate to position
       ├─ Group → Shape nodes (one per glyph)
       │
       ▼
  canvas->drawPath() per glyph (full pipeline)
```

## Components

### FontManager (`library/draw/FontManager.php`)

Resolves font-family names to font files and caches parsed instances.

```php
class FontManager {
    static function resolve(string $fontFamily, ?string $weight, ?string $style): FontFile;
    static function getDefault(): FontFile;
}
```

- Uses `fc-match` to resolve `font-family` + `font-weight` + `font-style` to a file path:
  ```
  fc-match "DejaVu Sans:weight=bold:style=italic" --format="%{file}"
  ```
- Caches `fc-match` results in a static map keyed by `fontFamily+weight+style`
- Caches loaded `FontFile` instances by file path
- Provides a default font via `fc-match "sans-serif"` when no font-family is specified or resolution fails
- Default font family configurable in `config.yaml` as `defaultFont`

### FontFile (`library/draw/FontFile.php`)

Thin wrapper over `php-font-lib`'s `Font`. Loads a `.ttf`/`.otf` file and exposes glyph data.

```php
class FontFile {
    public readonly float $unitsPerEm;    // from head table
    public readonly float $ascent;        // from hhea
    public readonly float $descent;       // from hhea (negative)
    public readonly float $lineGap;       // from hhea

    function getGlyphPath(string $char): ?Path;
    function getAdvanceWidth(string $char): float;
    function getKerning(string $left, string $right): float;
}
```

- `getGlyphPath()` pipeline:
  1. Resolve char → glyph ID via cmap
  2. Get glyph outline from glyf table
  3. Call `getSVGContours()` to get SVG path data string (`M`, `L`, `Q`, `z`)
  4. Parse the SVG path data string into our `Path` object (reuse the existing SVG `d` attribute parser from `SVGParser`)
  5. Return the `Path` in font units (unscaled — caller handles scaling)
- `getAdvanceWidth()` reads from hmtx table, returned in font units
- `getKerning()` reads from kern table for the glyph pair, returned in font units. Returns 0.0 if no kerning entry
- Composite glyphs (accented characters like é, ñ) are handled natively by php-font-lib — `getSVGContours()` resolves component references and produces a merged outline
- Returns `null` from `getGlyphPath()` for missing glyphs; caller falls back to plain text

### TextNode (`library/draw/TextNode.php`)

Scene tree leaf node for SVG `<text>` elements. Implements `SceneNode`.

```php
class TextNode implements SceneNode {
    public string $text = '';
    public float $x = 0;
    public float $y = 0;
    public ?string $fontFamily = null;
    public float $fontSize = 16;
    public ?string $fontWeight = null;
    public ?string $fontStyle = null;
    public string $textAnchor = 'start';
    public string $dominantBaseline = 'auto';
    public ?Paint $fill = null;
    public ?StrokeStyle $stroke = null;
    public ?Transform $transform = null;
    public float $opacity = 1.0;
    public float $fillOpacity = 1.0;
    public array $tspans = [];

    function getChildren(): array;
    function render(Canvas $canvas, RenderContext $ctx): void;
}
```

`render()` flow:
1. Merge inherited properties from `RenderContext` (fill, stroke, transform, opacity)
2. Resolve font via `FontManager::resolve()`
3. Compute glyph layout:
   - Start at `(x, y)` in user space
   - For each character: place glyph at current pen position, advance by `advanceWidth + kerning`
   - Apply `text-anchor`: if `middle`, shift entire run left by half total width; if `end`, shift left by full width
   - Apply `dominantBaseline`: adjust y by ascent/descent metrics
4. For each character:
   - If `getGlyphPath()` returns a Path: scale from font-units to `fontSize`, translate to pen position, create a `Shape` node with the resolved fill/stroke, render it
   - If `getGlyphPath()` returns null: stamp the raw character into the canvas pixel at the pen position using `drawPoint()` with the fill paint and the character as text
5. `<tspan>` children are rendered inline within the layout with their own local overrides

### TspanNode (`library/draw/TspanNode.php`)

Represents a sub-run within a parent `<text>`.

```php
class TspanNode {
    public string $text = '';
    public ?float $dx = null;
    public ?float $dy = null;
    public ?string $fontFamily = null;
    public ?float $fontSize = null;
    public ?string $fontWeight = null;
    public ?string $fontStyle = null;
    public ?Paint $fill = null;
    public ?StrokeStyle $stroke = null;
}
```

During `TextNode::render()`, tspans are laid out inline — each tspan continues from where the previous run ended, applying its `dx`/`dy` offset and any local style overrides. The parent's cursor advances after each tspan.

## Coordinate Scaling

Font glyphs are defined in font units (typically 2048 units per em). Scale to canvas pixels:

```
scale = fontSize / unitsPerEm
```

Each glyph's Path is in font-unit coordinates with Y increasing upward (standard font convention). Our canvas Y increases downward, so for each glyph:

1. Scale the path by `(scale, -scale)` — flip Y axis
2. Translate to the pen position `(penX, penY)`
3. Pen position accounts for dominantBaseline

Pen advancement:
```
penX += (advanceWidth + kerning) * scale
```

text-anchor adjustment (computed before rendering any glyphs):
```
totalWidth = sum of all (advanceWidth + kerning) * scale
if textAnchor == 'middle': penX -= totalWidth / 2
if textAnchor == 'end':    penX -= totalWidth
```

## Plain Text Fallback

When a glyph can't be resolved (missing from font, font file not found, fontconfig unavailable):

1. Stamp the raw Unicode character into the canvas at the pen position using `drawPoint($penX, $penY, $fill, $char)`
2. Advance the pen by `fontSize * 0.6` (rough average advance)
3. Log a debug warning once per missing glyph

Text is always readable even if font rendering fails. Fallback characters inherit the resolved fill color and opacity.

## Caching

- **Font resolution cache** — `fc-match` results cached in a static map keyed by `fontFamily+weight+style`. Never invalidated (font config rarely changes at runtime).
- **Font file cache** — Parsed `FontFile` instances cached by file path. php-font-lib keeps all tables in memory after first parse. Reused across all SVGs referencing the same font.
- **Glyph path cache** — `char → Path` map inside each `FontFile` instance. Same character renders identically every time.

All caches are in-process memory. The bot is long-running so this is effective. Memory usage is modest since a typical ASCII glyph set is ~100 paths.

## Error Handling

| Scenario | Behavior |
|----------|----------|
| `font-family` not found by fontconfig | Fall back to default font, log warning |
| Font file exists but can't be parsed by php-font-lib | Fall back to plain text rendering for entire `<text>`, log warning |
| Individual glyph missing from font (e.g., emoji, CJK) | Fall back to plain text character for that glyph only |
| No fontconfig available (`fc-match` not found) | Fall back to plain text rendering for all `<text>` elements |
| Invalid `font-size` (zero, negative) | Clamp to 1.0 minimum |
| `<text>` with no text content | No-op, skip rendering |

All warnings go through the existing logging system. No exceptions thrown for font issues — graceful degradation.

## SVG Text Attributes Supported

| Attribute | Values | Notes |
|-----------|--------|-------|
| `x`, `y` | number | Starting position |
| `font-family` | string | Resolved via fontconfig |
| `font-size` | number | In SVG user units, scales glyph paths |
| `font-weight` | `normal`, `bold`, 100–900 | Passed to fc-match |
| `font-style` | `normal`, `italic`, `oblique` | Passed to fc-match |
| `text-anchor` | `start`, `middle`, `end` | Horizontal alignment |
| `dominant-baseline` | `auto`, `alphabetic`, `middle`, `central`, `hanging`, `text-top`, `text-bottom` | Vertical positioning |
| `fill` | paint | Standard paint resolution |
| `stroke` | paint | Standard paint resolution |
| `transform` | transform string | Standard transform parsing |
| `opacity` | 0–1 | Element opacity |
| `fill-opacity` | 0–1 | Fill opacity |
| `stroke-opacity` | 0–1 | Stroke opacity |

`<tspan>` attributes: `dx`, `dy` (relative offsets), plus all style attributes above as local overrides.

## SVGParser Changes

Add a `'text'` case to `parseElement()` in `library/draw/SVGParser.php`:

1. Parse `<text>` attributes: `x`, `y`, `fill`, `stroke`, `font-size`, `font-family`, `font-weight`, `font-style`, `text-anchor`, `dominant-baseline`, `transform`, `opacity`, `fill-opacity`, `stroke-opacity`
2. Extract text content (direct text nodes + `<tspan>` children)
3. Resolve `fill`/`stroke` through the standard attribute cascade (inline `style` → CSS class → presentation attribute)
4. Return a `TextNode` in the scene tree

No changes to Canvas, Path, Shape, or the rendering pipeline — TextNode renders by creating Shape nodes that use the existing `drawPath()`.

## Deferred Features

The following SVG text features are out of scope for this milestone:

- `textPath` — text along a curved path
- `rotate` — per-character rotation
- `letter-spacing`, `word-spacing` — spacing adjustments
- `textLength` / `lengthAdjust` — force text to fit a given width
- `writing-mode`, `glyph-orientation` — vertical/CJK text
- `direction`, `unicode-bidi` — bi-directional text
- Ligatures and complex text shaping (Arabic, Indic, etc.)
- Multiple `<text>` x/y values for per-character positioning

## Testing

Tests in `tests/draw/` following existing PHPUnit patterns:

- **`FontFileTest`** — load a known TTF file, verify cmap resolution, glyph path extraction, advance widths, kerning values
- **`FontManagerTest`** — mock fontconfig calls, verify caching, default font fallback
- **`TextNodeTest`** — render a `<text>` element to a canvas, verify glyph positions, text-anchor alignment, dominant-baseline adjustment
- **`TspanNodeTest`** — inline layout with dx/dy offsets, style inheritance and overrides
- **`SVGParserTextTest`** — parse full SVG with `<text>` and `<tspan>` elements, verify scene tree structure
- **Integration** — render an SVG with text through the full pipeline (parser → scene → canvas → output)

Test font: use a small freely-licensed TTF (e.g., DejaVu Sans or a test font from php-font-lib's test fixtures) in `tests/fixtures/`.

## New Files

| File | Purpose |
|------|---------|
| `library/draw/FontManager.php` | Font resolution and caching |
| `library/draw/FontFile.php` | php-font-lib wrapper, glyph → Path conversion |
| `library/draw/TextNode.php` | Scene tree node for `<text>` |
| `library/draw/TspanNode.php` | Sub-run within `<text>` |
| `tests/draw/FontFileTest.php` | Font parsing tests |
| `tests/draw/FontManagerTest.php` | Font resolution tests |
| `tests/draw/TextNodeTest.php` | Text rendering tests |
| `tests/draw/TspanNodeTest.php` | Tspan tests |
| `tests/draw/SVGParserTextTest.php` | Parser integration tests |
| `tests/fixtures/` | Test font file(s) |

## Modified Files

| File | Change |
|------|--------|
| `composer.json` | Add `dompdf/php-font-lib` dependency |
| `library/draw/SVGParser.php` | Add `<text>`/`<tspan>` parsing |
| `docs/superpowers/specs/2026-06-02-draw-library-svg-roadmap.md` | Update milestone 12, add deferred features note |

## Dependency

- `dompdf/php-font-lib` — pure PHP TrueType/OpenType font parser. Parses cmap (character→glyph mapping), glyf (glyph outlines as quadratic Bézier points), hmtx (advance widths), kern (kerning pairs), head/hhea (metrics). 200M+ downloads, actively maintained, LGPL-2.1.
