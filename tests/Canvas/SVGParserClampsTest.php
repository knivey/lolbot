<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\Shape;
use draw\StrokeStyle;
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
        $clamped = $this->render('<path d="M10 20 L70 20" stroke="red" stroke-width="500" fill="none"/>');
        $this->assertSame((string)$clamped, (string)$canvas);
    }

    public function test_huge_blur_stddev_renders(): void
    {
        $canvas = $this->render('<defs><filter id="f"><feGaussianBlur stdDeviation="1e9"/></filter></defs>'
            . '<rect width="30" height="20" fill="red" filter="url(#f)"/>');
        $this->assertSame(80, $canvas->w);
        $clamped = $this->render('<defs><filter id="f"><feGaussianBlur stdDeviation="100"/></filter></defs>'
            . '<rect width="30" height="20" fill="red" filter="url(#f)"/>');
        $this->assertSame((string)$clamped, (string)$canvas);
    }

    public function test_huge_font_size_renders(): void
    {
        $canvas = $this->render('<text x="10" y="30" font-size="1e9" fill="red">A</text>');
        $this->assertSame(80, $canvas->w);
        $clamped = $this->render('<text x="10" y="30" font-size="1000" fill="red">A</text>');
        $this->assertSame((string)$clamped, (string)$canvas);
    }

    public function test_huge_tspan_font_size_renders(): void
    {
        $canvas = $this->render('<text x="10" y="30" font-size="1e9" fill="red">A<tspan font-size="1e9">B</tspan></text>');
        $clamped = $this->render('<text x="10" y="30" font-size="1000" fill="red">A<tspan font-size="1000">B</tspan></text>');
        $this->assertSame((string)$clamped, (string)$canvas);
    }

    public function test_huge_dash_pattern_renders(): void
    {
        $canvas = $this->render('<path d="M5 20 L75 20" stroke="red" fill="none" stroke-dasharray="0.001 0.001"/>');
        $this->assertSame(80, $canvas->w);
    }

    public function test_dash_pattern_entries_sliced_to_cap(): void
    {
        $many = trim(str_repeat('2 2 ', 100));
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 40">'
            . '<path d="M5 20 L75 20" stroke="red" fill="none" stroke-dasharray="' . $many . '"/></svg>';
        $doc = SVGParser::parseString($svg);
        $shape = $doc->getRoot()->getChildren()[0];
        $this->assertInstanceOf(Shape::class, $shape);
        $stroke = $shape->stroke;
        $this->assertInstanceOf(StrokeStyle::class, $stroke);
        $dashArray = $stroke->dashArray;
        $this->assertNotNull($dashArray);
        $this->assertCount(16, $dashArray);
    }

    public function test_odd_dash_array_offset_period_doubled(): void
    {
        //odd-entry dash arrays repeat with visual period 2*dashLen, so
        //offset 9 (3 + 2*3) must land on the same phase as offset 3
        $offset0 = $this->render('<path d="M5 20 L75 20" stroke="red" fill="none" stroke-dasharray="3" stroke-dashoffset="0"/>');
        $offset3 = $this->render('<path d="M5 20 L75 20" stroke="red" fill="none" stroke-dasharray="3" stroke-dashoffset="3"/>');
        $offset9 = $this->render('<path d="M5 20 L75 20" stroke="red" fill="none" stroke-dasharray="3" stroke-dashoffset="9"/>');
        $this->assertSame((string)$offset3, (string)$offset9);
        //offset 3 is half the visual period, so it must not fold to phase 0
        $this->assertNotSame((string)$offset0, (string)$offset3);
    }

    public function test_even_dash_array_offset_folds_by_period(): void
    {
        //even-entry dash arrays have period dashLen, so offset 6 folds to 0
        $offset0 = $this->render('<path d="M5 20 L75 20" stroke="red" fill="none" stroke-dasharray="3 3" stroke-dashoffset="0"/>');
        $offset6 = $this->render('<path d="M5 20 L75 20" stroke="red" fill="none" stroke-dasharray="3 3" stroke-dashoffset="6"/>');
        $this->assertSame((string)$offset0, (string)$offset6);
    }
}
