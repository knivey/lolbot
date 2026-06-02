# Transform Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a 2×3 affine Transform class with Canvas transform stack (save/restore) and per-Path transform support.

**Architecture:** Immutable `Transform` value object. Canvas holds a CTM (current transform matrix) with save/restore stack. Path carries an optional transform. `drawPath` composes `CTM × pathTransform` and applies to all vertices before rasterization. Public `drawLine` and `drawPolygon` are removed — all drawing goes through `drawPath`. `drawLineInternal` is a private Bresenham helper used for outlines.

**Tech Stack:** PHP 8.1+, PHPUnit 10, existing `draw\` namespace classes.

**Spec:** `docs/superpowers/specs/2026-06-02-transform-design.md`

---

## File Structure

| File | Responsibility |
|------|---------------|
| `library/draw/Transform.php` | Immutable 2×3 affine matrix value object |
| `library/draw/Canvas.php` | Add CTM, transform stack, integrate into drawPath/drawPoint, remove drawLine/drawPolygon |
| `library/draw/Path.php` | Add `setTransform`/`getTransform` |
| `tests/Canvas/TransformTest.php` | Unit tests for Transform class |
| `tests/Canvas/CanvasTest.php` | Migrate to drawPath, add transform integration tests |
| `tests/Canvas/PathTest.php` | Add transform tests |
| `artbot_scripts/drawing.php` | Migrate drawLine callers to drawPath |
| `scripts/stocks/stocks.php` | Migrate drawLine callers to drawPath |

---

### Task 1: Transform class — identity, apply, getElements

**Files:**
- Create: `library/draw/Transform.php`
- Create: `tests/Canvas/TransformTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Tests\Canvas;

use draw\Transform;
use PHPUnit\Framework\TestCase;

class TransformTest extends TestCase
{
    public function test_identity_returns_identity_matrix(): void
    {
        $t = Transform::identity();
        $this->assertSame([1.0, 0.0, 0.0, 1.0, 0.0, 0.0], $t->getElements());
    }

    public function test_identity_apply_is_noop(): void
    {
        $t = Transform::identity();
        [$x, $y] = $t->apply(5.0, 10.0);
        $this->assertEqualsWithDelta(5.0, $x, 0.0001);
        $this->assertEqualsWithDelta(10.0, $y, 0.0001);
    }

