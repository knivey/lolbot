<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\Color;
use draw\Group;
use draw\Path;
use draw\RenderContext;
use draw\SVGDocument;
use draw\Shape;
use draw\Transform;
use PHPUnit\Framework\TestCase;

class SVGDocumentTest extends TestCase
{
    public function test_getRoot_returns_group(): void
    {
        $root = new Group();
        $doc = new SVGDocument($root);
        $this->assertSame($root, $doc->getRoot());
    }

    public function test_viewBox_defaults_null(): void
    {
        $doc = new SVGDocument(new Group());
        $this->assertNull($doc->getViewBox());
    }

    public function test_viewBox_stored(): void
    {
        $doc = new SVGDocument(new Group(), viewBox: [0.0, 0.0, 100.0, 100.0]);
        $this->assertSame([0.0, 0.0, 100.0, 100.0], $doc->getViewBox());
    }

    public function test_width_height_defaults_null(): void
    {
        $doc = new SVGDocument(new Group());
        $this->assertNull($doc->getWidth());
        $this->assertNull($doc->getHeight());
    }

    public function test_width_height_stored(): void
    {
        $doc = new SVGDocument(new Group(), width: 200.0, height: 100.0);
        $this->assertSame(200.0, $doc->getWidth());
        $this->assertSame(100.0, $doc->getHeight());
    }

    public function test_getViewBoxTransform_no_viewBox_returns_null(): void
    {
        $doc = new SVGDocument(new Group());
        $this->assertNull($doc->getViewBoxTransform(80, 24));
    }

    public function test_getViewBoxTransform_uniform_scale_meet(): void
    {
        $doc = new SVGDocument(new Group(), viewBox: [0.0, 0.0, 100.0, 100.0]);
        $t = $doc->getViewBoxTransform(100.0, 100.0);
        $this->assertNotNull($t);
        $result = $t->apply(50.0, 50.0);
        $this->assertEqualsWithDelta(50.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(50.0, $result[1], 0.001);
    }

    public function test_getViewBoxTransform_meet_scales_to_smaller(): void
    {
        $doc = new SVGDocument(new Group(), viewBox: [0.0, 0.0, 100.0, 100.0]);
        $t = $doc->getViewBoxTransform(200.0, 100.0);
        $this->assertNotNull($t);
        $result = $t->apply(0.0, 0.0);
        $this->assertEqualsWithDelta(50.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(0.0, $result[1], 0.001);
        $br = $t->apply(100.0, 100.0);
        $this->assertEqualsWithDelta(150.0, $br[0], 0.001);
        $this->assertEqualsWithDelta(100.0, $br[1], 0.001);
    }

    public function test_getViewBoxTransform_slice_scales_to_larger(): void
    {
        $doc = new SVGDocument(new Group(), viewBox: [0.0, 0.0, 100.0, 100.0]);
        $t = $doc->getViewBoxTransform(200.0, 100.0, 'xMidYMid slice');
        $this->assertNotNull($t);
        $result = $t->apply(0.0, 0.0);
        $this->assertEqualsWithDelta(0.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(-50.0, $result[1], 0.001);
    }

    public function test_getViewBoxTransform_none_stretches(): void
    {
        $doc = new SVGDocument(new Group(), viewBox: [0.0, 0.0, 100.0, 100.0]);
        $t = $doc->getViewBoxTransform(200.0, 50.0, 'none');
        $this->assertNotNull($t);
        $result = $t->apply(100.0, 100.0);
        $this->assertEqualsWithDelta(200.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(50.0, $result[1], 0.001);
    }

    public function test_getViewBoxTransform_with_offset_viewBox(): void
    {
        $doc = new SVGDocument(new Group(), viewBox: [50.0, 50.0, 100.0, 100.0]);
        $t = $doc->getViewBoxTransform(100.0, 100.0);
        $result = $t->apply(50.0, 50.0);
        $this->assertEqualsWithDelta(0.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(0.0, $result[1], 0.001);
    }

    public function test_render_applies_viewBox_transform(): void
    {
        $shape = new Shape(path: Path::rect(0, 0, 10, 10), fill: new Color(4, null));
        $root = new Group();
        $root->addChild($shape);
        $doc = new SVGDocument($root, viewBox: [0.0, 0.0, 10.0, 10.0]);

        $canvas = Canvas::createBlank(20, 20);
        $doc->render($canvas);

        $this->assertNotNull($canvas->data[0][0]->fg);
        $this->assertNotNull($canvas->data[19][19]->fg);
    }

    public function test_render_without_viewBox(): void
    {
        $shape = new Shape(path: Path::rect(0, 0, 10, 10), fill: new Color(4, null));
        $root = new Group();
        $root->addChild($shape);
        $doc = new SVGDocument($root);

        $canvas = Canvas::createBlank(10, 10);
        $doc->render($canvas);

        $this->assertNotNull($canvas->data[0][0]->fg);
    }
}
