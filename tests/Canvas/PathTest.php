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
}
