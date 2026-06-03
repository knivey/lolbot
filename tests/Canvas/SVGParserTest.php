<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\Color;
use draw\Group;
use draw\Path;
use draw\SVGDocument;
use draw\Shape;
use draw\SVGParser;
use draw\Transform;
use PHPUnit\Framework\TestCase;

class SVGParserTest extends TestCase
{
    public function test_parse_d_moveto_lineto(): void
    {
        $path = SVGParser::parseDString('M 10 20 L 30 40');
        $this->assertSame([30.0, 40.0], $path->getCurrentPoint());
        $subpaths = $path->flatten();
        $this->assertCount(1, $subpaths);
        $this->assertEqualsWithDelta(10.0, $subpaths[0]['vertices'][0][0], 0.001);
        $this->assertEqualsWithDelta(40.0, $subpaths[0]['vertices'][1][1], 0.001);
    }

    public function test_parse_d_relative_moveto_lineto(): void
    {
        $path = SVGParser::parseDString('M 10 20 l 5 10');
        $this->assertSame([15.0, 30.0], $path->getCurrentPoint());
    }

    public function test_parse_d_implicit_lineto_repeat(): void
    {
        $path = SVGParser::parseDString('M 0 0 L 10 10 20 20');
        $this->assertSame([20.0, 20.0], $path->getCurrentPoint());
    }

    public function test_parse_d_horizontal_vertical(): void
    {
        $path = SVGParser::parseDString('M 10 20 H 30 V 50');
        $this->assertSame([30.0, 50.0], $path->getCurrentPoint());
    }

    public function test_parse_d_closepath(): void
    {
        $path = SVGParser::parseDString('M 10 20 L 30 40 Z');
        $subpaths = $path->flatten();
        $this->assertTrue($subpaths[0]['closed']);
    }

    public function test_parse_d_cubic_bezier(): void
    {
        $path = SVGParser::parseDString('M 0 0 C 10 20 30 40 50 60');
        $this->assertSame([50.0, 60.0], $path->getCurrentPoint());
    }

    public function test_parse_d_smooth_cubic(): void
    {
        $path = SVGParser::parseDString('M 0 0 C 10 20 30 40 50 60 S 90 100 110 120');
        $this->assertSame([110.0, 120.0], $path->getCurrentPoint());
    }

    public function test_parse_d_quadratic_bezier(): void
    {
        $path = SVGParser::parseDString('M 0 0 Q 25 50 50 0');
        $this->assertSame([50.0, 0.0], $path->getCurrentPoint());
    }

    public function test_parse_d_smooth_quadratic(): void
    {
        $path = SVGParser::parseDString('M 0 0 Q 25 50 50 0 T 100 0');
        $this->assertSame([100.0, 0.0], $path->getCurrentPoint());
    }

    public function test_parse_d_arc(): void
    {
        $path = SVGParser::parseDString('M 0 0 A 25 25 0 0 1 50 50');
        $this->assertSame([50.0, 50.0], $path->getCurrentPoint());
    }

    public function test_parse_d_multiple_subpaths(): void
    {
        $path = SVGParser::parseDString('M 0 0 L 10 10 M 20 20 L 30 30');
        $subpaths = $path->flatten();
        $this->assertCount(2, $subpaths);
    }

    public function test_parse_d_comma_separated(): void
    {
        $path = SVGParser::parseDString('M 10,20 L 30,40');
        $this->assertSame([30.0, 40.0], $path->getCurrentPoint());
    }

    public function test_parse_d_negative_as_delimiter(): void
    {
        $path = SVGParser::parseDString('M 10-20L30-40');
        $this->assertSame([30.0, -40.0], $path->getCurrentPoint());
    }

    public function test_parse_d_arc_flags_no_separator(): void
    {
        $path = SVGParser::parseDString('M 0 0 A 25 25 0 01 50 50');
        $this->assertSame([50.0, 50.0], $path->getCurrentPoint());
    }

    public function test_parse_d_empty_string_returns_empty_path(): void
    {
        $path = SVGParser::parseDString('');
        $this->assertTrue($path->isEmpty());
    }

