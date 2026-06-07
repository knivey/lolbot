<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\SVGParser;
use PHPUnit\Framework\TestCase;

class SVGTextIntegrationTest extends TestCase
{
    private function countRenderedPixels(Canvas $canvas): int
    {
        $count = 0;
        for ($y = 0; $y < $canvas->h; $y++) {
            for ($x = 0; $x < $canvas->w; $x++) {
                if ($canvas->data[$y][$x]->fg !== null) {
                    $count++;
                }
            }
        }
        return $count;
    }

    public function test_full_svg_with_text_and_shapes(): void
    {
        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 30">
  <rect x="0" y="0" width="80" height="30" fill="#333"/>
  <text x="40" y="20" font-size="12" font-family="DejaVu Sans" fill="white" text-anchor="middle">Test</text>
</svg>
SVG;
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(80, 30);
        $doc->render($canvas);
        $this->assertGreaterThan(10, $this->countRenderedPixels($canvas));
    }

    public function test_text_with_gradient_fill(): void
    {
        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 30">
  <defs>
    <linearGradient id="grad" x1="0%" y1="0%" x2="100%" y2="0%">
      <stop offset="0%" stop-color="red"/>
      <stop offset="100%" stop-color="blue"/>
    </linearGradient>
  </defs>
  <text x="5" y="20" font-size="14" font-family="DejaVu Sans" fill="url(#grad)">Grad</text>
</svg>
SVG;
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(80, 30);
        $doc->render($canvas);
        $this->assertGreaterThan(0, $this->countRenderedPixels($canvas));
    }

    public function test_text_stroke_only(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 30">'
            . '<text x="5" y="20" font-size="12" font-family="DejaVu Sans" fill="none" stroke="white" stroke-width="1">O</text>'
            . '</svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(80, 30);
        $doc->render($canvas);
        $this->assertGreaterThan(0, $this->countRenderedPixels($canvas));
    }

    public function test_text_inside_clippath(): void
    {
        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 30">
  <defs>
    <clipPath id="clip">
      <rect x="0" y="0" width="20" height="30"/>
    </clipPath>
  </defs>
  <text x="5" y="20" font-size="12" font-family="DejaVu Sans" fill="white" clip-path="url(#clip)">Clipped</text>
</svg>
SVG;
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(80, 30);
        $doc->render($canvas);
        $hasPixels = false;
        for ($y = 0; $y < $canvas->h; $y++) {
            for ($x = 0; $x < 20; $x++) {
                if ($canvas->data[$y][$x]->fg !== null) {
                    $hasPixels = true;
                    break 2;
                }
            }
        }
        $this->assertTrue($hasPixels, 'Expected clipped text to render within clip region');
    }
}
