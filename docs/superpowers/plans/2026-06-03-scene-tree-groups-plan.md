# Scene Tree / Groups Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a scene tree layer with Group and Shape nodes, property inheritance, and rendering to Canvas.

**Architecture:** Four new files — `SceneNode` interface, `Group` class, `Shape` class, `RenderContext` value object. No changes to existing files. Groups nest children and inherit style properties (fill, stroke, transform, opacity) down the tree. Rendering walks the tree, calling existing Canvas methods.

**Tech Stack:** PHP 8.1+, PHPUnit 10, existing `library/draw/` types (Canvas, Path, Paint, StrokeStyle, Transform, FillRule, Color).

**Spec:** `docs/superpowers/specs/2026-06-03-scene-tree-groups-design.md`

---

### Task 1: RenderContext

**Files:**
- Create: `library/draw/RenderContext.php`
- Test: `tests/Canvas/SceneTreeTest.php`

- [ ] **Step 1: Write failing tests for RenderContext defaults and merge**

```php
<?php

namespace Tests\Canvas;

use draw\Color;
use draw\FillRule;
use draw\RenderContext;
use draw\Transform;
use PHPUnit\Framework\TestCase;

class SceneTreeTest extends TestCase
{
    public function test_render_context_defaults(): void
    {
        $ctx = RenderContext::defaults();
        $this->assertInstanceOf(Color::class, $ctx->fill);
        $this->assertNull($ctx->stroke);
        $this->assertTrue(Transform::identity()->equals($ctx->transform));
        $this->assertSame(1.0, $ctx->opacity);
        $this->assertSame(1.0, $ctx->fillOpacity);
        $this->assertSame(FillRule::NonZero, $ctx->fillRule);
    }

    public function test_render_context_merge_no_overrides(): void
    {
        $ctx = RenderContext::defaults();
        $merged = $ctx->merge(fill: null, stroke: null, transform: null, opacity: null, fillOpacity: null, fillRule: null);
        $this->assertEquals($ctx->fill, $merged->fill);
        $this->assertNull($merged->stroke);
        $this->assertSame($ctx->opacity, $merged->opacity);
    }

    public function test_render_context_merge_overrides_fill(): void
    {
        $ctx = RenderContext::defaults();
        $newFill = new Color(4, null);
        $merged = $ctx->merge(fill: $newFill);
        $this->assertSame($newFill, $merged->fill);
    }

    public function test_render_context_merge_overrides_stroke(): void
    {
        $ctx = RenderContext::defaults();
        $stroke = new \draw\StrokeStyle(new Color(9, null));
        $merged = $ctx->merge(stroke: $stroke);
        $this->assertSame($stroke, $merged->stroke);
    }

    public function test_render_context_merge_composes_transform(): void
    {
        $ctx = RenderContext::defaults();
        $t = Transform::translate(10.0, 20.0);
        $merged = $ctx->merge(transform: $t);
        $this->assertTrue($t->equals($merged->transform));
    }

    public function test_render_context_merge_multiplies_opacity(): void
    {
        $ctx = RenderContext::defaults()->merge(opacity: 0.5);
        $this->assertSame(0.5, $ctx->opacity);
        $merged = $ctx->merge(opacity: 0.8);
        $this->assertSame(0.4, $merged->opacity);
    }

    public function test_render_context_merge_multiplies_fill_opacity(): void
    {
        $ctx = RenderContext::defaults()->merge(fillOpacity: 0.5);
        $this->assertSame(0.5, $ctx->fillOpacity);
        $merged = $ctx->merge(fillOpacity: 0.6);
        $this->assertSame(0.3, $merged->fillOpacity);
    }

    public function test_render_context_merge_overrides_fill_rule(): void
    {
        $ctx = RenderContext::defaults();
        $merged = $ctx->merge(fillRule: FillRule::EvenOdd);
        $this->assertSame(FillRule::EvenOdd, $merged->fillRule);
    }

    public function test_render_context_is_immutable(): void
    {
        $ctx = RenderContext::defaults();
        $merged = $ctx->merge(fill: new Color(4, null));
        $this->assertNotSame($ctx, $merged);
        $this->assertNotSame($ctx->fill, $merged->fill);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Canvas/SceneTreeTest.php`
Expected: FAIL — class `draw\RenderContext` not found

- [ ] **Step 3: Implement RenderContext**

