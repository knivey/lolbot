<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\Color;
use draw\FillRule;
use draw\Group;
use draw\Path;
use draw\RenderContext;
use draw\SceneNode;
use draw\Shape;
use draw\StrokeStyle;
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
}
