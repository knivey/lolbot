<?php

namespace Tests\Canvas;

use draw\ColorStop;
use draw\LinearGradient;
use draw\SpreadMethod;
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

    public function test_linear_gradient_fewer_than_two_stops_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
        ]);
    }

    public function test_linear_gradient_empty_stops_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LinearGradient(0.0, 0.0, 10.0, 0.0, []);
    }

    public function test_linear_gradient_horizontal_start_color(): void
    {
        $g = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);
        $rgb = $g->getColorAt(0.0, 5.0);
        $this->assertSame([255, 0, 0], $rgb);
    }

    public function test_linear_gradient_horizontal_end_color(): void
    {
        $g = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);
        $rgb = $g->getColorAt(10.0, 5.0);
        $this->assertSame([0, 0, 255], $rgb);
    }

    public function test_linear_gradient_horizontal_midpoint(): void
    {
        $g = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(0.0, 0, 0, 0),
            new ColorStop(1.0, 200, 200, 200),
        ]);
        $rgb = $g->getColorAt(5.0, 5.0);
        $this->assertSame([100, 100, 100], $rgb);
    }

    public function test_linear_gradient_vertical(): void
    {
        $g = new LinearGradient(0.0, 0.0, 0.0, 10.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 255, 0),
        ]);
        $rgb = $g->getColorAt(5.0, 5.0);
        $this->assertSame([128, 128, 0], $rgb);
    }

    public function test_linear_gradient_degenerate_vector_returns_first_stop(): void
    {
        $g = new LinearGradient(5.0, 5.0, 5.0, 5.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);
        $rgb = $g->getColorAt(0.0, 0.0);
        $this->assertSame([255, 0, 0], $rgb);
    }

    public function test_linear_gradient_stops_are_sorted(): void
    {
        $g = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(1.0, 0, 0, 255),
            new ColorStop(0.0, 255, 0, 0),
        ]);
        $rgb = $g->getColorAt(0.0, 5.0);
        $this->assertSame([255, 0, 0], $rgb);
        $rgb = $g->getColorAt(10.0, 5.0);
        $this->assertSame([0, 0, 255], $rgb);
    }

    public function test_linear_gradient_three_stops(): void
    {
        $g = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(0.5, 0, 255, 0),
            new ColorStop(1.0, 0, 0, 255),
        ]);
        $rgb = $g->getColorAt(2.5, 5.0);
        $this->assertSame([128, 128, 0], $rgb);
    }

    public function test_linear_gradient_duplicate_offset_sharp_edge(): void
    {
        $g = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(0.5, 0, 0, 0),
            new ColorStop(0.5, 255, 255, 255),
            new ColorStop(1.0, 0, 0, 255),
        ]);
        $before = $g->getColorAt(4.9, 5.0);
        $this->assertSame([5, 0, 0], $before);
        $after = $g->getColorAt(5.0, 5.0);
        $this->assertSame([255, 255, 255], $after);
    }

    public function test_linear_gradient_pad_before_start(): void
    {
        $g = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ], SpreadMethod::Pad);
        $rgb = $g->getColorAt(-5.0, 5.0);
        $this->assertSame([255, 0, 0], $rgb);
    }

    public function test_linear_gradient_pad_after_end(): void
    {
        $g = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(0.0, 255, 0, 0),
            new ColorStop(1.0, 0, 0, 255),
        ], SpreadMethod::Pad);
        $rgb = $g->getColorAt(15.0, 5.0);
        $this->assertSame([0, 0, 255], $rgb);
    }

    public function test_linear_gradient_reflect_t_1_3(): void
    {
        $g = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(0.0, 0, 0, 0),
            new ColorStop(1.0, 100, 0, 0),
        ], SpreadMethod::Reflect);
        $rgb = $g->getColorAt(13.0, 5.0);
        $this->assertSame([70, 0, 0], $rgb);
    }

    public function test_linear_gradient_reflect_t_neg_0_2(): void
    {
        $g = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(0.0, 0, 0, 0),
            new ColorStop(1.0, 100, 0, 0),
        ], SpreadMethod::Reflect);
        $rgb = $g->getColorAt(-2.0, 5.0);
        $this->assertSame([20, 0, 0], $rgb);
    }

    public function test_linear_gradient_repeat_t_1_3(): void
    {
        $g = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(0.0, 0, 0, 0),
            new ColorStop(1.0, 100, 0, 0),
        ], SpreadMethod::Repeat);
        $rgb = $g->getColorAt(13.0, 5.0);
        $this->assertSame([30, 0, 0], $rgb);
    }

    public function test_linear_gradient_repeat_t_neg_0_3(): void
    {
        $g = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new ColorStop(0.0, 0, 0, 0),
            new ColorStop(1.0, 100, 0, 0),
        ], SpreadMethod::Repeat);
        $rgb = $g->getColorAt(-3.0, 5.0);
        $this->assertSame([70, 0, 0], $rgb);
    }
}
