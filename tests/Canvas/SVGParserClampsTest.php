<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\SVGParser;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

class SVGParserClampsTest extends TestCase
{
    private function render(string $inner, int $w = 80, int $h = 40): Canvas
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 40">' . $inner . '</svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank($w, $h);
        $doc->render($canvas);
        return $canvas;
    }

    public function test_circular_clippath_throws(): void
    {
        $inner = '<defs><clipPath id="a"><rect clip-path="url(#a)" width="10" height="10"/></clipPath></defs>'
            . '<rect width="80" height="40" fill="red" clip-path="url(#a)"/>';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('circular');
        $this->render($inner);
    }

    public function test_circular_mask_throws(): void
    {
        $inner = '<defs><mask id="m"><rect clip-path="url(#c)" width="10" height="10"/></mask>'
            . '<clipPath id="c"><rect mask="url(#m)" width="10" height="10"/></clipPath></defs>'
            . '<rect width="80" height="40" fill="red" mask="url(#m)"/>';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('circular');
        $this->render($inner);
    }

    public function test_huge_stroke_width_renders(): void
    {
        $canvas = $this->render('<path d="M10 20 L70 20" stroke="red" stroke-width="1000000000" fill="none"/>');
        $this->assertSame(80, $canvas->w);
    }

    public function test_huge_blur_stddev_renders(): void
    {
        $canvas = $this->render('<defs><filter id="f"><feGaussianBlur stdDeviation="1e9"/></filter></defs>'
            . '<rect width="30" height="20" fill="red" filter="url(#f)"/>');
        $this->assertSame(80, $canvas->w);
    }

    public function test_huge_font_size_renders(): void
    {
        $canvas = $this->render('<text x="10" y="30" font-size="1e9" fill="red">A</text>');
        $this->assertSame(80, $canvas->w);
    }

    public function test_huge_dash_pattern_renders(): void
    {
        $canvas = $this->render('<path d="M5 20 L75 20" stroke="red" fill="none" stroke-dasharray="0.001 0.001"/>');
        $this->assertSame(80, $canvas->w);
    }
}
