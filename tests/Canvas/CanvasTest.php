<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\Color;
use PHPUnit\Framework\TestCase;

class CanvasTest extends TestCase
{
    public function test_draw_polygon_with_both_colors_null_is_noop(): void
    {
        $canvas = Canvas::createBlank(10, 10);

        $canvas->drawPolygon(
            [[1.0, 1.0], [5.0, 1.0], [5.0, 5.0], [1.0, 5.0]],
            null,
            null
        );

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
        $canvas->drawPolygon(
            [[1.0, 1.0], [5.0, 1.0], [5.0, 5.0], [1.0, 5.0]],
            null,
            $outline
        );

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
        $canvas->drawPolygon(
            [[1.0, 1.0], [5.0, 1.0], [5.0, 5.0], [1.0, 5.0]],
            $fill,
            null
        );

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
        $canvas->drawPolygon(
            [[1.0, 1.0], [5.0, 1.0], [5.0, 5.0], [1.0, 5.0]],
            $fill,
            $outline
        );

        // Corners are on the outline, so they must show the outline color
        // (outline is drawn on top of fill).
        $this->assertSame(5, $canvas->data[1][1]->fg);
        $this->assertSame(5, $canvas->data[5][1]->fg);
        $this->assertSame(5, $canvas->data[5][5]->fg);
        $this->assertSame(5, $canvas->data[1][5]->fg);

        // Interior pixels are pure fill.
        $this->assertSame(3, $canvas->data[2][2]->fg);
        $this->assertSame(3, $canvas->data[3][3]->fg);

        // Outside pixels are untouched.
        $this->assertNull($canvas->data[0][0]->fg);
        $this->assertNull($canvas->data[6][6]->fg);
    }
}
