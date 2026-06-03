<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\Color;
use draw\Path;
use draw\Transform;
use draw\StrokeStyle;
use draw\FillRule;
use draw\LineCap;
use draw\LineJoin;
use draw\LinearGradient;
use draw\RadialGradient;
use draw\ColorStop;
use draw\Dithering;
use draw\SpreadMethod;
use PHPUnit\Framework\TestCase;

class CanvasTest extends TestCase
{
    public function test_draw_polygon_with_both_colors_null_is_noop(): void
    {
        $canvas = Canvas::createBlank(10, 10);

        $canvas->drawPath(Path::polygon([[1.0, 1.0], [5.0, 1.0], [5.0, 5.0], [1.0, 5.0]]), null, null);

        // Every pixel in a blank canvas has null fg and bg; nothing should have changed.
        for ($y = 0; $y < 10; $y++) {
            for ($x = 0; $x < 10; $x++) {
                $this->assertNull(
                    $canvas->data[$y][$x]->fg,
                    "Pixel ($x, $y) fg was modified when both colors are null"
                );
                $this->assertNull(
                    $canvas->data[$y][$x]->bg,
                    "Pixel ($x, $y) bg was modified when both colors are null"
                );
            }
        }
    }

    public function test_draw_polygon_outline_only_square(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $outline = new Color(5, null);

        // Square (1,1)-(5,1)-(5,5)-(1,5)
        $canvas->drawPath(Path::polygon([[1.0, 1.0], [5.0, 1.0], [5.0, 5.0], [1.0, 5.0]]), null, new StrokeStyle($outline));

        // Corners must have the outline color.
        $this->assertSame(5, $canvas->data[1][1]->fg);
        $this->assertSame(5, $canvas->data[5][1]->fg);
        $this->assertSame(5, $canvas->data[5][5]->fg);
        $this->assertSame(5, $canvas->data[1][5]->fg);

        // Interior (2,2)-(4,4) must NOT be touched.
        for ($y = 2; $y <= 4; $y++) {
            for ($x = 2; $x <= 4; $x++) {
                $this->assertNull(
                    $canvas->data[$y][$x]->fg,
                    "Interior pixel ($x, $y) was colored but only outline was requested"
                );
            }
        }
    }

    public function test_draw_polygon_fill_only_square(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $fill = new Color(3, null);

        // Square (1,1)-(5,1)-(5,5)-(1,5)
        $canvas->drawPath(Path::polygon([[1.0, 1.0], [5.0, 1.0], [5.0, 5.0], [1.0, 5.0]]), $fill, null);

        // Interior pixels must be filled.
        $this->assertSame(3, $canvas->data[2][2]->fg);
        $this->assertSame(3, $canvas->data[3][3]->fg);
        $this->assertSame(3, $canvas->data[4][2]->fg);
        $this->assertSame(3, $canvas->data[2][4]->fg);

        // Outside pixels must NOT be filled.
        $this->assertNull($canvas->data[0][0]->fg);
        $this->assertNull($canvas->data[6][6]->fg);
        $this->assertNull($canvas->data[9][9]->fg);
    }

    public function test_draw_polygon_fill_plus_outline_square(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $fill = new Color(3, null);
        $outline = new Color(5, null);

        // Square (1,1)-(5,1)-(5,5)-(1,5)
        $canvas->drawPath(Path::polygon([[1.0, 1.0], [5.0, 1.0], [5.0, 5.0], [1.0, 5.0]]), $fill, new StrokeStyle($outline));

        // Corners are on the outline, so they must show the outline color
        // (outline is drawn on top of fill).
        $this->assertSame(5, $canvas->data[1][1]->fg, "Corner (1,1) should show outline color (outline wins over fill at boundary)");
        $this->assertSame(5, $canvas->data[5][1]->fg, "Corner (5,1) should show outline color (outline wins over fill at boundary)");
        $this->assertSame(5, $canvas->data[5][5]->fg, "Corner (5,5) should show outline color (outline wins over fill at boundary)");
        $this->assertSame(5, $canvas->data[1][5]->fg, "Corner (1,5) should show outline color (outline wins over fill at boundary)");

        // Edge midpoints must also show outline color, proving outline is drawn
        // along the entire boundary — not just at vertices.
        $this->assertSame(5, $canvas->data[1][3]->fg, "Edge midpoint (3,1) should show outline color");
        $this->assertSame(5, $canvas->data[3][1]->fg, "Edge midpoint (1,3) should show outline color");
        $this->assertSame(5, $canvas->data[5][3]->fg, "Edge midpoint (3,5) should show outline color");
        $this->assertSame(5, $canvas->data[3][5]->fg, "Edge midpoint (5,3) should show outline color");

        // Interior pixels are pure fill.
        $this->assertSame(3, $canvas->data[2][2]->fg, "Interior pixel (2,2) should show fill color");
        $this->assertSame(3, $canvas->data[3][3]->fg, "Interior pixel (3,3) should show fill color");

        // Outside pixels are untouched.
        $this->assertNull($canvas->data[0][0]->fg, "Outside pixel (0,0) should be untouched");
        $this->assertNull($canvas->data[6][6]->fg, "Outside pixel (6,6) should be untouched");
    }

    public function test_draw_polygon_with_two_vertices_is_noop(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $color = new Color(5, null);

        $canvas->drawPath(Path::polygon([[1.0, 1.0], [5.0, 5.0]]), $color, new StrokeStyle($color));

        for ($y = 0; $y < 10; $y++) {
            for ($x = 0; $x < 10; $x++) {
                $this->assertNull($canvas->data[$y][$x]->fg);
            }
        }
    }

    public function test_draw_polygon_with_one_vertex_is_noop(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $color = new Color(5, null);

        $canvas->drawPath(Path::polygon([[5.0, 5.0], [5.0, 5.0]]), $color, new StrokeStyle($color));

        for ($y = 0; $y < 10; $y++) {
            for ($x = 0; $x < 10; $x++) {
                $this->assertNull($canvas->data[$y][$x]->fg);
            }
        }
    }

