<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\ClipNode;
use draw\Color;
use draw\ColorStop;
use draw\Dithering;
use draw\GradientUnits;
use draw\Group;
use draw\LinearGradient;
use draw\Path;
use draw\RenderContext;
use draw\Shape;
use draw\Transform;
use PHPUnit\Framework\TestCase;

class ClipNodeTest extends TestCase
{
    public function test_clip_restricts_shape_to_clip_region(): void
    {
        $canvas = Canvas::createBlank(20, 10);

        $child = new Shape(path: Path::rect(0.0, 0.0, 20.0, 10.0), fill: new Color(4, null));

        $clipContent = new Group();
        $clipContent->addChild(new Shape(path: Path::rect(2.0, 2.0, 5.0, 5.0), fill: new Color(0, null)));

        $clipNode = new ClipNode($child, $clipContent);
        $clipNode->render($canvas, RenderContext::defaults());

        $this->assertSame(4, $canvas->data[3][3]->fg);
        $this->assertNull($canvas->data[0][0]->fg);
        $this->assertNull($canvas->data[8][8]->fg);
    }

    public function test_clip_empty_clip_nothing_rendered(): void
    {
        $canvas = Canvas::createBlank(20, 10);

        $child = new Shape(path: Path::rect(0.0, 0.0, 20.0, 10.0), fill: new Color(4, null));

        $clipContent = new Group();

        $clipNode = new ClipNode($child, $clipContent);
        $clipNode->render($canvas, RenderContext::defaults());

        $this->assertNull($canvas->data[5][5]->fg);
    }

    public function test_clip_with_transform(): void
    {
        $canvas = Canvas::createBlank(20, 10);

        $child = new Shape(path: Path::rect(0.0, 0.0, 20.0, 10.0), fill: new Color(4, null));

        $clipContent = new Group();
        $clipContent->addChild(new Shape(path: Path::rect(0.0, 0.0, 5.0, 5.0), fill: new Color(0, null)));

        $clipNode = new ClipNode($child, $clipContent, transform: Transform::translate(5.0, 0.0));
        $clipNode->render($canvas, RenderContext::defaults());

        $this->assertNull($canvas->data[2][2]->fg);
        $this->assertSame(4, $canvas->data[2][7]->fg);
    }

    public function test_clip_object_bounding_box_units(): void
    {
        $canvas = Canvas::createBlank(20, 10);

        $child = new Shape(path: Path::rect(5.0, 2.0, 10.0, 6.0), fill: new Color(4, null));

        $clipContent = new Group();
        $clipContent->addChild(new Shape(path: Path::rect(0.0, 0.0, 0.5, 1.0), fill: new Color(0, null)));

        $clipNode = new ClipNode($child, $clipContent, clipPathUnits: GradientUnits::ObjectBoundingBox);
        $clipNode->render($canvas, RenderContext::defaults());

        $this->assertSame(4, $canvas->data[5][7]->fg);
        $this->assertNull($canvas->data[5][15]->fg);
    }

    public function test_clip_on_group(): void
    {
        $canvas = Canvas::createBlank(20, 10);

        $group = new Group();
        $group->addChild(new Shape(path: Path::rect(0.0, 0.0, 10.0, 10.0), fill: new Color(4, null)));
        $group->addChild(new Shape(path: Path::rect(10.0, 0.0, 10.0, 10.0), fill: new Color(5, null)));

        $clipContent = new Group();
        $clipContent->addChild(new Shape(path: Path::rect(2.0, 2.0, 5.0, 5.0), fill: new Color(0, null)));

        $clipNode = new ClipNode($group, $clipContent);
        $clipNode->render($canvas, RenderContext::defaults());

        $this->assertSame(4, $canvas->data[3][3]->fg);
        $this->assertNull($canvas->data[3][13]->fg);
    }

    public function test_get_children_returns_child(): void
    {
        $child = new Shape(path: Path::rect(0.0, 0.0, 10.0, 10.0), fill: new Color(4, null));
        $clipNode = new ClipNode($child, new Group());

        $children = $clipNode->getChildren();
        $this->assertCount(1, $children);
        $this->assertSame($child, $children[0]);
    }

    public function test_clip_restores_canvas_state(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $beforeTransform = $canvas->getTransform();

        $child = new Shape(path: Path::rect(0.0, 0.0, 10.0, 10.0), fill: new Color(4, null));
        $clipContent = new Group();
        $clipContent->addChild(new Shape(path: Path::rect(0.0, 0.0, 5.0, 5.0), fill: new Color(0, null)));

        $clipNode = new ClipNode($child, $clipContent, transform: Transform::translate(2.0, 2.0));
        $clipNode->render($canvas, RenderContext::defaults());

        $this->assertTrue($beforeTransform->equals($canvas->getTransform()));
    }

    public function test_clip_preserves_dithering_through_temp_canvas(): void
    {
        $canvas = Canvas::createBlank(30, 10);
        $canvas->setDithering(Dithering::ShaderBlocks);

        $gradient = new LinearGradient(0.0, 0.0, 30.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);

        $child = new Shape(path: Path::rect(0.0, 0.0, 30.0, 10.0), fill: $gradient);

        $clipContent = new Group();
        $clipContent->addChild(new Shape(path: Path::rect(0.0, 0.0, 30.0, 10.0), fill: new Color(0, null)));

        $clipNode = new ClipNode($child, $clipContent);
        $clipNode->render($canvas, RenderContext::defaults());

        $ditheredCount = 0;
        for ($y = 0; $y < 10; $y++) {
            for ($x = 0; $x < 30; $x++) {
                if ($canvas->data[$y][$x]->dithered) {
                    $ditheredCount++;
                }
            }
        }
        $this->assertGreaterThan(0, $ditheredCount, 'Expected dithered pixels when rendering gradient through ClipNode');
    }
}
