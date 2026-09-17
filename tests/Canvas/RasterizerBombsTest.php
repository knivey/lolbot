<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\Path;
use draw\Color;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

class RasterizerBombsTest extends TestCase
{
    public function test_huge_coordinate_polygon_fills_bounded(): void
    {
        //pre-fix this iterates ~1e9 scanlines; the test process would hang for minutes.
        //post-fix it fills only the canvas intersection and returns fast.
        $canvas = Canvas::createBlank(80, 40);
        $path = Path::polygon([[0, 0], [1000000000, 1000000000], [0, 1000000000]]);
        $start = hrtime(true);
        $canvas->drawPath($path, new Color(1, null), null);
        $ms = (hrtime(true) - $start) / 1e6;
        $this->assertLessThan(5000.0, $ms, 'scanline fill should be bounded by canvas size');
        $painted = 0;
        for ($y = 0; $y < 40; $y++) {
            for ($x = 0; $x < 80; $x++) {
                if ($canvas->data[$y][$x]->fg !== null) {
                    $painted++;
                }
            }
        }
        $this->assertGreaterThan(700, $painted, 'huge triangle should still paint its canvas intersection');
    }

    public function test_huge_dash_offset_terminates(): void
    {
        $canvas = Canvas::createBlank(80, 40);
        $path = Path::line(5, 20, 75, 20);
        $start = hrtime(true);
        $canvas->drawPath($path, null, new \draw\StrokeStyle(new Color(1, null), dashArray: [0.001, 0.001], dashOffset: INF));
        $canvas->drawPath($path, null, new \draw\StrokeStyle(new Color(1, null), dashArray: [0.001, 0.001], dashOffset: 1000000));
        $ms = (hrtime(true) - $start) / 1e6;
        $this->assertLessThan(5000.0, $ms, 'dash pre-roll must be bounded');
    }

    public function test_normal_fill_unchanged(): void
    {
        $canvas = Canvas::createBlank(20, 20);
        $path = Path::polygon([[2, 2], [17, 2], [17, 17], [2, 17]]);
        $canvas->drawPath($path, new Color(4, null), null);
        $this->assertNotNull($canvas->data[10][10]->fg);
        $this->assertNull($canvas->data[0][0]->fg);
    }
}