    public function test_draw_polygon_with_zero_vertices_is_noop(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $color = new Color(5, null);

        $canvas->drawPath(new Path(), $color, new StrokeStyle($color));

        for ($y = 0; $y < 10; $y++) {
            for ($x = 0; $x < 10; $x++) {
                $this->assertNull($canvas->data[$y][$x]->fg);
            }
        }
    }

    /**
     * Regression test for the @stars corner-stranding bug. Previously the
     * stars command drew the outline with drawLine then flood-filled from
     * the centroid; at sharp corners the rasterized lines did not form a
     * 4-connected seal, so the flood fill correctly left corner pixels
     * unfilled even though they are geometrically inside the polygon.
     *
     * This test uses drawPolygon (scanline fill with non-zero winding rule)
     * and an independent winding-number point-in-polygon oracle to verify
     * that every pixel the oracle considers inside the polygon is colored.
     */
    public function test_draw_polygon_star_corner_stranding_regression(): void
    {
        // Deterministic 5-pointed star (matches the math in stars() but with
        // fixed rotation, fixed radius, fixed center).
        $cx = 40.0;
        $cy = 24.0;
        $radius = 20.0;
        $rot = 0.0;
        $alpha = (2.0 * M_PI) / 10.0;
        $points = [];
        for ($p = 11; $p != 0; $p--) {
            $omega = ($alpha * $p) + $rot;
            $r = $radius * (($p % 2) + 1) / 2.0;
            $points[] = [$r * sin($omega) + $cx, $r * cos($omega) + $cy];
        }

        // drawPolygon snaps vertices to integers internally; the oracle must
        // do the same to match the polygon actually rasterized.
        $snapped = [];
        foreach ($points as $p) {
            $snapped[] = [(int) round($p[0]), (int) round($p[1])];
        }

        $canvas = Canvas::createBlank(80, 48, true);
        $fill = new Color(7, null);
        $outline = new Color(4, null);
        $canvas->drawPath(Path::polygon($points), $fill, new StrokeStyle($outline));

        // Bounding box of the snapped star.
        $xs = array_column($snapped, 0);
        $ys = array_column($snapped, 1);
        $minX = min($xs);
        $maxX = max($xs);
        $minY = min($ys);
        $maxY = max($ys);

        // For every pixel in the bounding box, if the winding oracle says
        // the pixel top-left corner is inside the snapped polygon, the
        // canvas must show either the fill color or the outline color.
        // (Top-left sampling: pixel (X,Y) is filled iff point (X,Y) is
        // inside the polygon — matching the scanline fill convention.)
        for ($y = $minY; $y <= $maxY; $y++) {
            for ($x = $minX; $x <= $maxX; $x++) {
                $winding = $this->windingAt((float) $x, (float) $y, $snapped);
                if ($winding === 0) {
                    continue;
                }
                $fg = $canvas->data[$y][$x]->fg;
                $this->assertNotNull(
                    $fg,
                    "Pixel ($x, $y) is inside polygon (winding=$winding) but is unfilled"
                );
                $this->assertContains(
                    $fg,
                    [7, 4],
                    "Pixel ($x, $y) is inside polygon but has unexpected fg=$fg"
                );
            }
        }
    }

    /**
     * Pure-PHP winding-number point-in-polygon test, independent of the
     * Canvas implementation under test. Returns non-zero winding iff the
     * point is inside the polygon under the non-zero winding rule.
     */
    /**
     * @param array<int, array{0: int|float, 1: int|float}> $points
     */
    private function windingAt(float $px, float $py, array $points): int
    {
        $n = count($points);
        $winding = 0;
        for ($i = 0; $i < $n; $i++) {
            [$x1, $y1] = $points[$i];
            [$x2, $y2] = $points[($i + 1) % $n];
            if ($y1 <= $py) {
                if ($y2 > $py) {
                    if ($this->isLeft($px, $py, $x1, $y1, $x2, $y2) > 0) {
                        $winding++;
                    }
                }
            } else {
                if ($y2 <= $py) {
                    if ($this->isLeft($px, $py, $x1, $y1, $x2, $y2) < 0) {
                        $winding--;
                    }
                }
            }
        }
        return $winding;
    }

    private function isLeft(float $px, float $py, float $x1, float $y1, float $x2, float $y2): float
    {
        return ($x2 - $x1) * ($py - $y1) - ($px - $x1) * ($y2 - $y1);
    }

    /**
     * For a correctly rendered polygon, the outline and fill must share the
     * same boundary on every scanline. If they are computed against different
     * polygons (e.g. outline uses rounded-int vertices while fill uses float
     * vertices), the two can drift apart by up to half a pixel per vertex,
     * producing visible horizontal gaps where the outline sits beside an
     * unfilled pixel that lies between it and the fill region.
     *
     * Uses a convex polygon (triangle) with float vertices so that each
     * scanline crosses exactly one fill span — making "no horizontal gaps
     * between leftmost and rightmost pixel" a valid alignment invariant.
     * (Non-convex shapes like stars can have legitimate gaps between
     * separate fill spans on the same row.)
     */
    public function test_draw_polygon_outline_aligns_with_fill_no_horizontal_gaps(): void
    {
        // Triangle with float vertices that round to different integers,
        // stressing the fill-vs-outline alignment.
        $points = [[10.3, 5.7], [30.8, 5.2], [20.1, 25.9]];

        $canvas = Canvas::createBlank(40, 30, true);
        $canvas->drawPath(Path::polygon($points), new Color(3, null), new StrokeStyle(new Color(5, null)));

        // For every row that has any non-null pixel, all pixels between the
        // leftmost and rightmost non-null pixel must also be non-null.
        for ($y = 0; $y < $canvas->h; $y++) {
            $leftmost = null;
            $rightmost = null;
            for ($x = 0; $x < $canvas->w; $x++) {
                if ($canvas->data[$y][$x]->fg !== null) {
                    if ($leftmost === null) {
                        $leftmost = $x;
                    }
                    $rightmost = $x;
                }
            }
            if ($leftmost === null) {
                continue; // empty row, no constraint
            }
            for ($x = $leftmost; $x <= $rightmost; $x++) {
                $this->assertNotNull(
                    $canvas->data[$y][$x]->fg,
                    "Row $y has a gap at column $x (between leftmost=$leftmost " .
                    "and rightmost=$rightmost). Outline and fill are misaligned."
                );
            }
        }
    }