```php
<?php

namespace draw;

class RenderContext
{
    public function __construct(
        public readonly Paint $fill,
        public readonly ?StrokeStyle $stroke,
        public readonly Transform $transform,
        public readonly float $opacity,
        public readonly float $fillOpacity,
        public readonly FillRule $fillRule,
    ) {
    }

    public static function defaults(): self
    {
        return new self(
            fill: new Color(0, null),
            stroke: null,
            transform: Transform::identity(),
            opacity: 1.0,
            fillOpacity: 1.0,
            fillRule: FillRule::NonZero,
        );
    }

    public function merge(
        ?Paint $fill = null,
        ?StrokeStyle $stroke = null,
        ?Transform $transform = null,
        ?float $opacity = null,
        ?float $fillOpacity = null,
        ?FillRule $fillRule = null,
    ): self {
        return new self(
            fill: $fill ?? $this->fill,
            stroke: $stroke ?? $this->stroke,
            transform: $transform !== null
                ? $this->transform->multiply($transform)
                : $this->transform,
            opacity: $opacity !== null
                ? $this->opacity * $opacity
                : $this->opacity,
            fillOpacity: $fillOpacity !== null
                ? $this->fillOpacity * $fillOpacity
                : $this->fillOpacity,
            fillRule: $fillRule ?? $this->fillRule,
        );
    }
}
```

Also add `equals()` to Transform if not present (needed by tests):

Check if `Transform::equals()` exists. If not, add:

```php
public function equals(Transform $other): bool
{
    return abs($this->a - $other->a) < 1e-10
        && abs($this->b - $other->b) < 1e-10
        && abs($this->c - $other->c) < 1e-10
        && abs($this->d - $other->d) < 1e-10
        && abs($this->e - $other->e) < 1e-10
        && abs($this->f - $other->f) < 1e-10;
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test -- tests/Canvas/SceneTreeTest.php`
Expected: 9 PASS

- [ ] **Step 5: Run full test suite**

Run: `composer test`
Expected: All existing tests still pass + 9 new

- [ ] **Step 6: Commit**

```bash
git add library/draw/RenderContext.php library/draw/Transform.php tests/Canvas/SceneTreeTest.php
git commit -m "feat(draw): add RenderContext immutable value object for scene tree inheritance"
```

---

### Task 2: SceneNode interface and Shape

**Files:**
- Create: `library/draw/SceneNode.php`
- Create: `library/draw/Shape.php`
- Modify: `tests/Canvas/SceneTreeTest.php`

- [ ] **Step 1: Write failing tests for Shape**

Add to `tests/Canvas/SceneTreeTest.php`:

```php
use draw\Canvas;
use draw\Path;
use draw\SceneNode;
use draw\Shape;

public function test_shape_has_no_children(): void
{
    $shape = new Shape(path: Path::circle(5.0, 5.0, 3.0));
    $this->assertSame([], $shape->getChildren());
}

public function test_shape_implements_scene_node(): void
{
    $shape = new Shape(path: Path::rect(0.0, 0.0, 10.0, 10.0));
    $this->assertInstanceOf(SceneNode::class, $shape);
}

public function test_shape_render_uses_inherited_fill(): void
{
    $canvas = Canvas::createBlank(20, 10, true);
    $canvas->fillColor(0, 0, new Color(1, 1));
    $shape = new Shape(path: Path::rect(2.0, 1.0, 5.0, 3.0));
    $ctx = RenderContext::defaults();
    $shape->render($canvas, $ctx);
    $this->assertNotNull($canvas->data[2][4]->fg);
}

public function test_shape_render_with_own_fill_overrides_inherited(): void
{
    $canvas = Canvas::createBlank(20, 10, true);
    $canvas->fillColor(0, 0, new Color(1, 1));
    $fill = new Color(4, null);
    $shape = new Shape(path: Path::rect(2.0, 1.0, 5.0, 3.0), fill: $fill);
    $ctx = RenderContext::defaults();
    $shape->render($canvas, $ctx);
    $this->assertSame(4, $canvas->data[2][4]->fg);
}

public function test_shape_render_with_transform(): void
{
    $canvas = Canvas::createBlank(20, 10, true);
    $canvas->fillColor(0, 0, new Color(1, 1));
    $shape = new Shape(
        path: Path::rect(0.0, 0.0, 3.0, 3.0),
        fill: new Color(9, null),
        transform: Transform::translate(5.0, 3.0),
    );
    $shape->render($canvas, RenderContext::defaults());
    $this->assertSame(9, $canvas->data[4][6]->fg);
}

public function test_shape_render_with_opacity(): void
{
    $canvas = Canvas::createBlank(20, 10, true);
    $canvas->fillColor(0, 0, new Color(1, 1));
    $shape = new Shape(
        path: Path::rect(0.0, 0.0, 5.0, 5.0),
        fill: new Color(4, null),
        opacity: 0.0,
    );
    $shape->render($canvas, RenderContext::defaults());
    $this->assertNull($canvas->data[2][2]->fg);
}

public function test_shape_render_null_fill_and_stroke_is_noop(): void
{
    $canvas = Canvas::createBlank(20, 10, true);
    $canvas->fillColor(0, 0, new Color(1, 1));
    $ctx = new RenderContext(
        fill: new Color(0, null),
        stroke: null,
        transform: Transform::identity(),
        opacity: 0.0,
        fillOpacity: 0.0,
        fillRule: FillRule::NonZero,
    );
    $shape = new Shape(path: Path::rect(2.0, 1.0, 5.0, 3.0), fill: new Color(4, null));
    $shape->render($canvas, $ctx);
    $this->assertNull($canvas->data[2][4]->fg);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- tests/Canvas/SceneTreeTest.php`
