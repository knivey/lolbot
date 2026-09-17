<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\Color;
use draw\Path;
use draw\SVGParser;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

ini_set('memory_limit', '512M');

class PathFlattenBudgetTest extends TestCase
{
    public function test_huge_control_point_curve_renders_quickly(): void
    {
        //pre-fix: this single 48-byte cubic subdivides into ~2M vertices
        //(~550MB) and fatals with OOM under the stock 128M CLI limit.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 40">'
            . '<path d="M0 0 C1e15 1e15 -1e15 1e15 10 10" fill="red"/></svg>';
        $start = hrtime(true);
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(80, 40);
        $doc->render($canvas);
        $ms = (hrtime(true) - $start) / 1e6;
        $this->assertLessThan(5000.0, $ms, 'clamped curve should render quickly');
    }

    public function test_many_curve_path_hits_vertex_budget(): void
    {
        //each of these clamped-magnitude cubics flattens to ~8k vertices;
        //100 of them exceed the 500k vertex budget
        $path = new Path();
        $path->moveTo(-1e7, -1e7);
        for ($i = 0; $i < 100; $i++) {
            $path->cubicTo(1e7, -1e7, -1e7, 1e7, 1e7, 1e7);
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('svg path too complex');
        $path->flatten();
    }

    public function test_single_monster_cubic_hits_vertex_budget_mid_segment(): void
    {
        //unclamped control points subdivide indefinitely; the budget must
        //throw mid-segment instead of materializing 2M+ vertices
        $path = (new Path())
            ->moveTo(0.0, 0.0)
            ->cubicTo(1e15, 1e15, -1e15, 1e15, 10.0, 10.0);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('svg path too complex');
        $path->flatten();
    }

    public function test_budget_bound_reaches_drawpath(): void
    {
        $path = (new Path())
            ->moveTo(0.0, 0.0)
            ->cubicTo(1e15, 1e15, -1e15, 1e15, 10.0, 10.0);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('svg path too complex');
        Canvas::createBlank(80, 40)->drawPath($path, new Color(1, null), null);
    }
}