    public function test_draw_polygon_fully_outside_canvas_does_not_throw(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $color = new Color(5, null);

        // Polygon entirely up-and-left of the canvas.
        $canvas->drawPath(Path::polygon([[-20.0, -20.0], [-10.0, -20.0], [-10.0, -10.0], [-20.0, -10.0]]), $color, new StrokeStyle($color));

        // Polygon entirely down-and-right of the canvas.
        $canvas->drawPath(Path::polygon([[50.0, 50.0], [60.0, 50.0], [60.0, 60.0], [50.0, 60.0]]), $color, new StrokeStyle($color));

        // Canvas must be untouched (drawPoint's isset check rejected every write).
        for ($y = 0; $y < 10; $y++) {
            for ($x = 0; $x < 10; $x++) {
                $this->assertNull($canvas->data[$y][$x]->fg);
            }
        }
    }

    public function test_canvas_default_transform_is_identity(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $this->assertSame([1.0, 0.0, 0.0, 1.0, 0.0, 0.0], $canvas->getTransform()->getElements());
    }

    public function test_canvas_set_transform_replaces_ctm(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $t = Transform::translate(5.0, 10.0);
        $canvas->setTransform($t);
        $this->assertSame($t, $canvas->getTransform());
    }

    public function test_canvas_concat_transform_composes(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $canvas->concatTransform(Transform::translate(10.0, 0.0));
        $canvas->concatTransform(Transform::scale(2.0));
        [$x, $y] = $canvas->getTransform()->apply(5.0, 0.0);
        $this->assertEqualsWithDelta(20.0, $x, 0.0001);
    }

    public function test_canvas_save_restore(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $canvas->concatTransform(Transform::translate(5.0, 5.0));
        $canvas->save();
        $canvas->concatTransform(Transform::scale(2.0));
        $this->assertNotEquals(
            [1.0, 0.0, 0.0, 1.0, 5.0, 5.0],
            $canvas->getTransform()->getElements()
        );
        $canvas->restore();
        $this->assertSame(
            [1.0, 0.0, 0.0, 1.0, 5.0, 5.0],
            $canvas->getTransform()->getElements()
        );
    }

    public function test_canvas_restore_throws_on_empty_stack(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $this->expectException(\LogicException::class);
        $canvas->restore();
    }

    public function test_canvas_save_restore_nested(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $canvas->concatTransform(Transform::translate(1.0, 0.0));
        $canvas->save();
        $canvas->concatTransform(Transform::translate(2.0, 0.0));
        $canvas->save();
        $canvas->concatTransform(Transform::translate(3.0, 0.0));
        $canvas->restore();
        [$x, $y] = $canvas->getTransform()->apply(0.0, 0.0);
        $this->assertEqualsWithDelta(3.0, $x, 0.0001);
        $canvas->restore();
        [$x, $y] = $canvas->getTransform()->apply(0.0, 0.0);
        $this->assertEqualsWithDelta(1.0, $x, 0.0001);
    }

    public function test_canvas_translate_convenience(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $canvas->translate(5.0, 10.0);
        [$x, $y] = $canvas->getTransform()->apply(0.0, 0.0);
        $this->assertEqualsWithDelta(5.0, $x, 0.0001);
        $this->assertEqualsWithDelta(10.0, $y, 0.0001);
    }

    public function test_canvas_rotate_convenience(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $canvas->rotate(M_PI / 2.0, 5.0, 5.0);
        [$x, $y] = $canvas->getTransform()->apply(6.0, 5.0);
        $this->assertEqualsWithDelta(5.0, $x, 0.0001);
        $this->assertEqualsWithDelta(6.0, $y, 0.0001);
    }

    public function test_canvas_scale_convenience(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $canvas->scale(2.0, 3.0);
        [$x, $y] = $canvas->getTransform()->apply(1.0, 1.0);
        $this->assertEqualsWithDelta(2.0, $x, 0.0001);
        $this->assertEqualsWithDelta(3.0, $y, 0.0001);
    }

    public function test_draw_point_with_transform(): void
    {
        $canvas = Canvas::createBlank(20, 20);
        $canvas->translate(10.0, 5.0);
        $canvas->drawPoint(2, 3, new Color(4, null));
        $this->assertSame(4, $canvas->data[8][12]->fg);
        $this->assertNull($canvas->data[3][2]->fg);
    }

    public function test_draw_path_line_with_transform(): void
    {
        $canvas = Canvas::createBlank(20, 20);
        $canvas->translate(10.0, 0.0);
        $canvas->drawPath(Path::line(0, 5, 5, 5), null, new StrokeStyle(new Color(4, null)));
        $this->assertSame(4, $canvas->data[5][10]->fg);
        $this->assertSame(4, $canvas->data[5][15]->fg);
    }

    public function test_draw_path_polygon_with_transform(): void
    {
        $canvas = Canvas::createBlank(20, 20);
        $canvas->translate(5.0, 5.0);
        $canvas->drawPath(
            Path::polygon([[0.0, 0.0], [4.0, 0.0], [4.0, 4.0], [0.0, 4.0]]),
            new Color(4, null),
            null
        );
        $this->assertSame(4, $canvas->data[7][7]->fg);
        $this->assertNull($canvas->data[2][2]->fg);
    }

