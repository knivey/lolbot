<?php

namespace Tests\Canvas;

use draw\Color;
use draw\LineCap;
use draw\LineJoin;
use draw\StrokeStyle;
use PHPUnit\Framework\TestCase;

class StrokeStyleTest extends TestCase
{
    public function test_default_opacity_is_one(): void
    {
        $s = new StrokeStyle(new Color(4, null));
        $this->assertSame(1.0, $s->opacity);
    }

    public function test_opacity_can_be_set(): void
    {
        $s = new StrokeStyle(new Color(4, null), opacity: 0.5);
        $this->assertSame(0.5, $s->opacity);
    }

    public function test_zero_opacity_is_valid(): void
    {
        $s = new StrokeStyle(new Color(4, null), opacity: 0.0);
        $this->assertSame(0.0, $s->opacity);
    }

    public function test_opacity_is_clamped_below_zero(): void
    {
        $s = new StrokeStyle(new Color(4, null), opacity: -0.5);
        $this->assertSame(0.0, $s->opacity);
    }

    public function test_opacity_is_clamped_above_one(): void
    {
        $s = new StrokeStyle(new Color(4, null), opacity: 1.5);
        $this->assertSame(1.0, $s->opacity);
    }

    public function test_negative_dash_array_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StrokeStyle(new Color(4, null), dashArray: [-1.0]);
    }

    public function test_existing_properties_unchanged(): void
    {
        $s = new StrokeStyle(
            new Color(4, null),
            width: 3.0,
            dashArray: [4.0, 2.0],
            dashOffset: 1.0,
            lineCap: LineCap::Round,
            lineJoin: LineJoin::Bevel,
            miterLimit: 2.0,
        );
        $this->assertSame(4, $s->paint->fg);
        $this->assertSame(3.0, $s->width);
        $this->assertSame([4.0, 2.0], $s->dashArray);
        $this->assertSame(1.0, $s->dashOffset);
        $this->assertSame(LineCap::Round, $s->lineCap);
        $this->assertSame(LineJoin::Bevel, $s->lineJoin);
        $this->assertSame(2.0, $s->miterLimit);
    }
}
