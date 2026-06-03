<?php

namespace Tests\Canvas;

use draw\Path;
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
}