    public function test_evenodd_concentric_squares_hole(): void
    {
        $canvas = Canvas::createBlank(20, 20);
        $fill = new Color(4, null);

        $outer = Path::polygon([[1.0, 1.0], [15.0, 1.0], [15.0, 15.0], [1.0, 15.0]]);
        $inner = Path::polygon([[5.0, 5.0], [11.0, 5.0], [11.0, 11.0], [5.0, 11.0]]);

        $path = new Path();
        foreach ($outer->flatten() as $sp) {
            $path->moveTo($sp['vertices'][0][0], $sp['vertices'][0][1]);
            $n = count($sp['vertices']);
            for ($i = 1; $i < $n; $i++) {
                $path->lineTo($sp['vertices'][$i][0], $sp['vertices'][$i][1]);
            }
            if ($sp['closed']) {
                $path->closePath();
            }
        }
        foreach ($inner->flatten() as $sp) {
            $path->moveTo($sp['vertices'][0][0], $sp['vertices'][0][1]);
            $n = count($sp['vertices']);
            for ($i = 1; $i < $n; $i++) {
                $path->lineTo($sp['vertices'][$i][0], $sp['vertices'][$i][1]);
            }
            if ($sp['closed']) {
                $path->closePath();
            }
        }

        $canvas->drawPath($path, $fill, null, '', FillRule::EvenOdd);

        $this->assertSame(4, $canvas->data[3][3]->fg, "Pixel between outer and inner boundary should be filled (EvenOdd)");
        $this->assertSame(4, $canvas->data[3][8]->fg, "Pixel between outer and inner boundary should be filled (EvenOdd)");
        $this->assertNull($canvas->data[8][8]->fg, "Pixel inside inner square should NOT be filled (EvenOdd hole)");
        $this->assertNull($canvas->data[6][6]->fg, "Pixel inside inner square should NOT be filled (EvenOdd hole)");
    }

    public function test_nonzero_concentric_squares_no_hole(): void
    {
        $canvas = Canvas::createBlank(20, 20);
        $fill = new Color(4, null);

        $outer = Path::polygon([[1.0, 1.0], [15.0, 1.0], [15.0, 15.0], [1.0, 15.0]]);
        $inner = Path::polygon([[5.0, 5.0], [11.0, 5.0], [11.0, 11.0], [5.0, 11.0]]);

        $path = new Path();
        foreach ($outer->flatten() as $sp) {
            $path->moveTo($sp['vertices'][0][0], $sp['vertices'][0][1]);
            $n = count($sp['vertices']);
            for ($i = 1; $i < $n; $i++) {
                $path->lineTo($sp['vertices'][$i][0], $sp['vertices'][$i][1]);
            }
            if ($sp['closed']) {
                $path->closePath();
            }
        }
        foreach ($inner->flatten() as $sp) {
            $path->moveTo($sp['vertices'][0][0], $sp['vertices'][0][1]);
            $n = count($sp['vertices']);
            for ($i = 1; $i < $n; $i++) {
                $path->lineTo($sp['vertices'][$i][0], $sp['vertices'][$i][1]);
            }
            if ($sp['closed']) {
                $path->closePath();
            }
        }

        $canvas->drawPath($path, $fill, null, '', FillRule::NonZero);

        $this->assertSame(4, $canvas->data[3][3]->fg, "Pixel between outer and inner should be filled (NonZero)");
        $this->assertSame(4, $canvas->data[8][8]->fg, "Pixel inside inner square should also be filled (NonZero, same winding)");
    }

    public function test_stroke_width_2_horizontal_line(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $stroke = new StrokeStyle(new Color(4, null), width: 2.0);

        $canvas->drawPath(Path::line(2, 5, 8, 5), null, $stroke);

        $this->assertSame(4, $canvas->data[4][5]->fg, "Row above center should be stroked");
        $this->assertSame(4, $canvas->data[5][5]->fg, "Center row should be stroked");
        $this->assertNull($canvas->data[3][5]->fg, "Row two above should not be stroked");
        $this->assertNull($canvas->data[6][5]->fg, "Row below should not be stroked");
    }

    public function test_stroke_width_3_horizontal_line(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $stroke = new StrokeStyle(new Color(4, null), width: 3.0);

        $canvas->drawPath(Path::line(2, 5, 8, 5), null, $stroke);

        $this->assertSame(4, $canvas->data[4][5]->fg);
        $this->assertSame(4, $canvas->data[5][5]->fg);
        $this->assertSame(4, $canvas->data[6][5]->fg);
        $this->assertNull($canvas->data[3][5]->fg);
        $this->assertNull($canvas->data[7][5]->fg);
    }

    public function test_stroke_width_3_vertical_line(): void
    {
        $canvas = Canvas::createBlank(10, 20);
        $stroke = new StrokeStyle(new Color(4, null), width: 3.0);

        $canvas->drawPath(Path::line(5, 2, 5, 8), null, $stroke);

        $this->assertSame(4, $canvas->data[5][4]->fg);
        $this->assertSame(4, $canvas->data[5][5]->fg);
        $this->assertSame(4, $canvas->data[5][6]->fg);
        $this->assertNull($canvas->data[5][3]->fg);
        $this->assertNull($canvas->data[5][7]->fg);
    }

    public function test_stroke_width_2_square_outline(): void
    {
        $canvas = Canvas::createBlank(20, 20);
        $stroke = new StrokeStyle(new Color(4, null), width: 2.0);

        $canvas->drawPath(Path::rect(5, 5, 10, 10), null, $stroke);

        $this->assertSame(4, $canvas->data[4][5]->fg, "Top edge row above");
        $this->assertSame(4, $canvas->data[5][5]->fg, "Top edge");
        $this->assertSame(4, $canvas->data[14][5]->fg, "Bottom edge");
        $this->assertSame(4, $canvas->data[15][5]->fg, "Bottom edge row below");
        $this->assertSame(4, $canvas->data[5][4]->fg, "Left edge col before");
        $this->assertSame(4, $canvas->data[5][5]->fg, "Left edge");
        $this->assertNull($canvas->data[9][9]->fg, "Interior should be empty");
    }

    public function test_stroke_butt_cap_open_path(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $stroke = new StrokeStyle(new Color(4, null), width: 3.0, lineCap: LineCap::Butt);

        $canvas->drawPath(Path::line(5, 5, 10, 5), null, $stroke);

        $this->assertSame(4, $canvas->data[4][5]->fg, "Butt cap at start");
        $this->assertSame(4, $canvas->data[4][10]->fg, "Butt cap at end");
        $this->assertNull($canvas->data[4][4]->fg, "No extension before start");
        $this->assertNull($canvas->data[4][11]->fg, "No extension after end");
    }

    public function test_stroke_square_cap_extends(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $stroke = new StrokeStyle(new Color(4, null), width: 2.0, lineCap: LineCap::Square);

        $canvas->drawPath(Path::line(5, 5, 10, 5), null, $stroke);

        $this->assertSame(4, $canvas->data[5][4]->fg, "Square cap extends 1px before start");
        $this->assertSame(4, $canvas->data[5][11]->fg, "Square cap extends 1px after end");
    }

