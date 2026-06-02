<?php

namespace Tests\Canvas;

use draw\Pixel;
use PHPUnit\Framework\TestCase;

class PixelTest extends TestCase
{
    public function test_default_alpha_is_fully_opaque(): void
    {
        $p = new Pixel();
        $this->assertSame(1.0, $p->fgAlpha);
        $this->assertSame(1.0, $p->bgAlpha);
    }

    public function test_alpha_can_be_set_and_read(): void
    {
        $p = new Pixel();
        $p->fgAlpha = 0.5;
        $p->bgAlpha = 0.25;
        $this->assertSame(0.5, $p->fgAlpha);
        $this->assertSame(0.25, $p->bgAlpha);
    }

    public function test_existing_properties_unchanged(): void
    {
        $p = new Pixel();
        $this->assertNull($p->fg);
        $this->assertNull($p->bg);
        $this->assertSame(' ', $p->text);

        $p->fg = 4;
        $p->bg = 12;
        $p->text = '█';
        $this->assertSame(4, $p->fg);
        $this->assertSame(12, $p->bg);
        $this->assertSame('█', $p->text);
    }
}
