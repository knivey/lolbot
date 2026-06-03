<?php

namespace Tests\Canvas;

use draw\ColorStop;
use PHPUnit\Framework\TestCase;

class GradientTest extends TestCase
{
    public function test_color_stop_valid_construction(): void
    {
        $stop = new ColorStop(0.0, 255, 0, 0);
        $this->assertSame(0.0, $stop->offset);
        $this->assertSame(255, $stop->r);
        $this->assertSame(0, $stop->g);
        $this->assertSame(0, $stop->b);
    }

    public function test_color_stop_offset_below_zero_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ColorStop(-0.1, 0, 0, 0);
    }

    public function test_color_stop_offset_above_one_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ColorStop(1.1, 0, 0, 0);
    }

    public function test_color_stop_r_below_zero_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ColorStop(0.5, -1, 0, 0);
    }

    public function test_color_stop_r_above_255_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ColorStop(0.5, 256, 0, 0);
    }

    public function test_color_stop_g_below_zero_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ColorStop(0.5, 0, -1, 0);
    }

    public function test_color_stop_g_above_255_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ColorStop(0.5, 0, 256, 0);
    }

    public function test_color_stop_b_below_zero_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ColorStop(0.5, 0, 0, -1);
    }

    public function test_color_stop_b_above_255_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ColorStop(0.5, 0, 0, 256);
    }

    public function test_color_stop_boundary_values(): void
    {
        $s1 = new ColorStop(0.0, 0, 0, 0);
        $this->assertSame(0.0, $s1->offset);
        $s2 = new ColorStop(1.0, 255, 255, 255);
        $this->assertSame(1.0, $s2->offset);
    }
}
