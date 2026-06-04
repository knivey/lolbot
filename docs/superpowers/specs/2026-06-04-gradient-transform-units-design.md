# gradientTransform + gradientUnits Support

**Date**: 2026-06-04
**Roadmap milestone**: Tier 2 — Rich Rendering / Gradients (fill gap)
**Status**: Design approved

## Problem

SVG gradients support two attributes that our parser completely ignores:

1. **`gradientTransform`** — an additional affine transform that maps gradient coordinates to user space. Without it, gradients with non-trivial transforms (translate, rotate, scale) render at wrong positions and sizes.

2. **`gradientUnits`** — determines the coordinate system for gradient coordinates:
   - `objectBoundingBox` (default): coords 0–1 map to the shape's bounding box
   - `userSpaceOnUse`: coords are in SVG user space

There is also a pre-existing coordinate space mismatch: `getColorAt(x, y)` receives canvas pixel coordinates, but gradient coordinates from SVG are in user space. When a viewBox transform is active, these are different spaces. This bug is masked when viewBox dimensions match canvas dimensions.

## Solution Overview

Four parts:

1. **`Transform::inverse()`** — affine matrix inversion, needed everywhere
2. **Canvas inverse CTM** — map pixel coords to user space before `getColorAt`
3. **Gradient transform support** — store and apply `gradientTransform` in gradient classes
4. **`gradientUnits` handling** — `objectBoundingBox` scaling at paint resolution time

Plus: **reference transform tracking** for correct nested group gradient coordinates.

## Detailed Design

### 1. `Transform::inverse()`

Add `inverse(): Transform` to `draw\Transform`. Standard 2D affine matrix inversion:

```
Matrix: | a  c  e |    det = a*d - b*c
        | b  d  f |    inverse = (1/det) * |  d  -c  c*f-d*e |
        | 0  0  1 |                         | -b   a  b*e-a*f |
```

Throw `LogicException` for non-invertible (singular) matrices. In practice, SVG transforms are always invertible.

### 2. Canvas Inverse CTM

**Current behavior**: The Canvas maintains a `$ctm` (current transform matrix). Path vertices are transformed by the CTM before rasterization. `getColorAt()` receives raw canvas pixel coordinates.

**Change**: The Canvas also maintains an `$inverseCtm`. Every CTM mutation updates both:

| Canvas method | CTM update | Inverse CTM update |
|---|---|---|
| `concatTransform($t)` | `ctm = ctm * $t` | `inverseCtm = $t->inverse() * inverseCtm` |
| `save()` | push ctm | push inverseCtm |
| `restore()` | pop ctm | pop inverseCtm |
| constructor | identity | identity |

**getColorAt call sites** (3 places in Canvas): transform pixel coords through `$inverseCtm` before passing to `getColorAt`:

```php
[$ux, $uy] = $this->inverseCtm->apply((float)$xx, (float)$Y);
[$r, $g, $b] = $paint->getColorAt($ux, $uy);
```

**Impact on existing code**:
- When CTM is identity (no transforms, no viewBox), inverse CTM is also identity — zero behavioral change
- `Color::getColorAt()` ignores coordinates — zero behavioral change
- Gradient unit tests call `getColorAt` directly (bypass Canvas) — unaffected
- The new contract: `getColorAt` receives coordinates in the Canvas's user space (pre-CTM space), not pixel space

**Shape transforms**: The shape's own transform is applied to path vertices via `$path->getTransform()` (not through the Canvas CTM). So `$inverseCtm` maps to the shape's **parent** coordinate system (viewBox + group transforms), which is exactly the `userSpaceOnUse` reference coordinate system for shapes.

### 3. Gradient Classes

**`GradientUnits` enum** (new file):

```php
enum GradientUnits {
    case ObjectBoundingBox;
    case UserSpaceOnUse;
}
```

**`LinearGradient` changes**:
- New constructor params: `?Transform $gradientTransform = null`, `GradientUnits $gradientUnits = GradientUnits::ObjectBoundingBox`
- In `getColorAt(x, y)`: if `$gradientTransform` is set, apply `$gradientTransform->inverse()` to `(x, y)` before computing the gradient

**`RadialGradient` changes**:
- Same new constructor params as LinearGradient
- Same inverse transform application in `getColorAt`

The gradient's own coordinates (x1/y1/x2/y2 for linear; cx/cy/r for radial) remain in their **gradient-local** coordinate space. The `gradientTransform` maps gradient-local → user space. By applying its inverse in `getColorAt`, we map the user-space sample point back to gradient-local space where the coordinates are defined.

