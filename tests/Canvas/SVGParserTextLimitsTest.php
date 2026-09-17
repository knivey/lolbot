<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\RenderLimits;
use draw\SVGParser;
use draw\TextNode;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

class SVGParserTextLimitsTest extends TestCase
{
    public function test_huge_text_content_truncated_at_parse(): void
    {
        //pre-fix: a ~100KB <text> node passes every structural cap and
        //rasterizes one glyph path per character
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 40">'
            . '<text x="5" y="30" font-size="16" fill="red">' . str_repeat('A', 100000) . '</text>'
            . '</svg>';
        $start = hrtime(true);
        $doc = SVGParser::parseString($svg);
        $textNode = $doc->getRoot()->getChildren()[0];
        $this->assertInstanceOf(TextNode::class, $textNode);
        $this->assertSame(RenderLimits::maxTextLength, strlen($textNode->text));
        $this->assertSame('AAAA', substr($textNode->text, 0, 4));
        $canvas = Canvas::createBlank(80, 40);
        $doc->render($canvas);
        $ms = (hrtime(true) - $start) / 1e6;
        $this->assertLessThan(5000.0, $ms, 'truncated text should render quickly');
    }

    public function test_huge_tspan_content_truncated_at_parse(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 40">'
            . '<text x="5" y="30" font-size="16" fill="red">ok<tspan>' . str_repeat('B', 100000) . '</tspan></text>'
            . '</svg>';
        $doc = SVGParser::parseString($svg);
        $textNode = $doc->getRoot()->getChildren()[0];
        $this->assertInstanceOf(TextNode::class, $textNode);
        $this->assertCount(1, $textNode->tspans);
        $this->assertSame(RenderLimits::maxTextLength, strlen($textNode->tspans[0]->text));
    }
}