Expected: FAIL — class `draw\SceneNode` not found

- [ ] **Step 3: Implement SceneNode interface**

```php
<?php

namespace draw;

interface SceneNode
{
    /** @return array<SceneNode> */
    public function getChildren(): array;

    public function render(Canvas $canvas, RenderContext $ctx): void;
}
```

- [ ] **Step 4: Implement Shape**

```php
<?php

namespace draw;

class Shape implements SceneNode
{
    public function __construct(
        public readonly Path $path,
        public readonly ?Paint $fill = null,
        public readonly ?StrokeStyle $stroke = null,
        public readonly ?Transform $transform = null,
        public readonly ?float $opacity = null,
        public readonly ?float $fillOpacity = null,
        public readonly ?FillRule $fillRule = null,
    ) {
    }

    public function getChildren(): array
    {
        return [];
    }

    public function render(Canvas $canvas, RenderContext $ctx): void
    {
        $effective = $ctx->merge(
            fill: $this->fill,
            stroke: $this->stroke,
            transform: $this->transform,
            opacity: $this->opacity,
            fillOpacity: $this->fillOpacity,
            fillRule: $this->fillRule,
        );

        if ($effective->fill === null && $effective->stroke === null) {
            return;
        }

        if ($effective->opacity < 0.001 && ($effective->stroke === null || $effective->stroke->opacity < 0.001)) {
            return;
        }

        $canvas->save();

        if ($this->transform !== null) {
            $canvas->concatTransform($this->transform);
        }

        $canvas->drawPath(
            $this->path,
            $effective->fill,
            $effective->stroke,
            '',
            $effective->fillRule,
            $effective->fillOpacity,
            $effective->opacity,
        );

        $canvas->restore();
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `composer test -- tests/Canvas/SceneTreeTest.php`
Expected: All shape tests PASS

- [ ] **Step 6: Run full test suite**

Run: `composer test`
Expected: All pass

- [ ] **Step 7: Commit**

```bash
git add library/draw/SceneNode.php library/draw/Shape.php tests/Canvas/SceneTreeTest.php
git commit -m "feat(draw): add SceneNode interface and Shape leaf node"
```

---

### Task 3: Group

**Files:**
- Create: `library/draw/Group.php`
- Modify: `tests/Canvas/SceneTreeTest.php`

- [ ] **Step 1: Write failing tests for Group**

Add to `tests/Canvas/SceneTreeTest.php`:

```php
use draw\Group;

public function test_group_implements_scene_node(): void
{
    $group = new Group();
    $this->assertInstanceOf(SceneNode::class, $group);
}

public function test_group_add_and_get_children(): void
{
    $group = new Group();
    $shape = new Shape(path: Path::circle(0.0, 0.0, 1.0));
    $group->addChild($shape);
    $this->assertSame([$shape], $group->getChildren());
}

public function test_group_remove_child(): void
{
    $group = new Group();
    $shape = new Shape(path: Path::circle(0.0, 0.0, 1.0));
    $group->addChild($shape);
    $group->removeChild($shape);
    $this->assertSame([], $group->getChildren());
}

public function test_group_inherits_fill_to_children(): void
{
    $canvas = Canvas::createBlank(20, 10, true);
    $canvas->fillColor(0, 0, new Color(1, 1));
    $group = new Group(fill: new Color(4, null));
    $group->addChild(new Shape(path: Path::rect(2.0, 1.0, 5.0, 3.0)));
    $group->render($canvas, RenderContext::defaults());
    $this->assertSame(4, $canvas->data[2][4]->fg);
}

