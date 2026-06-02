# EvenOdd Fill Rule

## Purpose

Add `fill-rule="evenodd"` support to the scanline converter for SVG compatibility.
The current NonZero winding rule remains the default. EvenOdd is needed to
correctly render SVGs that explicitly set `fill-rule="evenodd"`.

## Changes

### 1. `FillRule` enum — `library/draw/FillRule.php`

Two cases: `NonZero` (default), `EvenOdd`.

### 2. `Canvas::drawPath()` — new parameter

Add `FillRule $fillRule = FillRule::NonZero` as the last parameter.

### 3. `fillPolygonScanlineMulti()` — rule-aware scanline walk

Thread `$fillRule` through. When `EvenOdd`:

- Replace winding counter with a boolean `$inside` toggled at each intersection.
- Fill spans open when `$inside` flips from false to true, close when it flips
  from true to false.

`NonZero` behavior is unchanged.

## Scope

- No changes to outline rasterization.
- No changes to `Path` or `Transform`.
- `FillRule` enum only — no string/constant alternatives.
