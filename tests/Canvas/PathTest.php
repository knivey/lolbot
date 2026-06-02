<?php
namespace Tests\Canvas;

use draw\Path;
use PHPUnit\Framework\TestCase;

class PathTest extends TestCase
{
    public function test_empty_path_isEmpty(): void
    {
        $path = new Path();
        $this->assertTrue($path->isEmpty());
    }

    public function test_move_to_sets_current_point(): void
    {
        $path = new Path();
        $path->moveTo(10.0, 20.0);
        $this->assertFalse($path->isEmpty());
        $this->assertSame([10.0, 20.0], $path->getCurrentPoint());
    }

    public function test_line_to_updates_current_point(): void
    {
        $path = new Path();
        $path->moveTo(10.0, 20.0);
        $path->lineTo(30.0, 40.0);
        $this->assertSame([30.0, 40.0], $path->getCurrentPoint());
    }

    public function test_horizontal_and_vertical_line_to(): void
    {
        $path = new Path();
        $path->moveTo(10.0, 20.0);
        $path->horizontalLineTo(50.0);
        $this->assertSame([50.0, 20.0], $path->getCurrentPoint());
        $path->verticalLineTo(60.0);
        $this->assertSame([50.0, 60.0], $path->getCurrentPoint());
    }

    public function test_cubic_to_updates_current_point(): void
    {
        $path = new Path();
        $path->moveTo(0.0, 0.0);
        $path->cubicTo(5.0, 5.0, 10.0, 5.0, 15.0, 0.0);
        $this->assertSame([15.0, 0.0], $path->getCurrentPoint());
    }

    public function test_quad_to_updates_current_point(): void
    {
        $path = new Path();
        $path->moveTo(0.0, 0.0);
        $path->quadTo(5.0, 10.0, 10.0, 0.0);
        $this->assertSame([10.0, 0.0], $path->getCurrentPoint());
    }

    public function test_arc_to_updates_current_point(): void
    {
        $path = new Path();
        $path->moveTo(0.0, 0.0);
        $path->arcTo(10.0, 10.0, 0.0, false, true, 20.0, 0.0);
        $this->assertSame([20.0, 0.0], $path->getCurrentPoint());
    }

    public function test_close_path_returns_to_subpath_start(): void
    {
        $path = new Path();
        $path->moveTo(10.0, 20.0);
        $path->lineTo(30.0, 40.0);
        $path->closePath();
        $this->assertSame([10.0, 20.0], $path->getCurrentPoint());
    }

    public function test_smooth_cubic_reflects_previous_cubic(): void
    {
        // After cubicTo(5,5, 10,5, 15,0), the second control point is (10,5).
        // smoothCubicTo should reflect it through the current point (15,0):
        // c1 = (15,0) + ((15,0) - (10,5)) = (15,0) + (5,-5) = (20,-5)
        $path = new Path();
        $path->moveTo(0.0, 0.0);
        $path->cubicTo(5.0, 5.0, 10.0, 5.0, 15.0, 0.0);
        $path->smoothCubicTo(20.0, 5.0, 25.0, 0.0);
        $this->assertSame([25.0, 0.0], $path->getCurrentPoint());
    }

    public function test_smooth_cubic_after_non_cubic_uses_current_point(): void
    {
        $path = new Path();
        $path->moveTo(0.0, 0.0);
        $path->lineTo(10.0, 0.0);
        $path->smoothCubicTo(15.0, 5.0, 20.0, 0.0);
        $this->assertSame([20.0, 0.0], $path->getCurrentPoint());
    }

    public function test_smooth_quad_reflects_previous_quad(): void
    {
        $path = new Path();
        $path->moveTo(0.0, 0.0);
        $path->quadTo(5.0, 10.0, 10.0, 0.0);
        $path->smoothQuadTo(15.0, 0.0);
        $this->assertSame([15.0, 0.0], $path->getCurrentPoint());
    }

    public function test_smooth_quad_after_non_quad_uses_current_point(): void
    {
        $path = new Path();
        $path->moveTo(0.0, 0.0);
        $path->lineTo(10.0, 0.0);
        $path->smoothQuadTo(20.0, 0.0);
        $this->assertSame([20.0, 0.0], $path->getCurrentPoint());
    }

    public function test_multiple_subpaths_via_multiple_move_to(): void
    {
        $path = new Path();
        $path->moveTo(10.0, 10.0);
        $path->lineTo(20.0, 10.0);
        $path->closePath();
        $path->moveTo(30.0, 30.0);
        $path->lineTo(40.0, 30.0);
        $this->assertSame([40.0, 30.0], $path->getCurrentPoint());
    }

    public function test_implicit_move_to_when_drawing_without_current_point(): void
    {
        $path = new Path();
        $path->lineTo(10.0, 20.0);
        $this->assertSame([10.0, 20.0], $path->getCurrentPoint());
    }

    public function test_builder_methods_return_self_for_chaining(): void
    {
        $path = new Path();
        $this->assertSame($path, $path->moveTo(0.0, 0.0));
        $this->assertSame($path, $path->lineTo(10.0, 10.0));
        $this->assertSame($path, $path->cubicTo(3.0, 3.0, 7.0, 7.0, 10.0, 10.0));
        $this->assertSame($path, $path->quadTo(5.0, 5.0, 10.0, 10.0));
        $this->assertSame($path, $path->closePath());
    }

