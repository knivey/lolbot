# SVG Parser & Rasterizer Hardening (Memory/CPU Bomb Guards) — Design

Date: 2026-09-17
Status: Approved (user waived spec review; proceed to implementation)

## Problem

The art bot's SVG pipeline (`@svg`, `@rain`) parses and rasterizes attacker-supplied
SVGs from URLs synchronously inside the Amp event loop. Profiling of the sibling
ImageMagick path (linktitles AI vision, commit 4048f11) established that decode/resize
cost scales linearly with total pixels; the SVG pipeline has no caps at all and is
exposed to memory bombs (OOM = uncatchable fatal), stack exhaustion (segfault), and
CPU hangs (whole bot unresponsive). The Imagick-based `@url`/`@ascii` (artbot) and
`@yoda` (channel bot) commands additionally fetch and decode remote images with no
body-size cap and no dimension/pixel cap — the same vulnerability class fixed in
linktitles.

Attack surface (from research, file:line verified):

1. **Huge-canvas allocation via SVG aspect ratio** — `artbot_scripts/svg.php:76-99`
   derives render height from the SVG's `width`/`height`/`viewBox`;
   `viewBox="0 0 0.001 1000000000"` yields an astronomically tall canvas.
   `--width`/`--height`/`--supersample` are unclamped above (only minimums at
   :105-106). `artbot_scripts/rain.php:182-183,240-245` same pattern. Allocation is
   `Canvas::createBlank()` (`library/draw/Canvas.php:50-63`) which allocates w×h
   `Pixel` objects (~100+ bytes each) with no bound. Also multiplied by every
   compositing layer (`ClipNode`, `MaskNode`, `FilterNode`, `FilterPipeline`,
   `GaussianBlurPrimitive` — three canvases per blur, `DropShadowPrimitive`, …).
2. **Scanline CPU hang from huge path coordinates** —
   `Canvas::fillPolygonScanlineMulti` (`Canvas.php:714`) iterates the polygon's full
   coordinate range regardless of canvas size; `fillSpan` (:745-748) iterates every
   integer x between intersections. `d="M0 0 L1000000000 1000000000 Z"` ≈ 10⁹
   iterations on an 80×40 render. Coordinates arrive via `(int)round()` of
   transformed floats (`drawPath` :489-498).
3. **clip/mask/filter reference cycles → infinite parser recursion** —
   `SVGParser::wrapWithClipMask()` (`library/draw/SVGParser.php:808-875`) re-parses
   referenced elements per referencer with no visited-set; a `<clipPath id="a">`
   containing an element with `clip-path="url(#a)"` (self or mutual) recurses
   infinitely. Also N-referencers × M-children re-parse amplification.
4. **Deep `<g>`/`<svg>` nesting** — `parseElement()`/`parseSvgElement`/`parseGroupElement`
   (:415-480), `collectAllDefs()` (:560-574), `collectStyles()` (:1265-1280) recurse
   without depth limits.
5. **feGaussianBlur bomb** — `stdDeviation` unclamped (`SVGParser.php:899-903`);
   boxRadius = stdDev·√2 (`GaussianBlurPrimitive.php:35`) → ~10⁹-iteration kernel
   loops per pixel (:68-96, :138-166).
6. **Path-data amplification** — `tokenizeD`/`parseDString` (`SVGParser.php:9-220`)
   have no segment cap; 2MB `d` attribute (download cap in svg.php is 2MB) yields
   ~10⁵-10⁶ segments, each flattening to up to 2²⁰ vertices (`CubicBezier.php:47`
   depth-20 recursion).
7. **Stroke/dash/text amplification** — unclamped `stroke-width` (arc join steps
   `Canvas::arcPoints` :969-985), quadratic dash loop (`applyDashPattern`
   :1033-1130), unclamped `font-size` (`SVGParser.php:1411-1412`,
   `TextNode.php:43` clamps only minimum).
8. **Entity expansion (billion laughs)** — `simplexml_load_string` at
   `SVGParser.php:348` passes no libxml flags; protected only by libxml2 ≥2.9
   defaults. XXE/external DTD not enabled by default, but no explicit defense.
