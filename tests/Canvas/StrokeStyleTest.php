<?php

namespace Tests\Canvas;

use draw\Color;
use draw\LineCap;
use draw\LineJoin;
use draw\StrokeStyle;
use PHPUnit\Framework\TestCase;

class StrokeStyleTest extends TestCase
{
    public function test_default_values(): void
    {
        $s = new StrokeStyle(new Color(4, null));
        $this->assertSame(4, $s->color->fg);
        $this->assertSame(1.0, $s->width);
        $this->assertNull($s->dashArray);
        $this->assertSame(0.0, $s->dashOffset);
        $this->assertSame(LineCap::Butt, $s->lineCap);
        $this->assertSame(LineJoin::Miter, $s->lineJoin);
        $this->assertSame(4.0, $s->miterLimit);
    }

    public function test_custom_values(): void
    {
        $s = new StrokeStyle(
            new Color(5, null),
            width: 3.0,
            dashArray: [5.0, 3.0],
            dashOffset: 2.0,
            lineCap: LineCap::Round,
            lineJoin: LineJoin::Bevel,
            miterLimit: 8.0
        );
        $this->assertSame(5, $s->color->fg);
        $this->assertSame(3.0, $s->width);
        $this->assertSame([5.0, 3.0], $s->dashArray);
        $this->assertSame(2.0, $s->dashOffset);
        $this->assertSame(LineCap::Round, $s->lineCap);
        $this->assertSame(LineJoin::Bevel, $s->lineJoin);
        $this->assertSame(8.0, $s->miterLimit);
    }
}