    public function test_stroke_round_cap_adds_semicircle(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $stroke = new StrokeStyle(new Color(4, null), width: 3.0, lineCap: LineCap::Round);

        $canvas->drawPath(Path::line(5, 5, 10, 5), null, $stroke);

        $this->assertSame(4, $canvas->data[5][4]->fg, "Round cap at start");
        $this->assertSame(4, $canvas->data[5][11]->fg, "Round cap at end");
    }

    public function test_stroke_miter_join(): void
    {
        $canvas = Canvas::createBlank(20, 20);
        $stroke = new StrokeStyle(new Color(4, null), width: 2.0, lineJoin: LineJoin::Miter);

        $path = new Path();
        $path->moveTo(5.0, 5.0);
        $path->lineTo(10.0, 5.0);
        $path->lineTo(10.0, 10.0);
        $canvas->drawPath($path, null, $stroke);

        $this->assertSame(4, $canvas->data[4][10]->fg, "Miter join at corner");
    }

    public function test_stroke_bevel_join(): void
    {
        $canvas = Canvas::createBlank(20, 20);
        $stroke = new StrokeStyle(new Color(4, null), width: 2.0, lineJoin: LineJoin::Bevel);

        $path = new Path();
        $path->moveTo(5.0, 5.0);
        $path->lineTo(10.0, 5.0);
        $path->lineTo(10.0, 10.0);
        $canvas->drawPath($path, null, $stroke);

        $this->assertSame(4, $canvas->data[5][9]->fg, "Bevel join area");
    }

    public function test_stroke_round_join(): void
    {
        $canvas = Canvas::createBlank(20, 20);
        $stroke = new StrokeStyle(new Color(4, null), width: 4.0, lineJoin: LineJoin::Round);

        $path = new Path();
        $path->moveTo(5.0, 10.0);
        $path->lineTo(10.0, 10.0);
        $path->lineTo(10.0, 5.0);
        $canvas->drawPath($path, null, $stroke);

        $this->assertSame(4, $canvas->data[10][10]->fg, "Round join at corner");
    }

    public function test_stroke_width_1_unchanged_behavior(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $stroke = new StrokeStyle(new Color(4, null));

        $canvas->drawPath(Path::line(2, 2, 8, 8), null, $stroke);

        $this->assertSame(4, $canvas->data[2][2]->fg);
        $this->assertSame(4, $canvas->data[5][5]->fg);
        $this->assertSame(4, $canvas->data[8][8]->fg);
    }

    public function test_stroke_miter_limit_clips_to_bevel(): void
    {
        $canvas = Canvas::createBlank(30, 30);
        $stroke = new StrokeStyle(new Color(4, null), width: 2.0, lineJoin: LineJoin::Miter, miterLimit: 1.0);

        $path = new Path();
        $path->moveTo(5.0, 15.0);
        $path->lineTo(15.0, 10.0);
        $path->lineTo(25.0, 15.0);
        $canvas->drawPath($path, null, $stroke);

        $this->assertTrue(true, "Miter limit clipping does not crash");
    }

    public function test_dash_pattern_simple(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $stroke = new StrokeStyle(new Color(4, null), dashArray: [3.0, 2.0]);

        $canvas->drawPath(Path::line(0, 5, 10, 5), null, $stroke);

        $this->assertSame(4, $canvas->data[5][0]->fg, "First dash pixel 0");
        $this->assertSame(4, $canvas->data[5][1]->fg, "First dash pixel 1");
        $this->assertSame(4, $canvas->data[5][2]->fg, "First dash pixel 2");
        $this->assertNull($canvas->data[5][3]->fg, "Gap pixel 3");
        $this->assertNull($canvas->data[5][4]->fg, "Gap pixel 4");
        $this->assertSame(4, $canvas->data[5][5]->fg, "Second dash pixel 5");
        $this->assertSame(4, $canvas->data[5][6]->fg, "Second dash pixel 6");
        $this->assertSame(4, $canvas->data[5][7]->fg, "Second dash pixel 7");
        $this->assertNull($canvas->data[5][8]->fg, "Gap pixel 8");
    }

    public function test_dash_pattern_with_offset(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $stroke = new StrokeStyle(new Color(4, null), dashArray: [3.0, 2.0], dashOffset: 3.0);

        $canvas->drawPath(Path::line(0, 5, 10, 5), null, $stroke);

        $this->assertNull($canvas->data[5][0]->fg, "Offset shifts start into gap");
        $this->assertNull($canvas->data[5][1]->fg, "Still in gap");
        $this->assertSame(4, $canvas->data[5][2]->fg, "Dash starts at offset");
    }

    public function test_dash_pattern_with_thick_stroke(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $stroke = new StrokeStyle(new Color(4, null), width: 3.0, dashArray: [4.0, 3.0]);

        $canvas->drawPath(Path::line(0, 5, 10, 5), null, $stroke);

        $this->assertSame(4, $canvas->data[4][0]->fg, "Thick dash fills rows");
        $this->assertSame(4, $canvas->data[5][0]->fg, "Thick dash fills center");
        $this->assertSame(4, $canvas->data[6][0]->fg, "Thick dash fills rows");
        $this->assertNull($canvas->data[4][4]->fg, "Gap in thick dash");
        $this->assertNull($canvas->data[5][4]->fg, "Gap center");
    }

    public function test_dash_pattern_null_means_solid(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $stroke = new StrokeStyle(new Color(4, null));

        $canvas->drawPath(Path::line(0, 5, 10, 5), null, $stroke);

        for ($x = 0; $x <= 10; $x++) {
            $this->assertSame(4, $canvas->data[5][$x]->fg, "Solid line pixel $x");
        }
    }

    public function test_draw_path_fill_opacity_renders_at_half(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $canvas->drawPath(
            Path::rect(0, 0, 20, 10),
            new Color(4, null),
            null,
            '',
            FillRule::NonZero,
            0.5
        );

        $pixel = $canvas->data[5][5];
        $this->assertNotNull($pixel->fg);
        $this->assertSame(4, $pixel->fg, "50% fill on blank canvas copies color directly");
    }