public function test_group_child_overrides_inherited_fill(): void
{
    $canvas = Canvas::createBlank(20, 10, true);
    $canvas->fillColor(0, 0, new Color(1, 1));
    $group = new Group(fill: new Color(4, null));
    $group->addChild(new Shape(
        path: Path::rect(2.0, 1.0, 5.0, 3.0),
        fill: new Color(9, null),
    ));
    $group->render($canvas, RenderContext::defaults());
    $this->assertSame(9, $canvas->data[2][4]->fg);
}

public function test_group_transform_applies_to_children(): void
{
    $canvas = Canvas::createBlank(30, 15, true);
    $canvas->fillColor(0, 0, new Color(1, 1));
    $group = new Group(transform: Transform::translate(10.0, 5.0));
    $group->addChild(new Shape(
        path: Path::rect(0.0, 0.0, 3.0, 3.0),
        fill: new Color(4, null),
    ));
    $group->render($canvas, RenderContext::defaults());
    $this->assertSame(4, $canvas->data[6][10]->fg);
}

public function test_group_opacity_multiplies_to_children(): void
{
    $canvas = Canvas::createBlank(20, 10, true);
    $canvas->fillColor(0, 0, new Color(1, 1));
    $group = new Group(opacity: 0.0);
    $group->addChild(new Shape(
        path: Path::rect(2.0, 1.0, 5.0, 3.0),
        fill: new Color(4, null),
    ));
    $group->render($canvas, RenderContext::defaults());
    $this->assertNull($canvas->data[2][4]->fg);
}

public function test_group_nested_inherits_properties(): void
{
    $canvas = Canvas::createBlank(30, 15, true);
    $canvas->fillColor(0, 0, new Color(1, 1));
    $outer = new Group(
        fill: new Color(4, null),
        transform: Transform::translate(5.0, 3.0),
    );
    $inner = new Group(transform: Transform::translate(3.0, 2.0));
    $inner->addChild(new Shape(path: Path::rect(0.0, 0.0, 2.0, 2.0)));
    $outer->addChild($inner);
    $outer->render($canvas, RenderContext::defaults());
    $this->assertSame(4, $canvas->data[5][8]->fg);
}

public function test_group_with_multiple_children_renders_all(): void
{
    $canvas = Canvas::createBlank(30, 10, true);
    $canvas->fillColor(0, 0, new Color(1, 1));
    $group = new Group(fill: new Color(4, null));
    $group->addChild(new Shape(path: Path::rect(2.0, 2.0, 3.0, 3.0)));
    $group->addChild(new Shape(path: Path::rect(10.0, 2.0, 3.0, 3.0)));
    $group->render($canvas, RenderContext::defaults());
    $this->assertSame(4, $canvas->data[3][3]->fg);
    $this->assertSame(4, $canvas->data[3][11]->fg);
}

