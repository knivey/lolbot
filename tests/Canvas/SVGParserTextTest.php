<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\SVGParser;
use PHPUnit\Framework\TestCase;

class SVGParserTextTest extends TestCase
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

    public function test_parse_text_element(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 20">'
            . '<text x="5" y="15" font-size="10" font-family="DejaVu Sans" fill="red">Hi</text>'
            . '</svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(80, 20);
        $doc->render($canvas);
        $this->assertGreaterThan(0, $this->countRenderedPixels($canvas));
    }

    public function test_parse_text_with_tspan(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 20">'
            . '<text x="5" y="15" font-size="10" font-family="DejaVu Sans" fill="white">'
            . 'Hi<tspan fill="red">!</tspan>'
            . '</text>'
            . '</svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(80, 20);
        $doc->render($canvas);
        $this->assertGreaterThan(0, $this->countRenderedPixels($canvas));
    }

    public function test_text_in_group(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 20">'
            . '<g fill="white"><text x="5" y="15" font-size="10" font-family="DejaVu Sans">OK</text></g>'
            . '</svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(80, 20);
        $doc->render($canvas);
        $this->assertGreaterThan(0, $this->countRenderedPixels($canvas));
    }

    public function test_text_with_transform(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 20">'
            . '<text x="5" y="15" font-size="10" font-family="DejaVu Sans" fill="white" transform="translate(10,0)">A</text>'
            . '</svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(80, 20);
        $doc->render($canvas);
        $this->assertGreaterThan(0, $this->countRenderedPixels($canvas));
    }

    public function test_text_with_text_anchor(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 20">'
            . '<text x="40" y="15" font-size="10" font-family="DejaVu Sans" fill="white" text-anchor="middle">AB</text>'
            . '</svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(80, 20);
        $doc->render($canvas);
        $this->assertGreaterThan(0, $this->countRenderedPixels($canvas));
    }
}