    public function test_draw_path_fill_opacity_zero_is_noop(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $canvas->drawPath(
            Path::rect(0, 0, 20, 10),
            new Color(4, null),
            null,
            '',
            FillRule::NonZero,
            0.0
        );

        for ($y = 0; $y < 10; $y++) {
            for ($x = 0; $x < 20; $x++) {
                $this->assertNull($canvas->data[$y][$x]->fg, "Pixel ($x,$y) should be empty at 0 opacity");
            }
        }
    }

    public function test_draw_path_stroke_opacity_renders_at_half(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $stroke = new StrokeStyle(new Color(4, null), opacity: 0.5);
        $canvas->drawPath(Path::line(2, 5, 17, 5), null, $stroke);

        $pixel = $canvas->data[5][10];
        $this->assertNotNull($pixel->fg);
        $this->assertSame(4, $pixel->fg, "50% stroke on blank canvas copies directly");
    }

    public function test_draw_path_stroke_opacity_zero_is_noop(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $stroke = new StrokeStyle(new Color(4, null), opacity: 0.0);
        $canvas->drawPath(Path::line(2, 5, 17, 5), null, $stroke);

        for ($x = 2; $x <= 17; $x++) {
            $this->assertNull($canvas->data[5][$x]->fg, "Pixel $x should be empty at 0 stroke opacity");
        }
    }

    public function test_draw_path_element_opacity_blends(): void
    {
        $canvas = Canvas::createBlank(20, 10);

        $canvas->drawPath(
            Path::rect(0, 0, 20, 10),
            new Color(0, null),
            null
        );

        $canvas->drawPath(
            Path::rect(2, 2, 10, 5),
            new Color(4, null),
            null,
            '',
            FillRule::NonZero,
            1.0,
            0.5
        );

        $blended = $canvas->data[4][5];
        $this->assertNotSame(4, $blended->fg, "Should not be pure red after 50% element opacity");
        $this->assertNotSame(0, $blended->fg, "Should not be pure white after 50% element opacity");

        $outside = $canvas->data[0][0];
        $this->assertSame(0, $outside->fg, "Outside overlap should be unchanged");
    }

    public function test_draw_path_element_opacity_zero_is_noop(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $canvas->drawPath(
            Path::rect(2, 2, 10, 5),
            new Color(4, null),
            null,
            '',
            FillRule::NonZero,
            1.0,
            0.0
        );

        $this->assertNull($canvas->data[4][5]->fg, "Should be empty at 0 element opacity");
    }

    public function test_draw_path_fill_and_stroke_combined_opacities(): void
    {
        $canvas = Canvas::createBlank(20, 10);

        $canvas->drawPath(
            Path::rect(0, 0, 20, 10),
            new Color(0, null),
            null
        );

        $stroke = new StrokeStyle(new Color(4, null), width: 3.0, opacity: 0.5);
        $canvas->drawPath(
            Path::rect(2, 2, 10, 5),
            new Color(9, null),
            $stroke,
            '',
            FillRule::NonZero,
            0.7,
            1.0
        );

        $fillPixel = $canvas->data[4][5];
        $this->assertNotSame(9, $fillPixel->fg, "Fill should be blended, not raw");
        $this->assertNotSame(0, $fillPixel->fg, "Fill should not be pure white bg");

        $strokePixel = $canvas->data[2][5];
        $this->assertNotSame(4, $strokePixel->fg, "Stroke should be blended, not raw");
    }

    public function test_draw_path_opacity_full_is_same_as_default(): void
    {
        $c1 = Canvas::createBlank(20, 10);
        $c2 = Canvas::createBlank(20, 10);

        $fill = new Color(4, null);
        $stroke = new StrokeStyle(new Color(0, null));

        $c1->drawPath(Path::rect(2, 2, 10, 5), $fill, $stroke);
        $c2->drawPath(Path::rect(2, 2, 10, 5), $fill, $stroke, '', FillRule::NonZero, 1.0, 1.0);

        for ($y = 0; $y < 10; $y++) {
            for ($x = 0; $x < 20; $x++) {
                $this->assertSame(
                    $c1->data[$y][$x]->fg,
                    $c2->data[$y][$x]->fg,
                    "Pixel ($x,$y) should match with explicit 1.0 opacity"
                );
            }
        }
    }

    public function test_draw_path_with_linear_gradient_fill(): void
    {
        $canvas = Canvas::createBlank(20, 5);
        $gradient = new LinearGradient(0.0, 0.0, 19.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);

        $path = Path::rect(0.0, 0.0, 20.0, 5.0);
        $canvas->drawPath($path, $gradient, null);

        $this->assertNotNull($canvas->data[2][0]->fg);
        $this->assertNotNull($canvas->data[2][19]->fg);
        $this->assertNotSame(
            $canvas->data[2][0]->fg,
            $canvas->data[2][19]->fg,
            'Left and right pixels should have different colors in a horizontal gradient'
        );
    }

    public function test_draw_path_with_radial_gradient_fill(): void
    {
        $canvas = Canvas::createBlank(21, 21);
        $gradient = new RadialGradient(10.0, 10.0, 10.0, [
            new ColorStop(0.0, 255, 255, 255),
            new ColorStop(1.0, 0, 0, 0),
        ]);

        $path = Path::rect(0.0, 0.0, 21.0, 21.0);
        $canvas->drawPath($path, $gradient, null);

        $this->assertNotNull($canvas->data[10][10]->fg);
        $this->assertNotNull($canvas->data[10][0]->fg);
        $this->assertNotSame(
            $canvas->data[10][10]->fg,
            $canvas->data[10][0]->fg,
            'Center and edge pixels should have different colors in a radial gradient'
        );
    }

    public function test_draw_path_with_gradient_stroke_width_1(): void
    {
        $canvas = Canvas::createBlank(20, 5);
        $gradient = new LinearGradient(0.0, 0.0, 19.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);

        $path = Path::line(0.0, 2.0, 19.0, 2.0);
        $canvas->drawPath($path, null, new StrokeStyle($gradient));

        $this->assertNotNull($canvas->data[2][0]->fg);
        $this->assertNotNull($canvas->data[2][19]->fg);
        $this->assertNotSame(
            $canvas->data[2][0]->fg,
            $canvas->data[2][19]->fg,
            'Start and end pixels should have different colors in a gradient stroke'
        );
    }

