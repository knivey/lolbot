<?php

namespace Tests\Canvas;

use draw\Color;
use draw\ColorStop;
use draw\LinearGradient;
use draw\Paint;
use draw\RadialGradient;
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

    public function test_linear_gradient_is_not_solid(): void
    {
        $g = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);
        $this->assertFalse($g->isSolid());
    }

    public function test_linear_gradient_get_color_at_returns_valid_rgb(): void
    {
        $g = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);
        $rgb = $g->getColorAt(5.0, 5.0);
        $this->assertCount(3, $rgb);
        foreach ($rgb as $c) {
            $this->assertGreaterThanOrEqual(0, $c);
            $this->assertLessThanOrEqual(255, $c);
        }
    }

    public function test_radial_gradient_is_not_solid(): void
    {
        $g = new RadialGradient(5.0, 5.0, 10.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);
        $this->assertFalse($g->isSolid());
    }

    public function test_radial_gradient_get_color_at_returns_valid_rgb(): void
    {
        $g = new RadialGradient(5.0, 5.0, 10.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);
        $rgb = $g->getColorAt(20.0, 20.0);
        $this->assertCount(3, $rgb);
        foreach ($rgb as $c) {
            $this->assertGreaterThanOrEqual(0, $c);
            $this->assertLessThanOrEqual(255, $c);
        }
    }
}
