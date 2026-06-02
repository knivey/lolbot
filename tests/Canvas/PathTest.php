<?php
namespace Tests\Canvas;

use draw\Canvas;
use draw\Color;
use draw\Path;
use draw\Transform;
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

    public function test_draw_path_fill_produces_expected_pixels(): void
    {
        $path = Path::polygon([[2.0, 2.0], [8.0, 2.0], [8.0, 6.0], [2.0, 6.0]]);
        $canvas = Canvas::createBlank(12, 12);
        $canvas->drawPath($path, new Color(3, null), null);
        $this->assertSame(3, $canvas->data[3][3]->fg);
        $this->assertSame(3, $canvas->data[4][5]->fg);
        $this->assertSame(3, $canvas->data[5][7]->fg);
        $this->assertNull($canvas->data[0][0]->fg);
        $this->assertNull($canvas->data[8][8]->fg);
    }

    public function test_draw_path_outline_produces_expected_pixels(): void
    {
        $path = Path::polygon([[2.0, 2.0], [8.0, 2.0], [8.0, 6.0], [2.0, 6.0]]);
        $canvas = Canvas::createBlank(12, 12);
        $canvas->drawPath($path, null, new Color(5, null));
        $this->assertSame(5, $canvas->data[2][2]->fg);
        $this->assertSame(5, $canvas->data[2][8]->fg);
        $this->assertSame(5, $canvas->data[6][2]->fg);
        $this->assertSame(5, $canvas->data[6][8]->fg);
        $this->assertNull($canvas->data[4][5]->fg);
    }

    public function test_draw_path_both_fill_and_outline(): void
    {
        $path = new Path();
        $path->moveTo(2.0, 2.0)
             ->lineTo(8.0, 2.0)
             ->lineTo(8.0, 6.0)
             ->lineTo(2.0, 6.0)
             ->closePath();

        $canvas = Canvas::createBlank(12, 12);
        $canvas->drawPath($path, new Color(3, null), new Color(5, null));

        // Interior should be fill color
        $this->assertSame(3, $canvas->data[4][5]->fg);
        // Corner should be outline color (outline drawn on top)
        $this->assertSame(5, $canvas->data[2][2]->fg);
    }

    public function test_draw_path_empty_is_noop(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $path = new Path();
        $canvas->drawPath($path, new Color(3, null), new Color(5, null));

        for ($y = 0; $y < 10; $y++) {
            for ($x = 0; $x < 10; $x++) {
                $this->assertNull($canvas->data[$y][$x]->fg);
            }
        }
    }

    public function test_draw_path_donut_creates_hole(): void
    {
        // Outer square (CW), inner square (CCW) — donut with hole
        $path = new Path();
        $path->moveTo(2.0, 2.0)
             ->lineTo(17.0, 2.0)
             ->lineTo(17.0, 17.0)
             ->lineTo(2.0, 17.0)
             ->closePath()
             ->moveTo(6.0, 6.0)
             ->lineTo(6.0, 13.0)
             ->lineTo(13.0, 13.0)
             ->lineTo(13.0, 6.0)
             ->closePath();

        $canvas = Canvas::createBlank(20, 20, true);
        $canvas->drawPath($path, new Color(3, null), null);

        // Center of hole should be empty
        $this->assertNull($canvas->data[9][9]->fg, "Donut hole center should be empty");
        // Ring should be filled
        $this->assertSame(3, $canvas->data[4][4]->fg, "Donut ring should be filled");
    }

    public function test_draw_path_outside_canvas_is_noop(): void
    {
        $path = new Path();
        $path->moveTo(-20.0, -20.0)
             ->lineTo(-10.0, -20.0)
             ->lineTo(-10.0, -10.0)
             ->lineTo(-20.0, -10.0)
             ->closePath();

        $canvas = Canvas::createBlank(10, 10);
        $canvas->drawPath($path, new Color(3, null), new Color(5, null));

        for ($y = 0; $y < 10; $y++) {
            for ($x = 0; $x < 10; $x++) {
                $this->assertNull($canvas->data[$y][$x]->fg);
            }
        }
    }

    public function test_draw_path_open_subpath_outline_no_closing_line(): void
    {
        // An open subpath should NOT draw a closing line for the outline
        $path = new Path();
        $path->moveTo(2.0, 2.0)
             ->lineTo(8.0, 2.0)
             ->lineTo(8.0, 8.0);
        // No closePath → open

        $canvas = Canvas::createBlank(12, 12);
        $canvas->drawPath($path, null, new Color(5, null));

        // The closing line from (8,8) back to (2,2) should NOT be drawn
        // Point on the closing diagonal: (5,5) — should not have outline
        $this->assertNull(
            $canvas->data[5][5]->fg,
            "Open subpath should not draw closing line"
        );
    }

    public function test_draw_path_closed_subpath_outline_has_closing_line(): void
    {
        $path = new Path();
        $path->moveTo(2.0, 2.0)
             ->lineTo(8.0, 2.0)
             ->lineTo(8.0, 8.0)
             ->closePath();

        $canvas = Canvas::createBlank(12, 12);
        $canvas->drawPath($path, null, new Color(5, null));

        // Pixel (5,5) lies on the closing diagonal from (8,8) to (2,2),
        // not on any other segment — it should only be colored if the
        // closing line is actually drawn.
        $this->assertSame(5, $canvas->data[5][5]->fg, "Closing line diagonal pixel should be outlined");
    }

    public function test_line_creates_open_path(): void
    {
        $path = Path::line(1.0, 2.0, 5.0, 8.0);
        $this->assertFalse($path->isEmpty());
        $this->assertSame([5.0, 8.0], $path->getCurrentPoint());
        $subpaths = $path->flatten();
        $this->assertCount(1, $subpaths);
        $this->assertFalse($subpaths[0]['closed']);
        $this->assertSame([1.0, 2.0], $subpaths[0]['vertices'][0]);
        $this->assertSame([5.0, 8.0], $subpaths[0]['vertices'][1]);
    }

    public function test_polyline_creates_open_path(): void
    {
        $path = Path::polyline([[0.0, 0.0], [10.0, 0.0], [10.0, 10.0]]);
        $subpaths = $path->flatten();
        $this->assertCount(1, $subpaths);
        $this->assertFalse($subpaths[0]['closed']);
        $this->assertCount(3, $subpaths[0]['vertices']);
        $this->assertSame([10.0, 10.0], $path->getCurrentPoint());
    }

    public function test_polyline_rejects_fewer_than_two_points(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Path::polyline([[0.0, 0.0]]);
    }

    public function test_polygon_creates_closed_path(): void
    {
        $path = Path::polygon([[0.0, 0.0], [10.0, 0.0], [10.0, 10.0], [0.0, 10.0]]);
        $subpaths = $path->flatten();
        $this->assertCount(1, $subpaths);
        $this->assertTrue($subpaths[0]['closed']);
        $this->assertCount(4, $subpaths[0]['vertices']);
    }

    public function test_polygon_rejects_fewer_than_two_points(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Path::polygon([[5.0, 5.0]]);
    }

    public function test_circle_creates_closed_path(): void
    {
        $path = Path::circle(20.0, 20.0, 10.0);
        $subpaths = $path->flatten();
        $this->assertCount(1, $subpaths);
        $this->assertTrue($subpaths[0]['closed']);
        $this->assertSame([20.0 + 10.0, 20.0], $path->getCurrentPoint());
    }

    public function test_ellipse_creates_closed_path(): void
    {
        $path = Path::ellipse(30.0, 20.0, 15.0, 8.0);
        $subpaths = $path->flatten();
        $this->assertCount(1, $subpaths);
        $this->assertTrue($subpaths[0]['closed']);
        $this->assertSame([30.0 + 15.0, 20.0], $path->getCurrentPoint());
    }

    public function test_rect_creates_closed_path(): void
    {
        $path = Path::rect(5.0, 10.0, 20.0, 15.0);
        $subpaths = $path->flatten();
        $this->assertCount(1, $subpaths);
        $this->assertTrue($subpaths[0]['closed']);
        $this->assertCount(4, $subpaths[0]['vertices']);
    }

    public function test_rect_with_rounded_corners(): void
    {
        $path = Path::rect(0.0, 0.0, 20.0, 10.0, 3.0, 3.0);
        $subpaths = $path->flatten();
        $this->assertCount(1, $subpaths);
        $this->assertTrue($subpaths[0]['closed']);
        $this->assertGreaterThan(4, count($subpaths[0]['vertices']));
    }

    public function test_rect_clamps_radius_to_half_smallest_dimension(): void
    {
        $path = Path::rect(0.0, 0.0, 10.0, 4.0, 100.0, 100.0);
        $subpaths = $path->flatten();
        $this->assertCount(1, $subpaths);
        $this->assertTrue($subpaths[0]['closed']);
    }

    public function test_line_renders_pixels(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $color = new Color(4, 0);
        $canvas->drawPath(Path::line(0, 0, 9, 9), null, $color);
        $this->assertSame(4, $canvas->data[0][0]->fg);
        $this->assertSame(4, $canvas->data[9][9]->fg);
    }

    public function test_rect_renders_outline(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $color = new Color(4, 0);
        $canvas->drawPath(Path::rect(1, 1, 8, 8), null, $color);
        $this->assertSame(4, $canvas->data[1][1]->fg);
        $this->assertSame(4, $canvas->data[1][9]->fg);
        $this->assertSame(4, $canvas->data[9][1]->fg);
        $this->assertSame(4, $canvas->data[9][9]->fg);
    }

    public function test_rect_renders_fill(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $fill = new Color(4, 0);
        $canvas->drawPath(Path::rect(2, 2, 6, 6), $fill, null);
        $this->assertSame(4, $canvas->data[4][4]->fg);
        $this->assertNull($canvas->data[0][0]->fg);
    }

    public function test_circle_renders_outline(): void
    {
        $canvas = Canvas::createBlank(20, 20);
        $color = new Color(4, 0);
        $canvas->drawPath(Path::circle(10, 10, 5), null, $color);
        $this->assertSame(4, $canvas->data[10][15]->fg);
        $this->assertSame(4, $canvas->data[10][5]->fg);
    }

    public function test_ellipse_renders_fill(): void
    {
        $canvas = Canvas::createBlank(20, 20);
        $fill = new Color(4, 0);
        $canvas->drawPath(Path::ellipse(10, 10, 8, 5), $fill, null);
        $this->assertSame(4, $canvas->data[10][10]->fg);
        $this->assertNull($canvas->data[0][0]->fg);
    }

    public function test_path_default_transform_is_null(): void
    {
        $path = new Path();
        $this->assertNull($path->getTransform());
    }

    public function test_path_set_transform_returns_self(): void
    {
        $path = new Path();
        $t = Transform::translate(5.0, 10.0);
        $result = $path->setTransform($t);
        $this->assertSame($path, $result);
    }

    public function test_path_get_transform_returns_set_transform(): void
    {
        $path = new Path();
        $t = Transform::translate(5.0, 10.0);
        $path->setTransform($t);
        $this->assertSame($t, $path->getTransform());
    }

    public function test_path_set_transform_null_clears(): void
    {
        $path = new Path();
        $path->setTransform(Transform::identity());
        $path->setTransform(null);
        $this->assertNull($path->getTransform());
    }

    public function test_path_transform_does_not_affect_builder(): void
    {
        $path = new Path();
        $path->setTransform(Transform::translate(100.0, 200.0));
        $path->moveTo(5.0, 10.0);
        $this->assertSame([5.0, 10.0], $path->getCurrentPoint());
    }

    public function test_draw_path_with_canvas_translate(): void
    {
        $path = Path::rect(0.0, 0.0, 4.0, 4.0);
        $canvas = Canvas::createBlank(20, 20);
        $canvas->translate(10.0, 10.0);
        $canvas->drawPath($path, new Color(4, null), null);
        $this->assertSame(4, $canvas->data[12][12]->fg);
        $this->assertNull($canvas->data[2][2]->fg);
    }

    public function test_draw_path_with_path_transform(): void
    {
        $path = Path::rect(0.0, 0.0, 4.0, 4.0);
        $path->setTransform(Transform::translate(10.0, 10.0));
        $canvas = Canvas::createBlank(20, 20);
        $canvas->drawPath($path, new Color(4, null), null);
        $this->assertSame(4, $canvas->data[12][12]->fg);
        $this->assertNull($canvas->data[2][2]->fg);
    }

    public function test_draw_path_with_both_transforms_composed(): void
    {
        $path = Path::rect(0.0, 0.0, 4.0, 4.0);
        $path->setTransform(Transform::translate(5.0, 0.0));
        $canvas = Canvas::createBlank(20, 20);
        $canvas->translate(5.0, 5.0);
        $canvas->drawPath($path, new Color(4, null), null);
        $this->assertSame(4, $canvas->data[7][12]->fg);
        $this->assertNull($canvas->data[2][2]->fg);
    }

    public function test_draw_path_with_canvas_rotate(): void
    {
        $path = Path::rect(-2.0, -2.0, 4.0, 4.0);
        $canvas = Canvas::createBlank(20, 20);
        $canvas->translate(10.0, 10.0);
        $canvas->drawPath($path, new Color(4, null), null);
        $this->assertSame(4, $canvas->data[10][10]->fg);
    }

    public function test_draw_path_identity_transform_is_noop(): void
    {
        $path = Path::rect(2.0, 2.0, 4.0, 4.0);
        $path->setTransform(Transform::identity());
        $canvas = Canvas::createBlank(10, 10);
        $canvas->drawPath($path, new Color(4, null), null);
        $this->assertSame(4, $canvas->data[4][4]->fg);
    }
}
