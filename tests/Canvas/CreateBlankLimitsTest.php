<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\RenderLimits;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

class CreateBlankLimitsTest extends TestCase
{
    public function test_legal_size_still_works(): void
    {
        $canvas = Canvas::createBlank(400, 400);
        $this->assertSame(400, $canvas->w);
        $this->assertSame(400, $canvas->h);
    }

    public function test_zero_or_negative_dimension_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('canvas too large');
        Canvas::createBlank(0, 100);
    }

    public function test_total_pixels_over_cap_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('canvas too large');
        Canvas::createBlank((int)(RenderLimits::maxCanvasPixels / 1000) + 1, 1000);
    }

    public function test_side_over_cap_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('canvas too large');
        Canvas::createBlank(1, RenderLimits::maxCanvasSide + 1);
    }
}
