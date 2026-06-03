<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\Color;
use draw\Compositor;
use draw\Path;
use draw\StrokeStyle;
use PHPUnit\Framework\TestCase;

class CompositorTest extends TestCase
{
    public function test_blend_throws_on_size_mismatch(): void
    {
        $dst = Canvas::createBlank(10, 10);
        $src = Canvas::createBlank(5, 5);
        $this->expectException(\InvalidArgumentException::class);
        Compositor::blend($dst, $src);
    }

    public function test_blend_full_opacity_same_as_overlay(): void
    {
        $dst = Canvas::createBlank(10, 10);
        $src = Canvas::createBlank(10, 10);

        $src->drawPoint(5, 5, new Color(4, null));

        Compositor::blend($dst, $src, 1.0);

        $this->assertSame(4, $dst->data[5][5]->fg);
    }

    public function test_blend_skips_empty_source_pixels(): void
    {
        $dst = Canvas::createBlank(10, 10);
        $dst->drawPoint(5, 5, new Color(4, null));

        $src = Canvas::createBlank(10, 10);

        Compositor::blend($dst, $src, 0.5);

        $this->assertSame(4, $dst->data[5][5]->fg);
    }

    public function test_blend_copies_to_empty_destination(): void
    {
        $dst = Canvas::createBlank(10, 10);
        $src = Canvas::createBlank(10, 10);

        $src->drawPoint(5, 5, new Color(4, null));

        Compositor::blend($dst, $src, 0.5);

        $this->assertSame(4, $dst->data[5][5]->fg);
    }

    public function test_blend_half_opacity_blends_colors(): void
    {
        $dst = Canvas::createBlank(10, 10);
        $src = Canvas::createBlank(10, 10);

        // dst: white (index 0 = #FFFFFF), src: black (index 1 = #000000)
        $dst->drawPoint(5, 5, new Color(0, null));
        $src->drawPoint(5, 5, new Color(1, null));

        Compositor::blend($dst, $src, 0.5);

        // Result should be ~#7F7F7F which is grey (index 14 = #7F7F7F)
        $result = $dst->data[5][5]->fg;
        $this->assertNotNull($result);
        $rgb = \draw\IrcPalette::getRgb($result);
        // Allow some tolerance since quantization may not land exactly
        $this->assertGreaterThan(100, $rgb[0], "R should be mid-range");
        $this->assertLessThan(170, $rgb[0], "R should be mid-range");
    }

    public function test_blend_zero_opacity_leaves_destination_unchanged(): void
    {
        $dst = Canvas::createBlank(10, 10);
        $src = Canvas::createBlank(10, 10);

        $dst->drawPoint(5, 5, new Color(4, null));
        $src->drawPoint(5, 5, new Color(1, null));

        Compositor::blend($dst, $src, 0.0);

        $this->assertSame(4, $dst->data[5][5]->fg);
    }

    public function test_blend_full_opacity_overwrites_destination(): void
    {
        $dst = Canvas::createBlank(10, 10);
        $src = Canvas::createBlank(10, 10);

        $dst->drawPoint(5, 5, new Color(4, null));
        $src->drawPoint(5, 5, new Color(0, null));

        Compositor::blend($dst, $src, 1.0);

        $this->assertSame(0, $dst->data[5][5]->fg);
    }

    public function test_blend_with_rendered_path(): void
    {
        $main = Canvas::createBlank(20, 10);
        $temp = Canvas::createBlank(20, 10);

        $fill = new Color(0, null);
        $main->drawPath(
            Path::rect(0, 0, 20, 10),
            $fill,
            null
        );

        $stroke = new StrokeStyle(new Color(4, null), width: 3.0);
        $temp->drawPath(Path::line(2, 5, 17, 5), null, $stroke);

        Compositor::blend($main, $temp, 0.5);

        $strokePixel = $main->data[5][5];
        $this->assertNotNull($strokePixel->fg);
        $this->assertNotSame(4, $strokePixel->fg, "Should not be pure red after 50% blend");
        $this->assertNotSame(0, $strokePixel->fg, "Should not be pure white after 50% blend");
    }

    public function test_blend_resets_dst_alpha_to_one(): void
    {
        $dst = Canvas::createBlank(10, 10);
        $src = Canvas::createBlank(10, 10);

        $src->drawPoint(5, 5, new Color(4, null));

        Compositor::blend($dst, $src, 0.5);

        $this->assertSame(1.0, $dst->data[5][5]->fgAlpha);
    }

    public function test_blend_handles_bg_independently(): void
    {
        $dst = Canvas::createBlank(10, 10);
        $src = Canvas::createBlank(10, 10);

        $dst->drawPoint(5, 5, new Color(null, 0));
        $src->drawPoint(5, 5, new Color(4, 1));

        Compositor::blend($dst, $src, 1.0);

        $this->assertSame(4, $dst->data[5][5]->fg);
        $this->assertSame(1, $dst->data[5][5]->bg);
    }

    public function test_blend_copies_text_from_source(): void
    {
        $dst = Canvas::createBlank(10, 10);
        $src = Canvas::createBlank(10, 10);

        $p = new \draw\Pixel();
        $p->fg = 4;
        $p->text = '█';
        $src->data[5][5] = $p;

        Compositor::blend($dst, $src, 1.0);

        $this->assertSame('█', $dst->data[5][5]->text);
    }

    public function test_blend_does_not_overwrite_text_with_space(): void
    {
        $dst = Canvas::createBlank(10, 10);
        $src = Canvas::createBlank(10, 10);

        $dp = new \draw\Pixel();
        $dp->fg = 0;
        $dp->text = '█';
        $dst->data[5][5] = $dp;

        $sp = new \draw\Pixel();
        $sp->fg = 4;
        $sp->text = ' ';
        $src->data[5][5] = $sp;

        Compositor::blend($dst, $src, 1.0);

        $this->assertSame('█', $dst->data[5][5]->text);
    }

    public function test_blend_multiple_pixels(): void
    {
        $dst = Canvas::createBlank(10, 10);
        $src = Canvas::createBlank(10, 10);

        $src->drawPath(
            Path::rect(2, 2, 5, 5),
            new Color(4, null),
            null
        );

        $dst->drawPath(
            Path::rect(0, 0, 10, 10),
            new Color(0, null),
            null
        );

        Compositor::blend($dst, $src, 0.5);

        $innerPixel = $dst->data[4][4];
        $this->assertNotSame(4, $innerPixel->fg);
        $this->assertNotSame(0, $innerPixel->fg);

        $outerPixel = $dst->data[0][0];
        $this->assertSame(0, $outerPixel->fg);
    }
}
