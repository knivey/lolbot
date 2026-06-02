# Transform Design

## Overview

Add a 2×3 affine `Transform` class to `library/draw/` with two integration points: a transform stack on `Canvas` (imperative push/pop model) and an optional per-`Path` transform (SVG declarative model). They compose naturally via matrix multiplication.

## Transform class

File: `library/draw/Transform.php`

An immutable value object holding a 2×3 affine matrix `[a, b, c, d, e, f]`:

```
| a  c  e |   x' = a*x + c*y + e
| b  d  f |   y' = b*x + d*y + f
| 0  0  1 |
```

### Factory methods

- `Transform::identity()` — `[1, 0, 0, 1, 0, 0]`
- `Transform::translate(float $tx, float $ty)` — shifts origin
- `Transform::rotate(float $angle, float $cx = 0, float $cy = 0)` — rotation in radians; `cx`/`cy` is sugar for `translate(cx,cy) → rotate(θ) → translate(-cx,-cy)`
- `Transform::scale(float $sx, ?float $sy = null)` — uniform if `$sy` omitted (`$sy = $sx`)
- `Transform::skewX(float $angle)` — skew along X axis by angle in radians
- `Transform::skewY(float $angle)` — skew along Y axis by angle in radians
- `Transform::matrix(float $a, float $b, float $c, float $d, float $e, float $f)` — raw matrix values

### Instance methods

- `multiply(Transform $other): Transform` — compose: returns `$this` then `$other`. Result matrix is `M_other × M_this` (standard affine composition). Immutable — returns new instance.
- `apply(float $x, float $y): array{float, float}` — transform a single point
- `getElements(): array{float, float, float, float, float, float}` — return `[a, b, c, d, e, f]`

## Canvas transform stack

Add to `Canvas`:

### State

- `private Transform $ctm` — current transform matrix, initialized to `Transform::identity()`
- `private array $transformStack = []` — stack of saved CTM states

### Methods

- `save(): void` — push current CTM onto stack
- `restore(): void` — pop stack, restore CTM. Throws if stack is empty.
- `getTransform(): Transform` — return current CTM
- `setTransform(Transform $t): void` — replace CTM entirely
- `concatTransform(Transform $t): void` — compose onto CTM: `CTM = CTM × $t`
- `translate(float $tx, float $ty): void` — convenience, delegates to `concatTransform(Transform::translate(...))`
- `rotate(float $angle, float $cx = 0, float $cy = 0): void` — convenience
- `scale(float $sx, ?float $sy = null): void` — convenience
- `skewX(float $angle): void` — convenience
- `skewY(float $angle): void` — convenience

`save()` and `restore()` only manage the transform stack, not other canvas state (colors, pixels). This keeps scope minimal.

### Integration with drawing methods

When any drawing method is called, the effective transform is `CTM × pathTransform`:

- `drawPath(Path $path, ...)` — flatten path, apply `CTM × path->getTransform()` to all vertices, then rasterize as before
- `drawLine($x1, $y1, $x2, $y2, ...)` — transform both endpoints via CTM, then draw
- `drawPolygon($points, ...)` — transform all points via CTM, then rasterize
- `drawPoint($x, $y, ...)` — transform point via CTM, then draw

## Path-level transform

Add to `Path`:

- `private ?Transform $transform = null`
- `setTransform(?Transform $t): self` — set the transform, returns `$this` for chaining
- `getTransform(): ?Transform` — get the transform

The transform does **not** affect builder methods (`moveTo`, `lineTo`, `cubicTo`, etc.). Those always work in local coordinates. The transform is applied at render time when `drawPath()` composes it with the CTM.

## Composition order

When `drawPath()` renders a path with both a canvas CTM and a path-level transform:

1. Compute `effective = CTM × pathTransform` (returns identity if either is null)
2. Flatten the path to vertices (in local coordinates)
3. Apply `effective` to every vertex
4. Snap to integers and rasterize as before

## Art command usage examples

### Rotated rectangle via Path transform

```php
$path = Path::rect(0, 0, 20, 5);
$path->setTransform(Transform::rotate(deg2rad(45), 10, 2.5));
$art->drawPath($path, $fillColor, $outlineColor);
```

### Canvas transform stack for repeated elements

```php
for ($i = 0; $i < 8; $i++) {
    $art->save();
    $art->translate(40, 20);
    $art->rotate(deg2rad(45 * $i));
    $art->drawPath(Path::rect(-10, -2, 20, 4), $fillColor, $outlineColor);
    $art->restore();
}
```

### Combined: canvas position + path rotation

```php
$art->translate(40, 20);
$shape = Path::rect(-8, -8, 16, 16);
$shape->setTransform(Transform::rotate(deg2rad(30)));
$art->drawPath($shape, $fillColor, $outlineColor);
```

## Testing

- `TransformTest` — identity, factory methods, apply, multiply, composition order
- `CanvasTest` additions — save/restore, CTM application to drawPath/drawLine/drawPolygon/drawPoint
- Integration: path transform + canvas CTM composition

## Files to create/modify

| File | Action |
|------|--------|
| `library/draw/Transform.php` | New — Transform class |
| `library/draw/Canvas.php` | Modify — add CTM, transform stack, apply transforms in draw methods |
| `library/draw/Path.php` | Modify — add `setTransform`/`getTransform` |
| `tests/Canvas/TransformTest.php` | New — unit tests for Transform |
| `tests/Canvas/CanvasTest.php` | Modify — add transform integration tests |
