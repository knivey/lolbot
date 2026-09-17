<?php

namespace Tests\Artbot;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../artbot_scripts/svg.php';

class SvgComputeRenderSizeTest extends TestCase
{
    public function test_default_80_col_sizing(): void
    {
        [$w, $h] = svgComputeRenderSize(100.0, 50.0, 0, 0, 0, false);
        $this->assertSame(80, $w);
        $this->assertSame(40, $h);
    }

    public function test_aspect_bomb_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('svg render too large');
        svgComputeRenderSize(0.001, 1000000000.0, 0, 0, 0, false);
    }

    public function test_width_option_clamped_to_500(): void
    {
        [$w, $h] = svgComputeRenderSize(100.0, 100.0, 50000, 0, 0, false);
        $this->assertSame(500, $w);
        $this->assertSame(500, $h);
    }

    public function test_supersample_bomb_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        svgComputeRenderSize(100.0, 100.0, 1000, 0, 4, false);
    }

    public function test_explicit_user_dims_bomb_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        svgComputeRenderSize(100.0, 100.0, 999, 9999, 0, false);
    }

    public function test_height_only_sizing(): void
    {
        [$w, $h] = svgComputeRenderSize(100.0, 50.0, 0, 20, 0, false);
        $this->assertSame(20, $h);
        $this->assertSame(40, $w);
    }
}
