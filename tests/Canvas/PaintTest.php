<?php

namespace Tests\Canvas;

use draw\Color;
use draw\Paint;
use PHPUnit\Framework\TestCase;

class PaintTest extends TestCase
{
    public function test_color_implements_paint(): void
    {
        $color = new Color(4, null);
        $this->assertInstanceOf(Paint::class, $color);
    }

    public function test_color_is_solid(): void
    {
        $color = new Color(4, null);
        $this->assertTrue($color->isSolid());
    }

    public function test_color_get_color_at_returns_rgb(): void
    {
        $color = new Color(0, null);
        $rgb = $color->getColorAt(0.0, 0.0);
        $this->assertSame([255, 255, 255], $rgb);
    }

    public function test_color_get_color_at_null_fg_returns_black(): void
    {
        $color = new Color(null, null);
        $rgb = $color->getColorAt(0.0, 0.0);
        $this->assertSame([0, 0, 0], $rgb);
    }

    public function test_color_get_color_at_consistent_across_positions(): void
    {
        $color = new Color(4, null);
        $rgb1 = $color->getColorAt(0.0, 0.0);
        $rgb2 = $color->getColorAt(100.0, 200.0);
        $this->assertSame($rgb1, $rgb2);
    }
}