    public function test_draw_path_with_gradient_stroke_width_gt_1(): void
    {
        $canvas = Canvas::createBlank(20, 10);
        $gradient = new LinearGradient(0.0, 0.0, 19.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);

        $path = Path::line(0.0, 5.0, 19.0, 5.0);
        $canvas->drawPath($path, null, new StrokeStyle($gradient, width: 3.0));

        $this->assertNotNull($canvas->data[4][0]->fg);
        $this->assertNotNull($canvas->data[4][19]->fg);
        $this->assertNotSame(
            $canvas->data[4][0]->fg,
            $canvas->data[4][19]->fg,
            'Start and end of thick stroke should have different colors in a gradient'
        );
    }

    public function test_draw_path_gradient_and_opacity(): void
    {
        $canvas = Canvas::createBlank(20, 5);
        $gradient = new LinearGradient(0.0, 0.0, 19.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);

        $path = Path::rect(0.0, 0.0, 20.0, 5.0);
        $canvas->drawPath($path, $gradient, null, '', FillRule::NonZero, 0.5);

        $this->assertNotNull($canvas->data[2][10]->fg);
    }

    public function test_draw_point_with_gradient(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $gradient = new LinearGradient(0.0, 0.0, 9.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);

        $canvas->drawPoint(5, 5, $gradient);

        $this->assertNotNull($canvas->data[5][5]->fg);
        $this->assertNull($canvas->data[5][5]->bg);
    }

    public function test_canvas_setDithering_getDithering_roundtrip(): void
    {
        $canvas = Canvas::createBlank(10, 10);
        $this->assertSame(Dithering::None, $canvas->getDithering());
        $canvas->setDithering(Dithering::Ordered4x4);
        $this->assertSame(Dithering::Ordered4x4, $canvas->getDithering());
    }

    public function test_canvas_dithering_affects_gradient_fill(): void
    {
        $stops = [
            new ColorStop(0.0, 200, 100, 50),
            new ColorStop(1.0, 50, 100, 200),
        ];
        $gradient = new LinearGradient(0, 0, 9, 0, $stops);

        $none = Canvas::createBlank(10, 1);
        $none->drawPath(
            Path::rect(0, 0, 10, 1),
            $gradient,
            null,
        );

        $dithered = Canvas::createBlank(10, 1);
        $dithered->setDithering(Dithering::Ordered4x4);
        $dithered->drawPath(
            Path::rect(0, 0, 10, 1),
            $gradient,
            null,
        );

        $different = false;
        for ($x = 0; $x < 10; $x++) {
            if ($none->data[0][$x]->fg !== $dithered->data[0][$x]->fg) {
                $different = true;
                break;
            }
        }
        $this->assertTrue($different, 'Dithered gradient should produce different colors than undithered');
    }

    public function test_canvas_paint_dithering_overrides_canvas_default(): void
    {
        $stops = [
            new ColorStop(0.0, 200, 100, 50),
            new ColorStop(1.0, 50, 100, 200),
        ];
        $gradientNone = new LinearGradient(0, 0, 9, 0, $stops, dithering: Dithering::None);
        $gradientDithered = new LinearGradient(0, 0, 9, 0, $stops, dithering: Dithering::Ordered4x4);

        $canvasDitherDefault = Canvas::createBlank(10, 1);
        $canvasDitherDefault->setDithering(Dithering::Ordered4x4);
        $canvasDitherDefault->drawPath(
            Path::rect(0, 0, 10, 1),
            $gradientNone,
            null,
        );

        $canvasNoneDefault = Canvas::createBlank(10, 1);
        $canvasNoneDefault->drawPath(
            Path::rect(0, 0, 10, 1),
            $gradientDithered,
            null,
        );

        $different = false;
        for ($x = 0; $x < 10; $x++) {
            if ($canvasDitherDefault->data[0][$x]->fg !== $canvasNoneDefault->data[0][$x]->fg) {
                $different = true;
                break;
            }
        }
        $this->assertTrue($different, 'Paint dithering override should differ from canvas default');
    }

    public function test_drawPoint_stores_dithering_metadata_when_enabled(): void
    {
        $canvas = Canvas::createBlank(10, 10, true);
        $canvas->setDithering(Dithering::ShaderBlocks);

        $grad = new LinearGradient(0, 0, 9, 0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);

        $canvas->drawPoint(5, 5, $grad);

        $pixel = $canvas->getPixel(5, 5);
        $this->assertNotNull($pixel->fg);
        $this->assertTrue($pixel->dithered);
        $this->assertGreaterThanOrEqual(0, $pixel->secondBest);
        $this->assertGreaterThan(0, $pixel->t);
    }

    public function test_drawPoint_no_metadata_without_dithering(): void
    {
        $canvas = Canvas::createBlank(10, 10, true);

        $grad = new LinearGradient(0, 0, 9, 0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);

        $canvas->drawPoint(5, 5, $grad);

        $pixel = $canvas->getPixel(5, 5);
        $this->assertNotNull($pixel->fg);
        $this->assertFalse($pixel->dithered);
        $this->assertSame(-1, $pixel->secondBest);
        $this->assertSame(0.0, $pixel->t);
    }

    public function test_toString_uses_shade_chars_when_both_pixels_dithered(): void
    {
        $canvas = Canvas::createBlank(4, 2, true);
        $canvas->fillColor(0, 0, new Color(1, 1));

        $pixel1 = $canvas->getPixel(0, 0);
        $pixel1->fg = 4;
        $pixel1->dithered = true;
        $pixel1->secondBest = 40;
        $pixel1->t = 0.5;

        $pixel2 = $canvas->getPixel(0, 1);
        $pixel2->fg = 12;
        $pixel2->dithered = true;
        $pixel2->secondBest = 48;
        $pixel2->t = 0.3;

        $output = (string) $canvas;
        $this->assertStringContainsString('▒', $output);
        $this->assertStringNotContainsString('▀', $output);
    }