### 4. SVGParser Changes

**`parseGradientElement()`**:
- Parse `gradientTransform` attribute using existing `parseTransform()`
- Parse `gradientUnits` attribute (default: `objectBoundingBox`)
- Pass both to gradient constructors
- Store gradient in `$defs` with its `GradientUnits` value alongside the gradient object (so paint resolution knows how to handle it)

**Defs storage change**: Currently `$defs[$id] = gradient_object`. Change to a structure that carries metadata:

```php
$defs[$id] = new GradientDef($gradient, $gradientUnits);
```

Or equivalently, store `GradientUnits` on the gradient objects themselves (they already have the property) and update `parsePaintAttr` / `parseStrokeAttr` to check it.

**objectBoundingBox handling** in `parsePaintAttr()` / `parseStrokeAttr()`:
- When resolving `url(#gradientId)`, check the gradient's `gradientUnits`
- If `objectBoundingBox`: compute the path's bounding box (requires new `Path::getBBox()` method), create a new gradient instance with coordinates scaled from [0,1] to the bbox:
  - Linear: `x1' = bbox.x + x1 * bbox.w`, etc.
  - Radial: `cx' = bbox.x + cx * bbox.w`, `cy' = bbox.y + cy * bbox.h`, `r' = r * sqrt(bbox.w² + bbox.h²) / sqrt(2)` (SVG spec diagonal formula)
- If `userSpaceOnUse`: use the gradient as-is

**`buildShape()` signature change**: `parsePaintAttr()` needs the `Path` to compute bounding boxes for `objectBoundingBox`. Currently `buildShape()` calls `parsePaintAttr()` without the path. Thread the `Path` through so `parsePaintAttr` can compute the bbox.

### 5. Reference Transform Tracking (Nested Groups)

For `userSpaceOnUse` gradients referenced inside transformed groups, the gradient's coordinate system is the group's user space, not root. The inverse CTM alone maps too far (to root).

**Solution**: SVGParser tracks an accumulated group transform during parsing. When resolving a gradient paint, the current accumulated transform is stored as a "reference transform" on the gradient paint.

**`SVGParser` changes**:
- New `array $transformStack` parameter threaded through `parseElement()`, `parseGroupElement()`, `parseSvgElement()`, `buildShape()`
- `parseGroupElement()`: if the group has a transform, push it onto the stack before parsing children, pop after
- The accumulated transform at any point = composition of all transforms on the stack

**Reference transform on gradient paints**:
- When resolving `url(#gradientId)` for a `userSpaceOnUse` gradient, compute `$refTransform` = composition of `$transformStack` entries
- Store `$refTransform` on the gradient paint (new property on gradient classes or a wrapper)
- In `getColorAt()`: apply `$refTransform->inverse()` then `$gradientTransform->inverse()` to the input coordinates

**For `objectBoundingBox`**: No reference transform needed — the coordinates are already scaled to the shape's bbox in its local space.

### 6. `Path::getBBox()` Method

New method on `draw\Path` that computes the axis-aligned bounding box of all subpath vertices (after flattening curves). Returns `{x, y, w, h}` or `null` for empty paths.

Uses the existing `flatten()` method to get all vertices, then computes min/max for x and y.

## File Changes Summary

| File | Change |
|---|---|
| `library/draw/Transform.php` | Add `inverse()` method |
| `library/draw/Canvas.php` | Add `$inverseCtm`, update in `concatTransform`/`save`/`restore`, apply in 3 `getColorAt` call sites |
| `library/draw/LinearGradient.php` | Add `$gradientTransform`, `$gradientUnits`, `$refTransform` params; apply in `getColorAt` |
| `library/draw/RadialGradient.php` | Same as LinearGradient |
| `library/draw/GradientUnits.php` | New enum file |
| `library/draw/Path.php` | Add `getBBox()` method |
| `library/draw/SVGParser.php` | Parse `gradientTransform`/`gradientUnits`; transform stack for ref transform; objectBoundingBox bbox scaling |
| `tests/Canvas/TransformTest.php` | Add `inverse()` tests |
| `tests/Canvas/GradientTest.php` | Add gradientTransform tests |
| `tests/Canvas/SVGParserTest.php` | Add gradientTransform, gradientUnits, viewBox coord mapping tests |

## Out of Scope

- `patternTransform` (separate feature)
- `textLength` / `lengthAdjust` on text
- Compound selectors in CSS targeting gradient attributes
- Elliptical radial gradients (objectBoundingBox on non-square shapes distorts circular gradients)
