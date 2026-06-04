# CSS Class Support for SVG Parser

## Summary

Add inline `<style>` block parsing to `SVGParser` so that SVG documents using CSS class selectors, ID selectors, type selectors, and the universal selector resolve their presentation properties correctly during rendering.

## Motivation

Many real-world SVG files define styling through `<style>` blocks and `class` attributes rather than inline presentation attributes. Currently, `getEffectiveAttr()` only checks XML attributes and inline `style` attribute properties. CSS class-based styling is silently ignored, causing those SVGs to render with default (often black) fills or no fill at all.

## Scope

**In scope:**
- Parse `<style>` element text content within SVG documents
- Support simple selectors: class (`.foo`), ID (`#bar`), type (`rect`), universal (`*`)
- Comma-separated selector groups (e.g., `.a, .b { fill: red; }`)
- Specificity-based cascade: inline `style` attribute > CSS rules (ID > class > type > universal, last rule wins on ties) > XML presentation attribute
- All presentation properties currently handled by `getEffectiveAttr()` and `parseStrokeAttr()`: `fill`, `stroke`, `stroke-width`, `opacity`, `fill-opacity`, `stroke-opacity`, `fill-rule`, `stroke-dasharray`, `stroke-dashoffset`, `stroke-linecap`, `stroke-linejoin`, `stroke-miterlimit`, `stop-color`, `display`

**Out of scope:**
- External stylesheets (`<?xml-stylesheet?>`, `xlink:href`)
- Descendant (`A B`), child (`A > B`), sibling combinators
- Pseudo-classes (`:hover`, `:first-child`)
- Attribute selectors (`[attr=val]`)
- Compound selectors (`rect.foo`)
- `!important` declarations
- `inherit`, `initial`, `unset` keywords
- `<style>` media queries

## Architecture

### Approach: Inline in SVGParser

All CSS parsing logic lives as private helper methods within `SVGParser`. A new `$styles` array is threaded through parse methods alongside the existing `$defs` array.

### Data structures

The `$styles` array holds entries of this shape:

```php
[
    'selector'    => string,  // e.g., '.highlight', '#myId', 'rect', '*'
    'specificity' => int,     // 300=ID, 200=class, 100=type, 0=universal
    'props'       => ['fill' => 'red', 'stroke-width' => '2', ...],
]
```

Rules are stored in document order. When matching, all matching rules are collected and sorted by specificity (ascending), then by array position (stable sort preserves document order for ties). The last matching declaration for each property wins.

### New methods

- `parseStyleBlock(string $css): array` — Extracts selector→property rules from `<style>` text. Uses regex to split into rule blocks, then parses each selector list and property declarations.
- `matchStyles(array $styles, string $tag, string $class, string $id): array` — Collects all matching rules for an element, resolves to a flat property map using specificity ordering.

### Modified methods

- `getEffectiveAttr()` — gains `$styles`, `$tag`, `$class`, `$id` parameters. New cascade order: inline `style` property → matched CSS rules → XML presentation attribute.
- `parsePaintAttr()`, `parseStrokeAttr()`, `parseFloatAttr()`, `parseFillRuleAttr()`, `parseOptionalTransform()` — pass through the additional context to `getEffectiveAttr()`.
- `buildShape()`, `parseGroupElement()`, `parseGradientStops()`, all element parsers — thread `$styles` through and extract `class`/`id` from elements.
- `parseSvgElement()`, `parseGroupElement()` — detect `<style>` children, call `parseStyleBlock()`, append results to `$styles`.

### Cascade resolution in `getEffectiveAttr()`

```
1. Check inline style attribute (style="fill:red") → return if found
2. Collect all CSS rules matching element's tag/class/id
3. Sort by specificity (0 < 100 < 200 < 300), stable (preserves document order)
4. Check CSS rules for property, last declaration wins → return if found
5. Check XML presentation attribute (fill="red") → return if found
6. Return ''
```

### `<style>` element handling

When `parseSvgElement()` or `parseGroupElement()` encounters a `<style>` child, it:
1. Extracts the text content (SimpleXML: cast to string or use `->asXML()`)
2. Strips CDATA wrappers if present
3. Calls `parseStyleBlock()` to get rule entries
4. Appends entries to the `$styles` array
5. Does not add a scene node for the `<style>` element

### Supported presentation properties

Only properties relevant to the draw library's rendering pipeline are extracted from CSS declarations:

| Property | Used by |
|---|---|
| `fill` | `parsePaintAttr()` |
| `stroke` | `parseStrokeAttr()` |
| `stroke-width` | `parseStrokeAttr()` |
| `stroke-dasharray` | `parseStrokeAttr()` |
| `stroke-dashoffset` | `parseStrokeAttr()` |
| `stroke-linecap` | `parseStrokeAttr()` |
| `stroke-linejoin` | `parseStrokeAttr()` |
| `stroke-miterlimit` | `parseStrokeAttr()` |
| `stroke-opacity` | `parseStrokeAttr()` |
| `opacity` | `parseFloatAttr()` |
| `fill-opacity` | `parseFloatAttr()` |
| `fill-rule` | `parseFillRuleAttr()` |
| `stop-color` | `parseGradientStops()` |
| `display` | `buildShape()` (new: `display:none` skips rendering) |

Properties not in this list are ignored when encountered in CSS rules.

## Files

| File | Action | Purpose |
|---|---|---|
| `library/draw/SVGParser.php` | Modify | Add CSS parsing helpers, thread `$styles` through parse methods |
| `tests/Canvas/SVGParserTest.php` | Modify | Add tests for CSS class/ID/type/universal selector resolution |
| `docs/superpowers/specs/2026-06-02-draw-library-svg-roadmap.md` | Modify | Mark milestone 15 complete |

## Test cases

- Class selector sets fill on matching elements
- ID selector overrides class selector (higher specificity)
- Type selector applies to all elements of that type
- Universal selector applies to all elements
- Inline `style` attribute overrides CSS rules
- XML presentation attribute is lowest priority
- Last rule wins when specificity is equal
- Multiple classes on one element (space-separated `class="a b"`)
- `<style>` inside `<g>` element
- `display: none` causes element to be skipped
- Unknown CSS properties are ignored
- `stop-color` in CSS applies to gradient stops
- Empty `<style>` block is harmless
- Malformed CSS is gracefully skipped

## Risks

- **SimpleXML CDATA handling:** `<style>` blocks sometimes use `<![CDATA[...]]>`. SimpleXML should handle this transparently, but needs verification in tests.
- **Performance:** Linear scan of rules per attribute per element. For typical SVGs (< 100 rules, < 1000 elements) this is negligible. No optimization needed unless profiling shows otherwise.
- **Regex fragility:** CSS parsing via regex is inherently limited. The chosen regex approach handles the simple selector subset well but will break if we later need combinators or pseudo-classes — at that point we'd need a proper tokenizer.