    public function test_flatten_rectangle_path(): void
    {
        $path = new Path();
        $path->moveTo(10.0, 10.0)
             ->lineTo(50.0, 10.0)
             ->lineTo(50.0, 30.0)
             ->lineTo(10.0, 30.0)
             ->closePath();

        $subpaths = $path->flatten();
        $this->assertCount(1, $subpaths);
        $this->assertTrue($subpaths[0]['closed']);
        $vertices = $subpaths[0]['vertices'];
        $this->assertCount(4, $vertices);
        $this->assertSame([10.0, 10.0], $vertices[0]);
        $this->assertSame([50.0, 10.0], $vertices[1]);
        $this->assertSame([50.0, 30.0], $vertices[2]);
        $this->assertSame([10.0, 30.0], $vertices[3]);
    }

    public function test_flatten_open_path_is_not_closed(): void
    {
        $path = new Path();
        $path->moveTo(10.0, 10.0)
             ->lineTo(50.0, 10.0)
             ->lineTo(50.0, 30.0);

        $subpaths = $path->flatten();
        $this->assertCount(1, $subpaths);
        $this->assertFalse($subpaths[0]['closed']);
    }

    public function test_flatten_multi_subpath(): void
    {
        $path = new Path();
        $path->moveTo(10.0, 10.0)
             ->lineTo(20.0, 10.0)
             ->closePath()
             ->moveTo(30.0, 30.0)
             ->lineTo(40.0, 30.0)
             ->closePath();

        $subpaths = $path->flatten();
        $this->assertCount(2, $subpaths);
        $this->assertTrue($subpaths[0]['closed']);
        $this->assertTrue($subpaths[1]['closed']);
        $this->assertSame([10.0, 10.0], $subpaths[0]['vertices'][0]);
        $this->assertSame([30.0, 30.0], $subpaths[1]['vertices'][0]);
    }

    public function test_flatten_cubic_bezier_produces_multiple_vertices(): void
    {
        $path = new Path();
        $path->moveTo(0.0, 0.0)
             ->cubicTo(0.0, 10.0, 10.0, 0.0, 10.0, 10.0);

        $subpaths = $path->flatten(0.5);
        $this->assertCount(1, $subpaths);
        $vertices = $subpaths[0]['vertices'];
        $this->assertGreaterThan(2, count($vertices), 'Curved cubic should produce multiple vertices');
        $last = $vertices[count($vertices) - 1];
        $this->assertEqualsWithDelta(10.0, $last[0], 0.01);
        $this->assertEqualsWithDelta(10.0, $last[1], 0.01);
    }

    public function test_flatten_arc_produces_multiple_vertices(): void
    {
        $path = new Path();
        $path->moveTo(0.0, 0.0)
             ->arcTo(10.0, 10.0, 0.0, false, true, 20.0, 0.0);

        $subpaths = $path->flatten();
        $this->assertCount(1, $subpaths);
        $this->assertGreaterThan(2, count($subpaths[0]['vertices']));
    }

    public function test_flatten_empty_path_returns_empty(): void
    {
        $path = new Path();
        $this->assertSame([], $path->flatten());
    }

    public function test_flatten_single_move_to_omitted(): void
    {
        $path = new Path();
        $path->moveTo(10.0, 10.0);
        $this->assertSame([], $path->flatten());
    }

    public function test_flatten_close_path_then_move_to_omits_trailing_degenerate(): void
    {
        $path = new Path();
        $path->moveTo(0.0, 0.0)
             ->lineTo(10.0, 0.0)
             ->closePath()
             ->moveTo(20.0, 20.0);

        $subpaths = $path->flatten();
        // Second subpath is just a MoveTo → omitted
        $this->assertCount(1, $subpaths);
    }

    public function test_flatten_open_subpath_at_end_included(): void
    {
        $path = new Path();
        $path->moveTo(10.0, 10.0)
             ->lineTo(20.0, 10.0)
             ->lineTo(20.0, 20.0);

        $subpaths = $path->flatten();
        $this->assertCount(1, $subpaths);
        $this->assertFalse($subpaths[0]['closed']);
        $this->assertCount(3, $subpaths[0]['vertices']);
    }

    public function test_flatten_drawing_after_close_without_move(): void
    {
        $path = new Path();
        $path->moveTo(0.0, 0.0)
             ->lineTo(10.0, 0.0)
             ->closePath()
             ->lineTo(20.0, 0.0);
        $subpaths = $path->flatten();
        $this->assertCount(2, $subpaths);
        $this->assertTrue($subpaths[0]['closed']);
        $this->assertFalse($subpaths[1]['closed']);
        $this->assertSame([0.0, 0.0], $subpaths[1]['vertices'][0]);
        $this->assertSame([20.0, 0.0], $subpaths[1]['vertices'][1]);
    }

    public function test_flatten_quadratic_bezier_produces_multiple_vertices(): void
    {
        $path = new Path();
        $path->moveTo(0.0, 0.0)
             ->quadTo(5.0, 10.0, 10.0, 0.0);
        $subpaths = $path->flatten(0.5);
        $this->assertCount(1, $subpaths);
        $this->assertGreaterThan(2, count($subpaths[0]['vertices']));
    }

    public function test_flatten_mixed_closed_and_open_subpaths(): void
    {
        $path = new Path();
        $path->moveTo(10.0, 10.0)
             ->lineTo(20.0, 10.0)
             ->closePath()
             ->moveTo(30.0, 30.0)
             ->lineTo(40.0, 30.0);
        $subpaths = $path->flatten();
        $this->assertCount(2, $subpaths);
        $this->assertTrue($subpaths[0]['closed']);
        $this->assertFalse($subpaths[1]['closed']);
    }

    public function test_flatten_implicit_move_to_creates_subpath_at_origin(): void
    {
        $path = new Path();
        $path->lineTo(10.0, 20.0);
        $subpaths = $path->flatten();
        $this->assertCount(1, $subpaths);
        $this->assertSame([0.0, 0.0], $subpaths[0]['vertices'][0]);
        $this->assertSame([10.0, 20.0], $subpaths[0]['vertices'][1]);
    }
}