    public function test_toString_uses_halfblock_when_only_one_pixel_dithered(): void
    {
        $canvas = Canvas::createBlank(4, 2, true);
        $canvas->fillColor(0, 0, new Color(1, 1));

        $pixel1 = $canvas->getPixel(0, 0);
        $pixel1->fg = 4;
        $pixel1->dithered = true;
        $pixel1->secondBest = 40;
        $pixel1->t = 0.5;

        $pixel2 = $canvas->getPixel(0, 1);
        $pixel2->fg = 12;

        $output = (string) $canvas;
        $this->assertStringContainsString('▀', $output);
    }

    public function test_toString_uses_halfblock_when_neither_pixel_dithered(): void
    {
        $canvas = Canvas::createBlank(4, 2, true);
        $canvas->fillColor(0, 0, new Color(1, 1));

        $pixel1 = $canvas->getPixel(0, 0);
        $pixel1->fg = 4;

        $pixel2 = $canvas->getPixel(0, 1);
        $pixel2->fg = 12;

        $output = (string) $canvas;
        $this->assertStringContainsString('▀', $output);
    }

    public function test_shade_char_selection_by_avg_t(): void
    {
        $cases = [
            [0.1, 0.2, '░'],
            [0.3, 0.4, '▒'],
            [0.5, 0.5, '▒'],
            [0.7, 0.7, '▓'],
            [0.9, 0.8, '▓'],
        ];
        foreach ($cases as [$t1, $t2, $expectedChar]) {
            $canvas = Canvas::createBlank(4, 2, true);
            $canvas->fillColor(0, 0, new Color(1, 1));

            $pixel1 = $canvas->getPixel(0, 0);
            $pixel1->fg = 4;
            $pixel1->dithered = true;
            $pixel1->secondBest = 40;
            $pixel1->t = $t1;

            $pixel2 = $canvas->getPixel(0, 1);
            $pixel2->fg = 12;
            $pixel2->dithered = true;
            $pixel2->secondBest = 48;
            $pixel2->t = $t2;

            $output = (string) $canvas;
            $this->assertStringContainsString($expectedChar, $output, "avg t=" . (($t1 + $t2) / 2) . " should use '$expectedChar'");
        }
    }

    public function test_drawPoint_solid_color_no_metadata(): void
    {
        $canvas = Canvas::createBlank(10, 10, true);
        $canvas->setDithering(Dithering::ShaderBlocks);

        $canvas->drawPoint(5, 5, new Color(4));

        $pixel = $canvas->getPixel(5, 5);
        $this->assertSame(4, $pixel->fg);
        $this->assertFalse($pixel->dithered);
    }

    public function test_drawPoint_solid_color_with_shader_blocks_all_sets_dithered(): void
    {
        $canvas = Canvas::createBlank(10, 10, true);
        $canvas->setDithering(Dithering::ShaderBlocksAll);

        $canvas->drawPoint(5, 5, new Color(4));

        $pixel = $canvas->getPixel(5, 5);
        $this->assertSame(4, $pixel->fg);
        $this->assertTrue($pixel->dithered);
    }

    public function test_fill_polygon_solid_color_with_shader_blocks_all_sets_dithered(): void
    {
        $canvas = Canvas::createBlank(10, 10, true);
        $canvas->setDithering(Dithering::ShaderBlocksAll);

        $canvas->drawPath(
            Path::polygon([[1.0, 1.0], [8.0, 1.0], [8.0, 8.0], [1.0, 8.0]]),
            new Color(4),
            null
        );

        $pixel = $canvas->getPixel(4, 4);
        $this->assertSame(4, $pixel->fg);
        $this->assertTrue($pixel->dithered);
    }

    public function test_stroke_solid_color_with_shader_blocks_all_sets_dithered(): void
    {
        $canvas = Canvas::createBlank(20, 10, true);
        $canvas->setDithering(Dithering::ShaderBlocksAll);

        $canvas->drawPath(
            Path::line(2, 5, 8, 5),
            null,
            new StrokeStyle(new Color(4))
        );

        $pixel = $canvas->getPixel(5, 5);
        $this->assertSame(4, $pixel->fg);
        $this->assertTrue($pixel->dithered);
    }

    public function test_shader_blocks_all_solid_shape_produces_shade_chars(): void
    {
        $canvas = Canvas::createBlank(10, 6, true);
        $canvas->fillColor(0, 0, new Color(1, 1));
        $canvas->setDithering(Dithering::ShaderBlocksAll);

        $grad = new LinearGradient(0, 0, 0, 5, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 255, 0),
        ]);
        $canvas->drawPath(Path::rect(0, 0, 10, 6), $grad, null);
        $canvas->drawPath(Path::rect(0, 0, 10, 2), new Color(4), null);

        $output = (string) $canvas;
        $hasShadeChar = str_contains($output, '░') || str_contains($output, '▒') || str_contains($output, '▓');
        $this->assertTrue($hasShadeChar, 'Solid shape over gradient with ShaderBlocksAll should produce shade characters');
    }

    public function test_gradient_with_dithering_produces_shade_chars(): void
    {
        $canvas = Canvas::createBlank(80, 48, true);
        $canvas->fillColor(0, 0, new Color(1, 1));
        $canvas->setDithering(Dithering::ShaderBlocks);

        $grad = new LinearGradient(0, 0, 0, 47, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);
        $canvas->drawPath(Path::rect(0, 0, 80, 48), $grad, null);

        $output = (string) $canvas;
        $hasShadeChar = str_contains($output, '░') || str_contains($output, '▒') || str_contains($output, '▓');
        $this->assertTrue($hasShadeChar, 'Gradient with shader blocks dithering should produce at least one shade character');
    }

    public function test_gradient_without_dithering_no_shade_chars(): void
    {
        $canvas = Canvas::createBlank(80, 48, true);
        $canvas->fillColor(0, 0, new Color(1, 1));

        $grad = new LinearGradient(0, 0, 0, 47, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);
        $canvas->drawPath(Path::rect(0, 0, 80, 48), $grad, null);

        $output = (string) $canvas;
        $this->assertStringNotContainsString('░', $output);
        $this->assertStringNotContainsString('▒', $output);
        $this->assertStringNotContainsString('▓', $output);
    }
}