9. **Font process/cache spam** — every distinct `font-family` spawns
   `shell_exec('fc-match …')` (`FontManager.php:62`) and is cached forever in an
   unbounded static `$pathCache` (:13).
10. **Imagick paths uncapped** — `artbot_scripts/urlimg.php:32-37,165-170` (no
    `setBodySizeLimit`) decodes arbitrary images (:200-201); `scripts/yoda/yoda.php:23-28`
    (channel bot, no body limit) `readImageBlob` (:34-36) and composites at full
    fetched resolution (:48-57). linktitles (ping-first, 25MP, frame-aware) is the
    template.

## Goals

- No attacker-supplied SVG or image URL can OOM, segfault, or CPU-hang the bot.
- Rejections are **visible errors** on interactive commands (`@svg`, `@rain`,
  `@url`, `@ascii`, `@yoda`) — the user explicitly asked for the render.
- Legitimate art (canvases ≤ a few MP, normal SVGs) renders unchanged.
- Follow the proven linktitles pattern: cheap checks before expensive work.

## Non-goals

- Subprocess/rlimit isolation (amphp Process) — deferred future layer.
- `<use>`/`<symbol>` support (not implemented today; no amplification exists).
- Refactoring the rasterizer architecture or the linktitles guard itself.

## Design

### 1. Central limits — `library/draw/RenderLimits.php`

Final class, public constants, used by parser, rasterizer, and entry scripts:

| Constant | Value | Rationale |
|---|---|---|
| `maxCanvasPixels` | 4,000,000 | Legit art ≤200px wide; Pixel ≈100B each → ~400MB worst case |
| `maxCanvasSide` | 100,000 | Sanity bound per dimension |
| `maxParseDepth` | 200 | XML nesting deeper than this is hostile |
| `maxElements` | 100,000 | Total parsed nodes per document |
| `maxPathSegments` | 50,000 | Per `d` attribute |
| `maxStrokeWidth` | 500 | Clamp (not throw) — degrade gracefully |
| `maxBlurStdDev` | 100 | Clamp; boxRadius stays ~141px |
| `maxFontSize` | 1000 | Clamp |
| `maxDashCount` | 10,000 | Total dashes per stroke, clamp |
| `maxArcSteps` | 5,000 | Per-join arc point cap, clamp |
| `maxCoordMagnitude` | 10,000,000 | Coordinate clamp at `drawPath` |

### 2. Allocation guards (vector 1)

- `Canvas::createBlank(int $w, int $h)`: throw `InvalidArgumentException`
  ("canvas too large {w}x{h}") if `w <= 0 || h <= 0`, `w*h > maxCanvasPixels`, or
  either side > `maxCanvasSide`. Central choke point — all compositing layers
  allocate through it.
- `svg.php`: after computing final `$renderW`/`$renderH` (width/height/viewBox
  aspect math + `--width`/`--height`/`--supersample`), check against
  `RenderLimits` and emit a friendly visible error ("svg render too large WxH")
  before any allocation. Clamp user options to ranges (width 10..1000, height
  2..10000, supersample stays 2..4) rather than erroring for merely silly values;
  error only when limits would be exceeded.
- `rain.php`: same validation for aspect-derived `copyW`/`copyH` and
  `rotW`/`rotH` before `createBlank`.

### 3. Parser guards (vectors 3, 4, 6, 7)

- `SVGParser::parseString()`: add `LIBXML_NONET` to `simplexml_load_string` options
  (explicit no-network; entity expansion remains capped by libxml2 defaults — do
  NOT pass `LIBXML_NOENT` or `LIBXML_PARSEHUGE`).
- Instance counters reset per parse: `$elementCount`, and a `$depth` parameter
  threaded through `parseElement` (and the recursive collectors
  `collectAllDefs`, `collectStyles`, `wrapWithClipMask` internal re-parses).
  Throw `InvalidArgumentException` ("svg too deep" / "svg too many elements")
  past limits. `InvalidArgumentException` chosen because entry scripts already
  catch it and show `$e->getMessage()` to the user.
