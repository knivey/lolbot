<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\ClipNode;
use draw\Color;
use draw\FilterNode;
use draw\FilterRegion;
use draw\GaussianBlurPrimitive;
use draw\GradientUnits;
use draw\Group;
use draw\OffsetPrimitive;
use draw\Path;
use draw\RenderContext;
use draw\Shape;
use draw\Transform;
use PHPUnit\Framework\TestCase;

class FilterNodeTest extends TestCase
{
    public function test_filter_node_with_offset_shifts_shape(): void
    {
        $canvas = Canvas::createBlank(20, 10);

        $child = new Shape(path: Path::rect(2.0, 2.0, 3.0, 3.0), fill: new Color(4, null));

        $filterNode = new FilterNode($child, [
            new OffsetPrimitive(5.0, 0.0),
        ]);
        $filterNode->render($canvas, RenderContext::defaults());

        $this->assertNull($canvas->data[3][3]->fg, 'Original position should be empty');
        $this->assertSame(4, $canvas->data[3][8]->fg, 'Shape should be shifted right by 5');
    }

    public function test_filter_node_with_blur_spreads_shape(): void
    {
        $canvas = Canvas::createBlank(20, 10);

        $child = new Shape(path: Path::rect(5.0, 3.0, 2.0, 2.0), fill: new Color(4, null));

        $filterNode = new FilterNode($child, [
            new GaussianBlurPrimitive(1.0),
        ]);
        $filterNode->render($canvas, RenderContext::defaults());

        $this->assertNotNull($canvas->data[4][6]->fg, 'Center of shape should have color');
        $this->assertNotNull($canvas->data[4][5]->fg, 'Spread from blur');
    }

    public function test_filter_node_empty_primitives_passes_through(): void
    {
        $canvas = Canvas::createBlank(20, 10);

        $child = new Shape(path: Path::rect(2.0, 2.0, 3.0, 3.0), fill: new Color(4, null));

        $filterNode = new FilterNode($child, []);
        $filterNode->render($canvas, RenderContext::defaults());

        $this->assertSame(4, $canvas->data[3][3]->fg);
    }

    public function test_filter_node_get_children_returns_child(): void
    {
        $child = new Shape(path: Path::rect(0.0, 0.0, 10.0, 10.0), fill: new Color(4, null));
        $filterNode = new FilterNode($child, []);

        $children = $filterNode->getChildren();
        $this->assertCount(1, $children);
        $this->assertSame($child, $children[0]);
    }

    public function test_filter_node_on_group(): void
    {
        $canvas = Canvas::createBlank(20, 10);

        $group = new Group();
        $group->addChild(new Shape(path: Path::rect(0.0, 0.0, 5.0, 5.0), fill: new Color(4, null)));

        $filterNode = new FilterNode($group, [
            new OffsetPrimitive(3.0, 0.0),
        ]);
        $filterNode->render($canvas, RenderContext::defaults());

        $this->assertNull($canvas->data[2][2]->fg, 'Original position should be empty');
        $this->assertSame(4, $canvas->data[2][5]->fg, 'Group should be shifted');
    }

    public function test_filter_node_with_custom_region(): void
    {
        $canvas = Canvas::createBlank(20, 10);

        $child = new Shape(path: Path::rect(5.0, 3.0, 3.0, 3.0), fill: new Color(4, null));

        $region = new FilterRegion(0.0, 0.0, 1.0, 1.0);
        $filterNode = new FilterNode($child, [new OffsetPrimitive(1.0, 0.0)], filterRegion: $region);
        $filterNode->render($canvas, RenderContext::defaults());

        $this->assertNull($canvas->data[4][5]->fg, 'Original pos should be empty after offset');
        $this->assertSame(4, $canvas->data[4][6]->fg);
    }

    public function test_filter_node_chains_primitives(): void
    {
        $canvas = Canvas::createBlank(20, 10);

        $child = new Shape(path: Path::rect(2.0, 2.0, 3.0, 3.0), fill: new Color(4, null));

        $filterNode = new FilterNode($child, [
            new OffsetPrimitive(3.0, 0.0),
            new OffsetPrimitive(2.0, 0.0),
        ]);
        $filterNode->render($canvas, RenderContext::defaults());

        $this->assertSame(4, $canvas->data[3][7]->fg, 'Shape shifted by 3+2=5');
    }

    public function test_filter_node_restores_canvas_state(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $beforeTransform = $canvas->getTransform();

        $child = new Shape(path: Path::rect(2.0, 2.0, 3.0, 3.0), fill: new Color(4, null));
        $filterNode = new FilterNode($child, [new OffsetPrimitive(1.0, 0.0)]);
        $filterNode->render($canvas, RenderContext::defaults());

        $this->assertTrue($beforeTransform->equals($canvas->getTransform()));
    }

    public function test_filter_node_user_space_on_use_units(): void
    {
        $canvas = Canvas::createBlank(20, 10);

        $child = new Shape(path: Path::rect(5.0, 3.0, 3.0, 3.0), fill: new Color(4, null));

        $region = new FilterRegion(0.0, 0.0, 20.0, 10.0);
        $filterNode = new FilterNode(
            $child,
            [new OffsetPrimitive(1.0, 0.0)],
            filterRegion: $region,
            filterUnits: GradientUnits::UserSpaceOnUse,
        );
        $filterNode->render($canvas, RenderContext::defaults());

        $this->assertSame(4, $canvas->data[4][6]->fg);
    }
}