    public function test_identity_is_immutable(): void
    {
        $t = Transform::identity();
        $elements = $t->getElements();
        $elements[0] = 99.0;
        $this->assertSame([1.0, 0.0, 0.0, 1.0, 0.0, 0.0], $t->getElements());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Canvas/TransformTest.php`
Expected: FAIL — class `draw\Transform` does not exist

- [ ] **Step 3: Write minimal implementation**

```php
<?php
namespace draw;

class Transform
{
    private function __construct(
        private readonly float $a,
        private readonly float $b,
        private readonly float $c,
        private readonly float $d,
        private readonly float $e,
        private readonly float $f,
    ) {
    }

    public static function identity(): self
    {
        return new self(1.0, 0.0, 0.0, 1.0, 0.0, 0.0);
    }

    /**
     * @return array{float, float, float, float, float, float}
     */
    public function getElements(): array
    {
        return [$this->a, $this->b, $this->c, $this->d, $this->e, $this->f];
    }

    /**
     * @return array{float, float}
     */
    public function apply(float $x, float $y): array
    {
        return [
            $this->a * $x + $this->c * $y + $this->e,
            $this->b * $x + $this->d * $y + $this->f,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- tests/Canvas/TransformTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add library/draw/Transform.php tests/Canvas/TransformTest.php
git commit -m "Add Transform class with identity, apply, getElements"
```

---

### Task 2: Transform factory methods — translate, scale, rotate

**Files:**
- Modify: `tests/Canvas/TransformTest.php`
- Modify: `library/draw/Transform.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Canvas/TransformTest.php`:

```php
    public function test_translate_shifts_point(): void
    {
        $t = Transform::translate(10.0, 20.0);
        [$x, $y] = $t->apply(5.0, 5.0);
        $this->assertEqualsWithDelta(15.0, $x, 0.0001);
        $this->assertEqualsWithDelta(25.0, $y, 0.0001);
    }

    public function test_scale_uniform(): void
    {
        $t = Transform::scale(2.0);
        [$x, $y] = $t->apply(3.0, 4.0);
        $this->assertEqualsWithDelta(6.0, $x, 0.0001);
        $this->assertEqualsWithDelta(8.0, $y, 0.0001);
    }

    public function test_scale_non_uniform(): void
    {
        $t = Transform::scale(2.0, 3.0);
        [$x, $y] = $t->apply(3.0, 4.0);
        $this->assertEqualsWithDelta(6.0, $x, 0.0001);
        $this->assertEqualsWithDelta(12.0, $y, 0.0001);
    }

    public function test_rotate_90_degrees(): void
    {
        $t = Transform::rotate(M_PI / 2.0);
        [$x, $y] = $t->apply(1.0, 0.0);
        $this->assertEqualsWithDelta(0.0, $x, 0.0001);
        $this->assertEqualsWithDelta(1.0, $y, 0.0001);
    }

    public function test_rotate_with_center(): void
    {
        $t = Transform::rotate(M_PI / 2.0, 10.0, 10.0);
        [$x, $y] = $t->apply(11.0, 10.0);
        $this->assertEqualsWithDelta(10.0, $x, 0.0001);
        $this->assertEqualsWithDelta(11.0, $y, 0.0001);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Canvas/TransformTest.php`
Expected: FAIL — method does not exist

- [ ] **Step 3: Write minimal implementation**

Add to `library/draw/Transform.php` after `identity()`:

```php
    public static function translate(float $tx, float $ty): self
    {
        return new self(1.0, 0.0, 0.0, 1.0, $tx, $ty);
    }

    public static function scale(float $sx, ?float $sy = null): self
    {
        $sy ??= $sx;
        return new self($sx, 0.0, 0.0, $sy, 0.0, 0.0);
    }

    public static function rotate(float $angle, float $cx = 0.0, float $cy = 0.0): self
    {
        $cos = cos($angle);
        $sin = sin($angle);
        if ($cx == 0.0 && $cy == 0.0) {
            return new self($cos, $sin, -$sin, $cos, 0.0, 0.0);
        }
        $tx = $cx - $cos * $cx + $sin * $cy;
        $ty = $cy - $sin * $cx - $cos * $cy;
        return new self($cos, $sin, -$sin, $cos, $tx, $ty);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- tests/Canvas/TransformTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add library/draw/Transform.php tests/Canvas/TransformTest.php
git commit -m "Add translate, scale, rotate factory methods to Transform"
```

---

### Task 3: Transform factory methods — skewX, skewY, matrix + multiply

**Files:**
- Modify: `tests/Canvas/TransformTest.php`
- Modify: `library/draw/Transform.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Canvas/TransformTest.php`:

```php
    public function test_skew_x(): void
    {
        $t = Transform::skewX(deg2rad(45.0));
        [$x, $y] = $t->apply(0.0, 1.0);
        $this->assertEqualsWithDelta(1.0, $x, 0.0001);
        $this->assertEqualsWithDelta(1.0, $y, 0.0001);
    }

    public function test_skew_y(): void
    {
        $t = Transform::skewY(deg2rad(45.0));
        [$x, $y] = $t->apply(1.0, 0.0);
        $this->assertEqualsWithDelta(1.0, $x, 0.0001);
        $this->assertEqualsWithDelta(1.0, $y, 0.0001);
    }

    public function test_matrix_raw(): void
    {
        $t = Transform::matrix(2.0, 0.0, 0.0, 3.0, 10.0, 20.0);
        [$x, $y] = $t->apply(1.0, 1.0);
        $this->assertEqualsWithDelta(12.0, $x, 0.0001);
        $this->assertEqualsWithDelta(23.0, $y, 0.0001);
    }

    public function test_multiply_identity_is_noop(): void
    {
        $id = Transform::identity();
        $t = Transform::translate(5.0, 10.0);
        $result = $t->multiply($id);
        [$x, $y] = $result->apply(0.0, 0.0);
        $this->assertEqualsWithDelta(5.0, $x, 0.0001);
        $this->assertEqualsWithDelta(10.0, $y, 0.0001);
    }

    public function test_multiply_translate_then_scale(): void
    {
        $translate = Transform::translate(10.0, 0.0);
        $scale = Transform::scale(2.0);
        $result = $translate->multiply($scale);
        [$x, $y] = $result->apply(5.0, 0.0);
        $this->assertEqualsWithDelta(20.0, $x, 0.0001);
    }

    public function test_multiply_scale_then_translate(): void
    {
        $scale = Transform::scale(2.0);
        $translate = Transform::translate(10.0, 0.0);
        $result = $scale->multiply($translate);
        [$x, $y] = $result->apply(5.0, 0.0);
        $this->assertEqualsWithDelta(30.0, $x, 0.0001);
    }

    public function test_multiply_is_immutable(): void
    {
        $a = Transform::translate(1.0, 2.0);
        $b = Transform::scale(3.0);
        $a->multiply($b);
        $this->assertSame([1.0, 0.0, 0.0, 1.0, 1.0, 2.0], $a->getElements());
    }

    public function test_multiply_rotate_then_translate(): void
    {
        $rot = Transform::rotate(M_PI / 2.0);
        $trans = Transform::translate(5.0, 0.0);
        $result = $rot->multiply($trans);
        [$x, $y] = $result->apply(1.0, 0.0);
        $this->assertEqualsWithDelta(5.0, $x, 0.0001);
        $this->assertEqualsWithDelta(1.0, $y, 0.0001);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Canvas/TransformTest.php`
Expected: FAIL — methods do not exist

- [ ] **Step 3: Write minimal implementation**

Add to `library/draw/Transform.php`:

```php
    public static function skewX(float $angle): self
    {
        return new self(1.0, 0.0, tan($angle), 1.0, 0.0, 0.0);
    }

    public static function skewY(float $angle): self
    {
        return new self(1.0, tan($angle), 0.0, 1.0, 0.0, 0.0);
    }

    public static function matrix(float $a, float $b, float $c, float $d, float $e, float $f): self
    {
        return new self($a, $b, $c, $d, $e, $f);
    }

    public function multiply(Transform $other): self
    {
        return new self(
            $other->a * $this->a + $other->c * $this->b,
            $other->b * $this->a + $other->d * $this->b,
            $other->a * $this->c + $other->c * $this->d,
            $other->b * $this->c + $other->d * $this->d,
            $other->a * $this->e + $other->c * $this->f + $other->e,
            $other->b * $this->e + $other->d * $this->f + $other->f,
        );
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- tests/Canvas/TransformTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add library/draw/Transform.php tests/Canvas/TransformTest.php
git commit -m "Add skewX, skewY, matrix, multiply to Transform"
```

---

### Task 4: Path-level transform

**Files:**
- Modify: `tests/Canvas/PathTest.php`
- Modify: `library/draw/Path.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Canvas/PathTest.php`:

```php
    public function test_path_default_transform_is_null(): void
    {
        $path = new Path();
        $this->assertNull($path->getTransform());
    }

    public function test_path_set_transform_returns_self(): void
    {
        $path = new Path();
        $t = Transform::translate(5.0, 10.0);
        $result = $path->setTransform($t);
        $this->assertSame($path, $result);
    }

    public function test_path_get_transform_returns_set_transform(): void
    {
        $path = new Path();
        $t = Transform::translate(5.0, 10.0);
        $path->setTransform($t);
        $this->assertSame($t, $path->getTransform());
    }

    public function test_path_set_transform_null_clears(): void
    {
        $path = new Path();
        $path->setTransform(Transform::identity());
        $path->setTransform(null);
        $this->assertNull($path->getTransform());
    }

    public function test_path_transform_does_not_affect_builder(): void
    {
        $path = new Path();
        $path->setTransform(Transform::translate(100.0, 200.0));
        $path->moveTo(5.0, 10.0);
        $this->assertSame([5.0, 10.0], $path->getCurrentPoint());
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Canvas/PathTest.php`
Expected: FAIL — method `getTransform` does not exist

- [ ] **Step 3: Write minimal implementation**

Add to `library/draw/Path.php` — new property after `$hasCurrentPoint`:

```php
    private ?Transform $transform = null;
```

Add methods before `ensureCurrentPoint`:

```php
    public function setTransform(?Transform $t): self
    {
        $this->transform = $t;
        return $this;
    }

    public function getTransform(): ?Transform
    {
        return $this->transform;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- tests/Canvas/PathTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add library/draw/Path.php tests/Canvas/PathTest.php
git commit -m "Add setTransform/getTransform to Path"
```

---

### Task 5: Canvas transform stack — save, restore, getTransform, setTransform, concatTransform

**Files:**
- Modify: `tests/Canvas/CanvasTest.php`
- Modify: `library/draw/Canvas.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Canvas/CanvasTest.php`. Add `use draw\Transform;` to the imports.

```php
    public function test_canvas_default_transform_is_identity(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $this->assertSame([1.0, 0.0, 0.0, 1.0, 0.0, 0.0], $canvas->getTransform()->getElements());
    }

    public function test_canvas_set_transform_replaces_ctm(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $t = Transform::translate(5.0, 10.0);
        $canvas->setTransform($t);
        $this->assertSame($t, $canvas->getTransform());
    }

    public function test_canvas_concat_transform_composes(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $canvas->concatTransform(Transform::translate(10.0, 0.0));
        $canvas->concatTransform(Transform::scale(2.0));
        [$x, $y] = $canvas->getTransform()->apply(5.0, 0.0);
        $this->assertEqualsWithDelta(20.0, $x, 0.0001);
    }

    public function test_canvas_save_restore(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $canvas->concatTransform(Transform::translate(5.0, 5.0));
        $canvas->save();
        $canvas->concatTransform(Transform::scale(2.0));
        $this->assertNotEquals(
            [1.0, 0.0, 0.0, 1.0, 5.0, 5.0],
            $canvas->getTransform()->getElements()
        );
        $canvas->restore();
        $this->assertSame(
            [1.0, 0.0, 0.0, 1.0, 5.0, 5.0],
            $canvas->getTransform()->getElements()
        );
    }

    public function test_canvas_restore_throws_on_empty_stack(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $this->expectException(\LogicException::class);
        $canvas->restore();
    }

    public function test_canvas_save_restore_nested(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $canvas->concatTransform(Transform::translate(1.0, 0.0));
        $canvas->save();
        $canvas->concatTransform(Transform::translate(2.0, 0.0));
        $canvas->save();
        $canvas->concatTransform(Transform::translate(3.0, 0.0));
        $canvas->restore();
        [$x, $y] = $canvas->getTransform()->apply(0.0, 0.0);
        $this->assertEqualsWithDelta(3.0, $x, 0.0001);
        $canvas->restore();
        [$x, $y] = $canvas->getTransform()->apply(0.0, 0.0);
        $this->assertEqualsWithDelta(1.0, $x, 0.0001);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Canvas/CanvasTest.php`
Expected: FAIL — method `getTransform` does not exist

- [ ] **Step 3: Write minimal implementation**

Add to `library/draw/Canvas.php` — add `use draw\Transform;` is not needed (same namespace).

Add properties after `$halfblocks`:

```php
    private Transform $ctm;
    /** @var array<int, Transform> */
    private array $transformStack = [];
```

Add initialization in `__construct` (after `readonly public bool $halfblocks = false`):

```php
        $this->ctm = Transform::identity();
```

Add methods before `drawPoint`:

```php
    public function save(): void
    {
        $this->transformStack[] = $this->ctm;
    }

    public function restore(): void
    {
        if (count($this->transformStack) === 0) {
            throw new \LogicException('Cannot restore: transform stack is empty');
        }
        $this->ctm = array_pop($this->transformStack);
    }

    public function getTransform(): Transform
    {
        return $this->ctm;
    }

    public function setTransform(Transform $t): void
    {
        $this->ctm = $t;
    }

    public function concatTransform(Transform $t): void
    {
        $this->ctm = $this->ctm->multiply($t);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- tests/Canvas/CanvasTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add library/draw/Canvas.php tests/Canvas/CanvasTest.php
git commit -m "Add transform stack to Canvas: save, restore, getTransform, setTransform, concatTransform"
```

---

### Task 6: Canvas convenience methods — translate, rotate, scale, skewX, skewY

**Files:**
- Modify: `tests/Canvas/CanvasTest.php`
- Modify: `library/draw/Canvas.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Canvas/CanvasTest.php`:

```php
    public function test_canvas_translate_convenience(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $canvas->translate(5.0, 10.0);
        [$x, $y] = $canvas->getTransform()->apply(0.0, 0.0);
        $this->assertEqualsWithDelta(5.0, $x, 0.0001);
        $this->assertEqualsWithDelta(10.0, $y, 0.0001);
    }

    public function test_canvas_rotate_convenience(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $canvas->rotate(M_PI / 2.0, 5.0, 5.0);
        [$x, $y] = $canvas->getTransform()->apply(6.0, 5.0);
        $this->assertEqualsWithDelta(5.0, $x, 0.0001);
        $this->assertEqualsWithDelta(6.0, $y, 0.0001);
    }

    public function test_canvas_scale_convenience(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $canvas->scale(2.0, 3.0);
        [$x, $y] = $canvas->getTransform()->apply(1.0, 1.0);
        $this->assertEqualsWithDelta(2.0, $x, 0.0001);
        $this->assertEqualsWithDelta(3.0, $y, 0.0001);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Canvas/CanvasTest.php`
Expected: FAIL — method `translate` does not exist

- [ ] **Step 3: Write minimal implementation**

Add to `library/draw/Canvas.php` after `concatTransform`:

```php
    public function translate(float $tx, float $ty): void
    {
        $this->concatTransform(Transform::translate($tx, $ty));
    }

    public function rotate(float $angle, float $cx = 0.0, float $cy = 0.0): void
    {
        $this->concatTransform(Transform::rotate($angle, $cx, $cy));
    }

    public function scale(float $sx, ?float $sy = null): void
    {
        $this->concatTransform(Transform::scale($sx, $sy));
    }

    public function skewX(float $angle): void
    {
        $this->concatTransform(Transform::skewX($angle));
    }

    public function skewY(float $angle): void
    {
        $this->concatTransform(Transform::skewY($angle));
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- tests/Canvas/CanvasTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add library/draw/Canvas.php tests/Canvas/CanvasTest.php
git commit -m "Add translate, rotate, scale, skewX, skewY convenience methods to Canvas"
```

---

### Task 7: Integrate transforms into drawing methods + remove drawLine/drawPolygon

This task does three things:
1. Add transform support to `drawPath` and `drawPoint`
2. Remove public `drawLine` and `drawPolygon` (replaced by `drawLineInternal` private helper)
3. Update all tests that used `drawLine`/`drawPolygon` to use `drawPath` + Path factories

**Files:**
- Modify: `library/draw/Canvas.php`
- Modify: `tests/Canvas/CanvasTest.php`
- Modify: `tests/Canvas/PathTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Canvas/PathTest.php` (add `use draw\Transform;` to imports):

```php
    public function test_draw_path_with_canvas_translate(): void
    {
        $path = Path::rect(0.0, 0.0, 4.0, 4.0);
        $canvas = Canvas::createBlank(20, 20);
        $canvas->translate(10.0, 10.0);
        $canvas->drawPath($path, new Color(4, null), null);
        $this->assertSame(4, $canvas->data[12][12]->fg);
        $this->assertNull($canvas->data[2][2]->fg);
    }

    public function test_draw_path_with_path_transform(): void
    {
        $path = Path::rect(0.0, 0.0, 4.0, 4.0);
        $path->setTransform(Transform::translate(10.0, 10.0));
        $canvas = Canvas::createBlank(20, 20);
        $canvas->drawPath($path, new Color(4, null), null);
        $this->assertSame(4, $canvas->data[12][12]->fg);
        $this->assertNull($canvas->data[2][2]->fg);
    }

    public function test_draw_path_with_both_transforms_composed(): void
    {
        $path = Path::rect(0.0, 0.0, 4.0, 4.0);
        $path->setTransform(Transform::translate(5.0, 0.0));
        $canvas = Canvas::createBlank(20, 20);
        $canvas->translate(5.0, 5.0);
        $canvas->drawPath($path, new Color(4, null), null);
        $this->assertSame(4, $canvas->data[7][12]->fg);
        $this->assertNull($canvas->data[2][2]->fg);
    }

    public function test_draw_path_with_canvas_rotate(): void
    {
        $path = Path::rect(-2.0, -2.0, 4.0, 4.0);
        $canvas = Canvas::createBlank(20, 20);
        $canvas->translate(10.0, 10.0);
        $canvas->drawPath($path, new Color(4, null), null);
        $this->assertSame(4, $canvas->data[10][10]->fg);
    }

    public function test_draw_path_identity_transform_is_noop(): void
    {
        $path = Path::rect(2.0, 2.0, 4.0, 4.0);
        $path->setTransform(Transform::identity());
        $canvas = Canvas::createBlank(10, 10);
        $canvas->drawPath($path, new Color(4, null), null);
        $this->assertSame(4, $canvas->data[4][4]->fg);
    }
```

Append to `tests/Canvas/CanvasTest.php` (add `use draw\Path;` and `use draw\Transform;` to imports):

```php
    public function test_draw_point_with_transform(): void
    {
        $canvas = Canvas::createBlank(20, 20);
        $canvas->translate(10.0, 5.0);
        $canvas->drawPoint(2, 3, new Color(4, null));
        $this->assertSame(4, $canvas->data[8][12]->fg);
        $this->assertNull($canvas->data[3][2]->fg);
    }

    public function test_draw_path_line_with_transform(): void
    {
        $canvas = Canvas::createBlank(20, 20);
        $canvas->translate(10.0, 0.0);
        $canvas->drawPath(Path::line(0, 5, 5, 5), null, new Color(4, null));
        $this->assertSame(4, $canvas->data[5][10]->fg);
        $this->assertSame(4, $canvas->data[5][15]->fg);
    }

    public function test_draw_path_polygon_with_transform(): void
    {
        $canvas = Canvas::createBlank(20, 20);
        $canvas->translate(5.0, 5.0);
        $canvas->drawPath(
            Path::polygon([[0.0, 0.0], [4.0, 0.0], [4.0, 4.0], [0.0, 4.0]]),
            new Color(4, null),
            null
        );
        $this->assertSame(4, $canvas->data[7][7]->fg);
        $this->assertNull($canvas->data[2][2]->fg);
    }
```

- [ ] **Step 2: Migrate existing tests from drawPolygon to drawPath(Path::polygon(...))**

In `tests/Canvas/CanvasTest.php`, replace every `$canvas->drawPolygon($points, ...)` call with `$canvas->drawPath(Path::polygon($points), ...)`. Add `use draw\Path;` to imports.

Specific replacements (each `drawPolygon` call site):

- `test_draw_polygon_with_both_colors_null_is_noop`: `$canvas->drawPath(Path::polygon([[1.0, 1.0], [5.0, 1.0], [5.0, 5.0], [1.0, 5.0]]), null, null)`
- `test_draw_polygon_outline_only_square`: `$canvas->drawPath(Path::polygon([[1.0, 1.0], [5.0, 1.0], [5.0, 5.0], [1.0, 5.0]]), null, $outline)`
- `test_draw_polygon_fill_only_square`: `$canvas->drawPath(Path::polygon([[1.0, 1.0], [5.0, 1.0], [5.0, 5.0], [1.0, 5.0]]), $fill, null)`
- `test_draw_polygon_fill_plus_outline_square`: `$canvas->drawPath(Path::polygon([[1.0, 1.0], [5.0, 1.0], [5.0, 5.0], [1.0, 5.0]]), $fill, $outline)`
- `test_draw_polygon_with_two_vertices_is_noop`: `$canvas->drawPath(Path::polygon([[1.0, 1.0], [5.0, 5.0]]), $color, $color)` — note: `Path::polygon` requires 2+ points so this still works, but drawPath with < 3 vertices won't fill or outline. Test behavior stays the same.
- `test_draw_polygon_with_one_vertex_is_noop`: This needs a different approach — `Path::polygon` rejects < 2 points. Replace with `Path::polygon([[5.0, 5.0], [5.0, 5.0]])` (degenerate 2-point polygon that drawPath won't render).
- `test_draw_polygon_with_zero_vertices_is_noop`: Create an empty Path — `$canvas->drawPath(new Path(), $color, $color)`
- `test_draw_polygon_star_corner_stranding_regression`: Replace `$canvas->drawPolygon($points, $fill, $outline)` with `$canvas->drawPath(Path::polygon($points), $fill, $outline)`
- `test_draw_polygon_outline_aligns_with_fill_no_horizontal_gaps`: Replace `$canvas->drawPolygon($points, new Color(3, null), new Color(5, null))` with `$canvas->drawPath(Path::polygon($points), new Color(3, null), new Color(5, null))`
- `test_draw_polygon_fully_outside_canvas_does_not_throw`: Replace both `drawPolygon` calls with `$canvas->drawPath(Path::polygon($points), $color, $color)`

In `tests/Canvas/PathTest.php`, rename the two tests that compare `drawPolygon` vs `drawPath` to just verify `drawPath`:

- `test_draw_path_fill_matches_draw_polygon`: This test compared drawPolygon output with drawPath. Since drawPolygon is being removed, change this test to verify drawPath produces expected fill pixels directly instead of comparing against drawPolygon.
- `test_draw_path_outline_matches_draw_polygon`: Same — verify drawPath outline directly.

Replace the bodies:

```php
    public function test_draw_path_fill_produces_expected_pixels(): void
    {
        $path = Path::polygon([[2.0, 2.0], [8.0, 2.0], [8.0, 6.0], [2.0, 6.0]]);
        $canvas = Canvas::createBlank(12, 12);
        $canvas->drawPath($path, new Color(3, null), null);
        $this->assertSame(3, $canvas->data[3][3]->fg);
        $this->assertSame(3, $canvas->data[4][5]->fg);
        $this->assertSame(3, $canvas->data[5][7]->fg);
        $this->assertNull($canvas->data[0][0]->fg);
        $this->assertNull($canvas->data[8][8]->fg);
    }

    public function test_draw_path_outline_produces_expected_pixels(): void
    {
        $path = Path::polygon([[2.0, 2.0], [8.0, 2.0], [8.0, 6.0], [2.0, 6.0]]);
        $canvas = Canvas::createBlank(12, 12);
        $canvas->drawPath($path, null, new Color(5, null));
        $this->assertSame(5, $canvas->data[2][2]->fg);
        $this->assertSame(5, $canvas->data[2][8]->fg);
        $this->assertSame(5, $canvas->data[6][2]->fg);
        $this->assertSame(5, $canvas->data[6][8]->fg);
        $this->assertNull($canvas->data[4][5]->fg);
    }
```

- [ ] **Step 3: Migrate external callers — artbot_scripts/drawing.php**

In the `lines` function (line 42), replace:
```php
$art->drawLine($sx, $sy, $ex, $ey, $color);
```
with:
```php
$art->drawPath(Path::line($sx, $sy, $ex, $ey), null, $color);
```

- [ ] **Step 4: Migrate external callers — scripts/stocks/stocks.php**

Replace the box border (lines 244-247):
```php
$canvas->drawLine(      0,        0,       0, $h - 1, new draw\Color(14));
$canvas->drawLine( $w - 1,        0,  $w - 1, $h - 1, new draw\Color(14));
$canvas->drawLine(      0,        0,  $w - 1,      0, new draw\Color(14));
$canvas->drawLine(      0,   $h - 1,  $w - 1, $h - 1, new draw\Color(14));
```
with:
```php
$canvas->drawPath(draw\Path::line(      0,        0,       0, $h - 1), null, new draw\Color(14));
$canvas->drawPath(draw\Path::line( $w - 1,        0,  $w - 1, $h - 1), null, new draw\Color(14));
$canvas->drawPath(draw\Path::line(      0,        0,  $w - 1,      0), null, new draw\Color(14));
$canvas->drawPath(draw\Path::line(      0,   $h - 1,  $w - 1, $h - 1), null, new draw\Color(14));
```

Replace the price line (line 286):
```php
$canvas->drawLine($i+1,$ly,$i,$y, $color);
```
with:
```php
$canvas->drawPath(draw\Path::line($i+1, $ly, $i, $y), null, $color);
```

Add `use draw\Path;` is not needed — already using `draw\` namespace prefix. Use `draw\Path::line(...)`.

- [ ] **Step 5: Run test to verify tests fail before implementation**

Run: `composer test -- tests/Canvas/PathTest.php --filter test_draw_path_with_canvas_translate`
Expected: FAIL — drawPath ignores the CTM

- [ ] **Step 6: Write implementation — rewrite Canvas.php**

The full `Canvas.php` after this task has these public methods:
- `createBlank`, `createFromArt`, `__toString`
- `save`, `restore`, `getTransform`, `setTransform`, `concatTransform`
- `translate`, `rotate`, `scale`, `skewX`, `skewY`
- `drawPoint` — applies CTM, rounds to int, sets pixel
- `fillColor` — unchanged (flood fill)
- `overlay` — unchanged
- `drawPath` — composes CTM × pathTransform, flattens, transforms vertices, rasterizes

Private methods:
- `drawLineInternal` — Bresenham without transform
- `isIdentity` — checks if Transform is identity
- `fillPolygonScanline` — unchanged
- `fillPolygonScanlineMulti` — unchanged

**Delete** public `drawLine` and `drawPolygon` methods entirely.

Replace the body of `drawPoint`:

```php
    public function drawPoint(int|float $x, int|float $y, Color $color, string $text = ''): void
    {
        if (!$this->isIdentity($this->ctm)) {
            [$x, $y] = $this->ctm->apply((float) $x, (float) $y);
        }
        $x = (int) round($x);
        $y = (int) round($y);
        if (isset($this->data[$y][$x])) {
            $this->data[$y][$x]->fg = $color->fg;
            $this->data[$y][$x]->bg = $color->bg;
            if ($text != '') {
                $this->data[$y][$x]->text = $text;
            }
        }
    }
```

Replace `drawPath` — compose CTM × pathTransform, apply to all vertices, use `drawLineInternal` for outline:

```php
    public function drawPath(
        Path $path,
        ?Color $fillColor,
        ?Color $outlineColor,
        string $text = ''
    ): void {
        $subpaths = $path->flatten();
        if (count($subpaths) === 0) {
            return;
        }
        if ($fillColor === null && $outlineColor === null) {
            return;
        }

        $effective = $this->ctm;
        $pathTransform = $path->getTransform();
        if ($pathTransform !== null) {
            $effective = $effective->multiply($pathTransform);
        }

        $needTransform = !$this->isIdentity($effective);

        $snappedSubpaths = [];
        foreach ($subpaths as $sp) {
            $snapped = [];
            foreach ($sp['vertices'] as $v) {
                if ($needTransform) {
                    $v = $effective->apply($v[0], $v[1]);
                }
                $snapped[] = [(int) round($v[0]), (int) round($v[1])];
            }
            $snappedSubpaths[] = ['vertices' => $snapped, 'closed' => $sp['closed']];
        }

        if ($fillColor !== null) {
            $polygonArrays = [];
            foreach ($snappedSubpaths as $sp) {
                if (count($sp['vertices']) >= 3) {
                    $polygonArrays[] = $sp['vertices'];
                }
            }
            if (count($polygonArrays) > 0) {
                $this->fillPolygonScanlineMulti($polygonArrays, $fillColor, $text);
            }
        }

        if ($outlineColor !== null) {
            foreach ($snappedSubpaths as $sp) {
                $vertices = $sp['vertices'];
                $n = count($vertices);
                if ($n < 2) {
                    continue;
                }
                for ($i = 1; $i < $n; $i++) {
                    $this->drawLineInternal(
                        $vertices[$i - 1][0],
                        $vertices[$i - 1][1],
                        $vertices[$i][0],
                        $vertices[$i][1],
                        $outlineColor,
                        $text
                    );
                }
                if ($sp['closed']) {
                    $this->drawLineInternal(
                        $vertices[$n - 1][0],
                        $vertices[$n - 1][1],
                        $vertices[0][0],
                        $vertices[0][1],
                        $outlineColor,
                        $text
                    );
                }
            }
        }
    }
```

Add private helpers:

```php
    private function isIdentity(Transform $t): bool
    {
        $e = $t->getElements();
        return $e[0] === 1.0 && $e[1] === 0.0 && $e[2] === 0.0
            && $e[3] === 1.0 && $e[4] === 0.0 && $e[5] === 0.0;
    }

    private function drawLineInternal(int $startX, int $startY, int $endX, int $endY, Color $color, string $text = ''): void
    {
        $dx = abs($endX - $startX);
        $dy = abs($endY - $startY);
        $sx = ($startX < $endX ? 1 : -1);
        $sy = ($startY < $endY ? 1 : -1);
        $error = ($dx > $dy ? $dx : - $dy) / 2;
        $x = $startX;
        $y = $startY;
        $cnt = 0;
        while ($cnt++ < 1000) {
            if (isset($this->data[$y][$x])) {
                $this->data[$y][$x]->fg = $color->fg;
                $this->data[$y][$x]->bg = $color->bg;
                if ($text != '') {
                    $this->data[$y][$x]->text = $text;
                }
            }
            if ($x == $endX && $y == $endY) {
                break;
            }
            $e2 = $error;
            if ($e2 > -$dx) {
                $error -= $dy;
                $x += $sx;
            }
            if ($e2 < $dy) {
                $error += $dx;
                $y += $sy;
            }
        }
    }
```

- [ ] **Step 7: Run all tests to verify they pass**

Run: `composer test`
Expected: All tests PASS

- [ ] **Step 8: Commit**

```bash
git add library/draw/Canvas.php tests/Canvas/CanvasTest.php tests/Canvas/PathTest.php artbot_scripts/drawing.php scripts/stocks/stocks.php
git commit -m "Integrate transforms, remove drawLine/drawPolygon, migrate callers to drawPath"
```

---

### Task 8: PHPStan check and final verification

**Files:**
- May modify: `library/draw/Transform.php` or `library/draw/Canvas.php` if type issues found

- [ ] **Step 1: Run full test suite**

Run: `composer test`
Expected: All tests PASS

- [ ] **Step 2: Run PHPStan**

Run: `composer phpstan`
Expected: 666 errors (same as baseline — no increase)

- [ ] **Step 3: Fix any issues if needed, then commit**

```bash
git add -u
git commit -m "Fix PHPStan issues from Transform integration"
```

(Only if changes were needed)

---

### Task 9: Art command demo using transforms

**Files:**
- Modify: `artbot_scripts/drawing.php`

- [ ] **Step 1: Add a `transform` demo to the `$demos` array and helper function**

Add `'transform'` to the `$demos` array in the `demo` command, and add the function:

```php
function demoTransform(Canvas $art): void
{
    $colors = [4, 7, 8, 9, 11, 12, 13];
    $cx = 40;
    $cy = 20;
    $numSpokes = rand(6, 12);
    $spokeLen = rand(10, 18);
    $spokeWidth = rand(2, 4);
    $fillColor = new Color($colors[array_rand($colors)], null);
    $outlineColor = new Color($colors[array_rand($colors)], null);
    for ($i = 0; $i < $numSpokes; $i++) {
        $art->save();
        $art->translate((float) $cx, (float) $cy);
        $art->rotate((2.0 * M_PI * $i) / $numSpokes);
        $spoke = Path::rect(-$spokeWidth, -$spokeLen, $spokeWidth * 2, $spokeLen);
        $art->drawPath($spoke, $fillColor, $outlineColor);
        $art->restore();
    }
    $art->drawPath(Path::circle($cx, $cy, 3), new Color($colors[array_rand($colors)], null), null);
}
```

- [ ] **Step 2: Run tests**

Run: `composer test`
Expected: All tests PASS

- [ ] **Step 3: Commit**

```bash
git add artbot_scripts/drawing.php
git commit -m "Add transform demo art command"
```