- `parseDString`/`tokenizeD`: throw past `maxPathSegments` ("svg path too long").
- `wrapWithClipMask(array $visited = [])`: track referenced clip/mask/filter ids
  through the re-parse chain; id already present → throw ("circular clip/mask/
  filter reference"). Do not memoize (perf only) — cycle guard is the fix.
- Attribute clamps at parse time (silent, graceful degradation):
  `stroke-width` → `maxStrokeWidth`; `stdDeviation` → `maxBlurStdDev`;
  `font-size` → `maxFontSize`; dash arrays → total count `maxDashCount` (excess
  entries dropped).

### 4. Rasterizer guards (vectors 2, 5, 7)

- `Canvas::fillPolygonScanlineMulti`: iterate scanlines over
  `max(0, ceil(minY)) .. min(canvasH-1, floor(maxY))` — clip to canvas instead of
  coordinate range. `fillSpan`: clamp x runs to `[0, canvasW-1]`. Invisible for
  on-canvas pixels; kills the 10⁹-iteration hang.
- `Canvas::drawPath`: clamp each coordinate to ±`maxCoordMagnitude` before
  rasterization (clamped, not thrown).
- `Canvas::arcPoints`: cap `steps` at `maxArcSteps`.
- `Canvas::applyDashPattern`: cap total dash segments at `maxDashCount`.
- `GaussianBlurPrimitive`: defensively re-clamp `stdDeviation` to
  `maxBlurStdDev` (parse-time clamp is primary).

### 5. Imagick path hardening — vectors 10

- New shared helper `library/ImageGuard.php`: static methods
  `oversize(Imagick $ping): bool` (width·height·frames > 25MP, frame-aware like
  linktitles) and `ping(string $body): Imagick` (thin `pingImageBlob` wrapper).
  25MP constant lives here (`ImageGuard::maxPixels = 25_000_000`).
- `artbot_scripts/urlimg.php` (`@url`, `@ascii`): add
    `setBodySizeLimit(16MB)`; before `readImageBlob`, ping + `ImageGuard::oversize`
  check → visible error "image too large WxH (max 25MP)".
- `scripts/yoda/yoda.php` (channel bot): same treatment (16MB body limit, ping
  guard, visible error).
- linktitles left untouched (already hardened and tested).

### 6. Font spam — vector 9

- `FontManager::$pathCache` capped at 128 entries; when full, a cache miss
  returns the fallback font path without spawning `fc-match`.

### 7. Error propagation / UX

- All guards throw `InvalidArgumentException` with short, user-showable messages
  (existing entry-script catch blocks already print them). Clamps are silent.
- `@svg`/`@rain` pre-allocation checks produce friendly phrasing, e.g.
  "svg render too large 80x31250000".

## Testing

Bomb fixture per vector, in `tests/Canvas/` (new `HardeningTest.php` or split by
class under test) and `tests/Imgur`-style harnesses for script-level guards:

1. `Canvas::createBlank` oversize → throws; max-legal size renders.
2. 300-deep nested `<g>` SVG → "svg too deep".
3. 150k-element SVG → "svg too many elements".
4. `d` with 60k segments → "svg path too long".
5. Self-referencing `<clipPath>` and mutual clip↔mask pair → "circular … reference".
6. `viewBox="0 0 1 1000000"` `@svg`-level sizing → friendly error (extracted
   sizing function, tested directly).
7. `d="M0 0 L1e9 1e9 Z"` on 80×40 canvas: render completes (assert no exception +
   bounded runtime via a generous PHPUnit timeout); scanline clamp unit test
   verifies fill range equals canvas intersection.
8. `stdDeviation="1e9"` blur on small canvas completes (clamped).
9. `stroke-width="1e9"` path renders (clamped), arc steps capped.
10. `urlimg`/`yoda` ping guard: patched-IHDR 60000×60000 fixture (reuse pattern
    from `tests/fixtures/bomb_header_60000x60000.png`) and animated GIF fixture →
    visible-error path asserted.
11. `FontManager` cache cap: 129th unique family does not spawn `fc-match`
    (assert via injectable spawn stub or count-limited wrapper).

Verification per repo standard: `composer test` (967+ tests green), scoped
phpstan no-new-errors vs baseline, php-cs-fixer clean.

## Deferred

- amphp Process + rlimit/timeout sandbox as a second defense layer.
- `<use>`/`<symbol>` support (if ever added, needs its own cycle/amplification
  guards — noted for future implementers).
