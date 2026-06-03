<?php

namespace Tests\Canvas;

use draw\Path;
use draw\SVGParser;
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
}
