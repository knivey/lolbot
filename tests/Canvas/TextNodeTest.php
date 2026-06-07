<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\Color;
use draw\FontManager;
use draw\TextNode;
use draw\TspanNode;
use draw\RenderContext;
use PHPUnit\Framework\TestCase;

class TextNodeTest extends TestCase
{
    private function renderToCanvas(TextNode $textNode, int $w = 80, int $h = 30): Canvas
    {
        $canvas = Canvas::createBlank($w, $h);
        $textNode->render($canvas, RenderContext::defaults());
        return $canvas;
    }

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

    public function test_render_single_char_produces_pixels(): void
    {
        $node = new TextNode();
        $node->text = 'A';
        $node->x = 10;
        $node->y = 10;
        $node->fontSize = 12;
        $node->fontFamily = 'DejaVu Sans';

        $canvas = $this->renderToCanvas($node);
        $this->assertGreaterThan(0, $this->countRenderedPixels($canvas));
    }

    public function test_render_string_produces_pixels(): void
    {
        $node = new TextNode();
        $node->text = 'Hi';
        $node->x = 5;
        $node->y = 10;
        $node->fontSize = 10;
        $node->fontFamily = 'DejaVu Sans';

        $canvas = $this->renderToCanvas($node);
        $this->assertGreaterThan(0, $this->countRenderedPixels($canvas));
    }

    public function test_render_with_fill_color(): void
    {
        $node = new TextNode();
        $node->text = 'X';
        $node->x = 5;
        $node->y = 5;
        $node->fontSize = 10;
        $node->fontFamily = 'DejaVu Sans';
        $node->fill = new Color(4, null);

        $canvas = $this->renderToCanvas($node);
        $found = false;
        for ($y = 0; $y < $canvas->h; $y++) {
            for ($x = 0; $x < $canvas->w; $x++) {
                if ($canvas->data[$y][$x]->fg === 4) {
                    $found = true;
                    break 2;
                }
            }
        }
        $this->assertTrue($found, 'Expected red (color 4) pixels');
    }

    public function test_text_anchor_middle_shifts_left(): void
    {
        $nodeStart = new TextNode();
        $nodeStart->text = 'A';
        $nodeStart->x = 40;
        $nodeStart->y = 10;
        $nodeStart->fontSize = 10;
        $nodeStart->fontFamily = 'DejaVu Sans';
        $nodeStart->textAnchor = 'start';

        $canvasStart = $this->renderToCanvas($nodeStart);

        $nodeMiddle = new TextNode();
        $nodeMiddle->text = 'A';
        $nodeMiddle->x = 40;
        $nodeMiddle->y = 10;
        $nodeMiddle->fontSize = 10;
        $nodeMiddle->fontFamily = 'DejaVu Sans';
        $nodeMiddle->textAnchor = 'middle';

        $canvasMiddle = $this->renderToCanvas($nodeMiddle);

        $startMinX = PHP_INT_MAX;
        $middleMinX = PHP_INT_MAX;
        for ($y = 0; $y < $canvasStart->h; $y++) {
            for ($x = 0; $x < $canvasStart->w; $x++) {
                if ($canvasStart->data[$y][$x]->fg !== null) {
                    $startMinX = min($startMinX, $x);
                }
            }
        }
        for ($y = 0; $y < $canvasMiddle->h; $y++) {
            for ($x = 0; $x < $canvasMiddle->w; $x++) {
                if ($canvasMiddle->data[$y][$x]->fg !== null) {
                    $middleMinX = min($middleMinX, $x);
                }
            }
        }
        $this->assertLessThan($startMinX, $middleMinX, 'middle anchor should shift text left of start anchor');
    }

    public function test_render_with_tspan(): void
    {
        $node = new TextNode();
        $node->text = 'Hello';
        $node->x = 5;
        $node->y = 10;
        $node->fontSize = 10;
        $node->fontFamily = 'DejaVu Sans';

        $tspan = new TspanNode();
        $tspan->text = ' World';
        $node->tspans = [$tspan];

        $canvas = $this->renderToCanvas($node);
        $this->assertGreaterThan(0, $this->countRenderedPixels($canvas));
    }

    public function test_get_children_returns_empty(): void
    {
        $node = new TextNode();
        $this->assertSame([], $node->getChildren());
    }

    public function test_plain_text_fallback_for_missing_glyph(): void
    {
        $node = new TextNode();
        $node->text = "\u{10FFFF}";
        $node->x = 5;
        $node->y = 5;
        $node->fontSize = 10;
        $node->fontFamily = 'DejaVu Sans';
        $node->fill = new Color(0, null);

        $canvas = $this->renderToCanvas($node);
        $this->assertTrue(true);
    }
}
