# SVG Command Design

Add a `@svg` command to the art bot that fetches an SVG file from a URL, parses it using the existing SVGParser, renders it to an IRC canvas, and pumps the output to the channel.

## Goal

Users can paste an SVG URL in IRC and get a rendered IRC art version of it, similar to how `@ascii` and `@url` work for raster images.

## Command

```
@svg <url> [--width=80] [--height=40] [--nohalfblock]
```

- `<url>` — required, URL to an SVG file
- `--width N` — canvas width in character cells (default 80)
- `--height N` — canvas height in character cells (default 40)
- `--nohalfblock` — use normal mode instead of halfblock rendering (default: halfblock enabled)

## Architecture

**New file:** `artbot_scripts/svg.php`
**Modified:** `artbots.php` — add `require_once 'artbot_scripts/svg.php';`

Follows the same pattern as existing art bot commands (`urlimg.php`, `drawing.php`):
- Command registered via `#[Cmd]` attribute (auto-discovered by `Cmdr::loadFuncs()`)
- URL fetched via Amp HTTP client
- Rendered using `draw\SVGParser` + `draw\Canvas`
- Output sent via `pumpToChan()`

No external tools required (no `p2u` or `a2m`).

## Flow

1. Parse command args to get URL and options
2. Validate URL scheme (http/https only)
3. Fetch SVG via Amp HTTP client
4. Check response status (must be 200)
5. Check content size: reject if > 2MB
   - Check `Content-Length` header first; if present and > 2MB, reject immediately without downloading body
   - If `Content-Length` absent or smaller, buffer body with a 2MB limit during read
6. Validate content-type is SVG-ish (`image/svg+xml`, `text/xml`, `application/xml`, or `text/html` with svg content)
7. Parse: `$doc = SVGParser::parseString($body)`
8. Create canvas: `Canvas::createBlank($width, $height, true)` (halfblocks)
9. Render: `$doc->render($canvas)`
10. Convert to string, split by newlines, trim
11. Pump to channel via `pumpToChan()`

## Size Constraint

Max 2MB for fetched SVG content. Two-stage check:

1. **Pre-download:** Check `Content-Length` response header. If present and > 2,097,152 bytes, reject with a notice without downloading the body.
2. **During download:** If `Content-Length` is absent, buffer the body with a 2MB cap. If the body exceeds 2MB during buffering, abort and notice the user.

Error message: `"SVG file too large (max 2MB)"`

## Error Handling

| Condition | Response |
|---|---|
| Missing URL argument | Notice: `"Usage: @svg <url>"` |
| Invalid URL scheme | Notice: `"URL must be http or https"` |
| Fetch failure / non-200 | Notice: `"Failed to fetch SVG: <status>"` |
| Content too large (>2MB) | Notice: `"SVG file too large (max 2MB)"` |
| Invalid SVG XML | Notice: `"Failed to parse SVG"` |
| Empty render (blank canvas) | Notice: `"SVG rendered as empty"` |

All errors sent via `$bot->notice($args->nick, ...)`.

## File Inventory

| File | Action | Purpose |
|---|---|---|
| `artbot_scripts/svg.php` | Create | `@svg` command implementation |
| `artbots.php` | Modify | Add `require_once` for svg.php |

## Dependencies

Uses existing code only:
- `draw\SVGParser` — parse SVG XML
- `draw\SVGDocument` — hold parsed result
- `draw\Canvas` — rendering surface
- `draw\Color` / `draw\IrcPalette` — IRC color mapping
- `library/async_get_contents.php` — or direct Amp HTTP client
- `NetworkContext::pumpToChan()` — output to IRC
- `knivey\cmdr\attributes\*` — command registration attributes

## Out of Scope

- SVG optimization or preprocessing
- Caching of fetched SVGs
- Support for SVGZ (gzipped SVG)
- Multiple URL arguments
- Edit links (unlike `@ascii --edit`)