    public function test_parse_transform_translate(): void
    {
        $t = SVGParser::parseTransform('translate(10, 20)');
        $result = $t->apply(0.0, 0.0);
        $this->assertEqualsWithDelta(10.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(20.0, $result[1], 0.001);
    }

    public function test_parse_transform_translate_one_arg(): void
    {
        $t = SVGParser::parseTransform('translate(10)');
        $result = $t->apply(0.0, 0.0);
        $this->assertEqualsWithDelta(10.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(0.0, $result[1], 0.001);
    }

    public function test_parse_transform_scale(): void
    {
        $t = SVGParser::parseTransform('scale(2, 3)');
        $result = $t->apply(10.0, 10.0);
        $this->assertEqualsWithDelta(20.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(30.0, $result[1], 0.001);
    }

    public function test_parse_transform_scale_one_arg(): void
    {
        $t = SVGParser::parseTransform('scale(2)');
        $result = $t->apply(10.0, 10.0);
        $this->assertEqualsWithDelta(20.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(20.0, $result[1], 0.001);
    }

    public function test_parse_transform_rotate(): void
    {
        $t = SVGParser::parseTransform('rotate(90)');
        $result = $t->apply(1.0, 0.0);
        $this->assertEqualsWithDelta(0.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(1.0, $result[1], 0.001);
    }

    public function test_parse_transform_rotate_with_center(): void
    {
        $t = SVGParser::parseTransform('rotate(90, 50, 50)');
        $result = $t->apply(51.0, 50.0);
        $this->assertEqualsWithDelta(50.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(51.0, $result[1], 0.001);
    }

    public function test_parse_transform_skewX(): void
    {
        $t = SVGParser::parseTransform('skewX(45)');
        $result = $t->apply(1.0, 1.0);
        $this->assertEqualsWithDelta(2.0, $result[0], 0.01);
        $this->assertEqualsWithDelta(1.0, $result[1], 0.001);
    }

    public function test_parse_transform_skewY(): void
    {
        $t = SVGParser::parseTransform('skewY(45)');
        $result = $t->apply(1.0, 1.0);
        $this->assertEqualsWithDelta(1.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(2.0, $result[1], 0.01);
    }

    public function test_parse_transform_matrix(): void
    {
        $t = SVGParser::parseTransform('matrix(2, 0, 0, 3, 10, 20)');
        $result = $t->apply(5.0, 5.0);
        $this->assertEqualsWithDelta(20.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(35.0, $result[1], 0.001);
    }

    public function test_parse_transform_chained(): void
    {
        $t = SVGParser::parseTransform('translate(10, 0) scale(2)');
        $result = $t->apply(5.0, 5.0);
        $this->assertEqualsWithDelta(20.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(10.0, $result[1], 0.001);
    }

    public function test_parse_transform_empty_returns_identity(): void
    {
        $t = SVGParser::parseTransform('');
        $this->assertTrue(Transform::identity()->equals($t));
    }

    public function test_parse_string_rect(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect x="5" y="5" width="10" height="10" fill="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(20, 20);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
        $this->assertNull($canvas->data[0][0]->fg);
    }

    public function test_parse_string_circle(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="10" r="5" fill="blue"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(20, 20);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[10][10]->fg);
    }

    public function test_parse_string_ellipse(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><ellipse cx="15" cy="10" rx="10" ry="5" fill="green"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(30, 20);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[10][15]->fg);
    }

    public function test_parse_string_line(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><line x1="0" y1="5" x2="19" y2="5" stroke="white"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(20, 10);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][10]->fg);
    }

    public function test_parse_string_polyline(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><polyline points="0,0 10,0 10,10" stroke="white"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(20, 20);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[0][5]->fg);
    }

    public function test_parse_string_polygon(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><polygon points="5,0 10,10 0,10" fill="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_path_element(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><path d="M 0 0 L 10 0 L 10 10 L 0 10 Z" fill="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_group(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><g fill="red"><rect x="0" y="0" width="5" height="5"/></g></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(10, 10);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[2][2]->fg);
    }

    public function test_parse_string_viewBox(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect x="0" y="0" width="50" height="50" fill="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $this->assertSame([0.0, 0.0, 100.0, 100.0], $doc->getViewBox());
        $canvas = Canvas::createBlank(40, 40);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[10][10]->fg);
    }

    public function test_parse_string_transform_attribute(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect x="0" y="0" width="5" height="5" fill="red" transform="translate(5,5)"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNull($canvas->data[0][0]->fg);
        $this->assertNotNull($canvas->data[7][7]->fg);
    }

    public function test_parse_string_unknown_element_ignored(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect x="0" y="0" width="10" height="10" fill="red"/><text>Hello</text></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_opacity(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect x="0" y="0" width="10" height="10" fill="red" opacity="0.5"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_fill_none(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect x="0" y="0" width="10" height="10" fill="none" stroke="white"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[0][5]->fg);
    }
}