public function test_group_restores_canvas_state_after_render(): void
{
    $canvas = Canvas::createBlank(20, 10, true);
    $canvas->fillColor(0, 0, new Color(1, 1));
    $beforeTransform = $canvas->getTransform();
    $group = new Group(transform: Transform::translate(5.0, 3.0));
    $group->addChild(new Shape(path: Path::rect(0.0, 0.0, 2.0, 2.0), fill: new Color(4, null)));
    $group->render($canvas, RenderContext::defaults());
    $this->assertTrue($beforeTransform->equals($canvas->getTransform()));
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- tests/Canvas/SceneTreeTest.php`
Expected: FAIL — class `draw\Group` not found

- [ ] **Step 3: Implement Group**

```php
<?php

namespace draw;

class Group implements SceneNode
{
    /** @var array<SceneNode> */
    private array $children = [];

    public function __construct(
        public readonly ?Paint $fill = null,
        public readonly ?StrokeStyle $stroke = null,
        public readonly ?Transform $transform = null,
        public readonly ?float $opacity = null,
        public readonly ?float $fillOpacity = null,
        public readonly ?FillRule $fillRule = null,
    ) {
    }

    public function addChild(SceneNode $node): void
    {
        $this->children[] = $node;
    }

    public function removeChild(SceneNode $node): void
    {
        foreach ($this->children as $i => $child) {
            if ($child === $node) {
                array_splice($this->children, $i, 1);
                return;
            }
        }
    }

    public function getChildren(): array
    {
        return $this->children;
    }

    public function render(Canvas $canvas, RenderContext $ctx): void
    {
        $childCtx = $ctx->merge(
            fill: $this->fill,
            stroke: $this->stroke,
            transform: $this->transform,
            opacity: $this->opacity,
            fillOpacity: $this->fillOpacity,
            fillRule: $this->fillRule,
        );

        $canvas->save();

        if ($this->transform !== null) {
            $canvas->concatTransform($this->transform);
        }

        foreach ($this->children as $child) {
            $child->render($canvas, $childCtx);
        }

        $canvas->restore();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test -- tests/Canvas/SceneTreeTest.php`
Expected: All group tests PASS

- [ ] **Step 5: Run full test suite**

Run: `composer test`
Expected: All pass

- [ ] **Step 6: Commit**

```bash
git add library/draw/Group.php tests/Canvas/SceneTreeTest.php
git commit -m "feat(draw): add Group scene node with property inheritance"
```

---

### Task 4: Integration test — complex scene

**Files:**
- Modify: `tests/Canvas/SceneTreeTest.php`

- [ ] **Step 1: Write integration test for a multi-level scene**

Add to `tests/Canvas/SceneTreeTest.php`:

```php
public function test_complex_scene_nested_groups_with_overrides(): void
{
    $canvas = Canvas::createBlank(40, 20, true);
    $canvas->fillColor(0, 0, new Color(1, 1));

    $root = new Group(
        fill: new Color(4, null),
        transform: Transform::translate(2.0, 1.0),
        opacity: 0.8,
    );

    $bgGroup = new Group();
    $bgGroup->addChild(new Shape(path: Path::rect(0.0, 0.0, 10.0, 5.0)));
    $bgGroup->addChild(new Shape(path: Path::rect(12.0, 0.0, 10.0, 5.0)));

    $fgGroup = new Group(
        fill: new Color(9, null),
        opacity: 0.5,
    );
    $fgGroup->addChild(new Shape(path: Path::rect(5.0, 2.0, 4.0, 3.0)));
    $fgGroup->addChild(new Shape(
        path: Path::circle(20.0, 4.0, 2.0),
        fill: new Color(11, null),
        opacity: 1.0,
    ));

    $root->addChild($bgGroup);
    $root->addChild($fgGroup);

    $root->render($canvas, RenderContext::defaults());

    $this->assertNotNull($canvas->data[3][5]->fg, 'bg rect rendered');
    $this->assertNotNull($canvas->data[3][15]->fg, 'bg rect 2 rendered');
    $this->assertNotNull($canvas->data[4][8]->fg, 'fg rect rendered');
    $this->assertNotNull($canvas->data[5][22]->fg, 'circle rendered');
}

public function test_scene_with_stroke_inheritance(): void
{
    $canvas = Canvas::createBlank(20, 10, true);
    $canvas->fillColor(0, 0, new Color(1, 1));

    $group = new Group(
        stroke: new StrokeStyle(new Color(4, null), width: 1.0),
    );
    $group->addChild(new Shape(path: Path::rect(3.0, 2.0, 5.0, 3.0), fill: null));

    $group->render($canvas, RenderContext::defaults());

    $this->assertNotNull($canvas->data[2][3]->fg, 'stroke rendered');
}

public function test_empty_group_is_noop(): void
{
    $canvas = Canvas::createBlank(20, 10, true);
    $canvas->fillColor(0, 0, new Color(1, 1));
    $before = $canvas->data[0][0]->fg;

    $group = new Group(fill: new Color(4, null));
    $group->render($canvas, RenderContext::defaults());

    $this->assertSame($before, $canvas->data[0][0]->fg);
}

public function test_scene_renders_children_in_order(): void
{
    $canvas = Canvas::createBlank(20, 10, true);
    $canvas->fillColor(0, 0, new Color(1, 1));

    $group = new Group();
    $group->addChild(new Shape(
        path: Path::rect(5.0, 2.0, 5.0, 5.0),
        fill: new Color(4, null),
    ));
    $group->addChild(new Shape(
        path: Path::rect(5.0, 2.0, 5.0, 5.0),
        fill: new Color(9, null),
    ));

    $group->render($canvas, RenderContext::defaults());

    $this->assertSame(9, $canvas->data[4][7]->fg, 'second child overwrites first');
}
```

- [ ] **Step 2: Run all tests**

Run: `composer test`
Expected: All pass

- [ ] **Step 3: Commit**

```bash
git add tests/Canvas/SceneTreeTest.php
git commit -m "test(draw): add scene tree integration tests for complex nesting"
```
