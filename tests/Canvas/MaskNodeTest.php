<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\Color;
use draw\Group;
use draw\MaskNode;
use draw\MaskType;
use draw\Path;
use draw\RenderContext;
use draw\Shape;
use draw\Transform;
use draw\GradientUnits;
use PHPUnit\Framework\TestCase;

class MaskNodeTest extends TestCase
{
    public function test_mask_luminance_white_passes_through(): void
    {
        $canvas = Canvas::createBlank(20, 10);

        $child = new Shape(path: Path::rect(2.0, 2.0, 5.0, 5.0), fill: new Color(4, null));

        $maskContent = new Group();
        $maskContent->addChild(new Shape(path: Path::rect(2.0, 2.0, 5.0, 5.0), fill: new Color(0, null)));

        $maskNode = new MaskNode($child, $maskContent);
        $maskNode->render($canvas, RenderContext::defaults());

        $this->assertSame(4, $canvas->data[3][3]->fg);
    }

    public function test_mask_luminance_black_blocks_rendering(): void
    {
        $canvas = Canvas::createBlank(20, 10);

        $child = new Shape(path: Path::rect(2.0, 2.0, 5.0, 5.0), fill: new Color(4, null));

        $maskContent = new Group();
        $maskContent->addChild(new Shape(path: Path::rect(2.0, 2.0, 5.0, 5.0), fill: new Color(1, null)));

        $maskNode = new MaskNode($child, $maskContent);
        $maskNode->render($canvas, RenderContext::defaults());

        $this->assertNull($canvas->data[3][3]->fg);
    }

    public function test_mask_alpha_mode_with_rendered_content(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $canvas->drawPoint(5, 5, new Color(0, null));

        $child = new Shape(path: Path::rect(0.0, 0.0, 10.0, 10.0), fill: new Color(4, null));

        $maskContent = new Group();
        $maskContent->addChild(new Shape(path: Path::rect(0.0, 0.0, 10.0, 10.0), fill: new Color(0, null)));

        $maskNode = new MaskNode($child, $maskContent, maskType: MaskType::Alpha);
        $maskNode->render($canvas, RenderContext::defaults());

        $this->assertSame(4, $canvas->data[5][5]->fg);
        $this->assertNull($canvas->data[0][15]->fg);
    }

    public function test_mask_empty_mask_nothing_rendered(): void
    {
        $canvas = Canvas::createBlank(20, 10);

        $child = new Shape(path: Path::rect(0.0, 0.0, 20.0, 10.0), fill: new Color(4, null));

        $maskNode = new MaskNode($child, new Group());
        $maskNode->render($canvas, RenderContext::defaults());

        $this->assertNull($canvas->data[5][5]->fg);
    }

    public function test_mask_with_transform(): void
    {
        $canvas = Canvas::createBlank(20, 10);

        $child = new Shape(path: Path::rect(0.0, 0.0, 20.0, 10.0), fill: new Color(4, null));

        $maskContent = new Group();
        $maskContent->addChild(new Shape(path: Path::rect(0.0, 0.0, 5.0, 5.0), fill: new Color(0, null)));

        $maskNode = new MaskNode($child, $maskContent, transform: Transform::translate(5.0, 0.0));
        $maskNode->render($canvas, RenderContext::defaults());

        $this->assertNull($canvas->data[2][2]->fg);
        $this->assertSame(4, $canvas->data[2][7]->fg);
    }

    public function test_mask_on_group(): void
    {
        $canvas = Canvas::createBlank(20, 10);

        $group = new Group();
        $group->addChild(new Shape(path: Path::rect(0.0, 0.0, 10.0, 10.0), fill: new Color(4, null)));
        $group->addChild(new Shape(path: Path::rect(10.0, 0.0, 10.0, 10.0), fill: new Color(5, null)));

        $maskContent = new Group();
        $maskContent->addChild(new Shape(path: Path::rect(2.0, 2.0, 5.0, 5.0), fill: new Color(0, null)));

        $maskNode = new MaskNode($group, $maskContent);
        $maskNode->render($canvas, RenderContext::defaults());

        $this->assertSame(4, $canvas->data[3][3]->fg);
        $this->assertNull($canvas->data[3][13]->fg);
    }

    public function test_get_children_returns_child(): void
    {
        $child = new Shape(path: Path::rect(0.0, 0.0, 10.0, 10.0), fill: new Color(4, null));
        $maskNode = new MaskNode($child, new Group());

        $children = $maskNode->getChildren();
        $this->assertCount(1, $children);
        $this->assertSame($child, $children[0]);
    }

    public function test_mask_restores_canvas_state(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $beforeTransform = $canvas->getTransform();

        $child = new Shape(path: Path::rect(0.0, 0.0, 10.0, 10.0), fill: new Color(4, null));
        $maskContent = new Group();
        $maskContent->addChild(new Shape(path: Path::rect(0.0, 0.0, 5.0, 5.0), fill: new Color(0, null)));

        $maskNode = new MaskNode($child, $maskContent, transform: Transform::translate(2.0, 2.0));
        $maskNode->render($canvas, RenderContext::defaults());

        $this->assertTrue($beforeTransform->equals($canvas->getTransform()));
    }
}
