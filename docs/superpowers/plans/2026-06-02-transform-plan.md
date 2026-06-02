# Transform Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a 2×3 affine Transform class with Canvas transform stack (save/restore) and per-Path transform support.

**Architecture:** Immutable `Transform` value object. Canvas holds a CTM (current transform matrix) with save/restore stack. Path carries an optional transform. Drawing methods compose `CTM × pathTransform` and apply to all vertices before rasterization.

**Tech Stack:** PHP 8.1+, PHPUnit 10, existing `draw\` namespace classes.

**Spec:** `docs/superpowers/specs/2026-06-02-transform-design.md`

---

## File Structure

| File | Responsibility |
|------|---------------|
| `library/draw/Transform.php` | Immutable 2×3 affine matrix value object |
| `library/draw/Canvas.php` | Add CTM, transform stack, apply transforms in draw methods |
| `library/draw/Path.php` | Add `setTransform`/`getTransform` |
| `tests/Canvas/TransformTest.php` | Unit tests for Transform class |
| `tests/Canvas/CanvasTest.php` | Add transform integration tests |

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

### Task 7: Integrate transforms into drawing methods

**Files:**
- Modify: `library/draw/Canvas.php`

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

    public function test_draw_line_with_transform(): void
    {
        $canvas = Canvas::createBlank(20, 20);
        $canvas->translate(10.0, 0.0);
        $canvas->drawLine(0, 5, 5, 5, new Color(4, null));
        $this->assertSame(4, $canvas->data[5][10]->fg);
        $this->assertSame(4, $canvas->data[5][15]->fg);
    }

    public function test_draw_polygon_with_transform(): void
    {
        $canvas = Canvas::createBlank(20, 20);
        $canvas->translate(5.0, 5.0);
        $canvas->drawPolygon(
            [[0.0, 0.0], [4.0, 0.0], [4.0, 4.0], [0.0, 4.0]],
            new Color(4, null),
            null
        );
        $this->assertSame(4, $canvas->data[7][7]->fg);
        $this->assertNull($canvas->data[2][2]->fg);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Canvas/PathTest.php --filter test_draw_path_with_canvas_translate`
Expected: FAIL — drawPath ignores the CTM

- [ ] **Step 3: Write minimal implementation**

Modify `library/draw/Canvas.php` — replace the body of `drawPoint`:

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

Modify `drawLine` — transform the endpoints then call drawPoint-style logic (but keep Bresenham working with integer coords after transform). Replace the body of `drawLine`:

```php
    public function drawLine(int|float $startX, int|float $startY, int|float $endX, int|float $endY, Color $color, string $text = ''): void
    {
        if (!$this->isIdentity($this->ctm)) {
            [$startX, $startY] = $this->ctm->apply((float) $startX, (float) $startY);
            [$endX, $endY] = $this->ctm->apply((float) $endX, (float) $endY);
        }
        $startX = (int) round($startX);
        $startY = (int) round($startY);
        $endX = (int) round($endX);
        $endY = (int) round($endY);
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

Modify `drawPolygon` — transform points before snapping:

```php
    public function drawPolygon(
        array $points,
        ?Color $fillColor,
        ?Color $outlineColor,
        string $text = ''
    ): void {
        if (count($points) < 3) {
            return;
        }
        if ($fillColor === null && $outlineColor === null) {
            return;
        }

        if (!$this->isIdentity($this->ctm)) {
            $transformed = [];
            foreach ($points as $point) {
                $transformed[] = $this->ctm->apply((float) $point[0], (float) $point[1]);
            }
            $points = $transformed;
        }

        $snapped = [];
        foreach ($points as $point) {
            $snapped[] = [(int) round($point[0]), (int) round($point[1])];
        }

        if ($fillColor !== null) {
            $this->fillPolygonScanline($snapped, $fillColor, $text);
        }

        if ($outlineColor !== null) {
            $firstX = null;
            $firstY = null;
            $prevX = null;
            $prevY = null;
            foreach ($snapped as [$x, $y]) {
                if ($firstX === null) {
                    $firstX = $x;
                    $firstY = $y;
                } else {
                    $this->drawLineInternal($prevX, $prevY, $x, $y, $outlineColor, $text);
                }
                $prevX = $x;
                $prevY = $y;
            }
            if ($prevX !== $firstX || $prevY !== $firstY) {
                $this->drawLineInternal($prevX, $prevY, $firstX, $firstY, $outlineColor, $text);
            }
        }
    }
```

Add private helpers `isIdentity` (checks if a Transform is identity) and `drawLineInternal` (Bresenham without transform, used by drawPolygon and drawPath after points are already transformed):

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

Modify `drawPath` — compose CTM × pathTransform, apply to all vertices:

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

- [ ] **Step 4: Run all tests to verify they pass**

Run: `composer test`
Expected: All tests PASS (83 existing + new transform tests)

- [ ] **Step 5: Commit**

```bash
git add library/draw/Canvas.php tests/Canvas/CanvasTest.php tests/Canvas/PathTest.php
git commit -m "Integrate transforms into drawPoint, drawLine, drawPolygon, drawPath"
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
