<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\Color;
use draw\FilterPipeline;
use draw\FilterRegion;
use draw\IrcPalette;
use draw\OffsetPrimitive;
use PHPUnit\Framework\TestCase;

class FilterPrimitiveTest extends TestCase
{
    public function test_filter_region_defaults(): void
    {
        $region = FilterRegion::defaults();
        $this->assertSame(-0.1, $region->x);
        $this->assertSame(-0.1, $region->y);
        $this->assertSame(1.2, $region->width);
        $this->assertSame(1.2, $region->height);
    }

    public function test_filter_region_custom_values(): void
    {
        $region = new FilterRegion(0.0, 0.0, 1.0, 1.0);
        $this->assertSame(0.0, $region->x);
        $this->assertSame(0.0, $region->y);
        $this->assertSame(1.0, $region->width);
        $this->assertSame(1.0, $region->height);
    }

    public function test_filter_region_to_absolute_with_bbox(): void
    {
        $region = new FilterRegion(-0.1, -0.1, 1.2, 1.2);
        $absolute = $region->toAbsolute(10.0, 5.0, 20.0, 10.0);
        $this->assertSame(8.0, $absolute['x']);
        $this->assertSame(4.0, $absolute['y']);
        $this->assertSame(24.0, $absolute['w']);
        $this->assertSame(12.0, $absolute['h']);
    }

    public function test_filter_region_to_absolute_identity(): void
    {
        $region = new FilterRegion(0.0, 0.0, 1.0, 1.0);
        $absolute = $region->toAbsolute(10.0, 5.0, 20.0, 10.0);
        $this->assertSame(10.0, $absolute['x']);
        $this->assertSame(5.0, $absolute['y']);
        $this->assertSame(20.0, $absolute['w']);
        $this->assertSame(10.0, $absolute['h']);
    }

    public function test_pipeline_stores_named_result(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(5, 5, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $result = Canvas::createBlank(10, 10);
        $result->drawPoint(3, 3, new Color(0, null));
        $pipeline->setResult('blur1', $result);

        $this->assertSame($result, $pipeline->getResult('blur1'));
    }

    public function test_pipeline_provides_source_graphic(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(5, 5, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $sg = $pipeline->getResult('SourceGraphic');

        $this->assertSame(4, $sg->data[5][5]->fg);
        $this->assertNull($sg->data[0][0]->fg);
    }

    public function test_pipeline_provides_source_alpha(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(5, 5, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $sa = $pipeline->getResult('SourceAlpha');

        $pixel = $sa->data[5][5];
        $this->assertNotNull($pixel->fg);
        $rgb = IrcPalette::getRgb($pixel->fg);
        $this->assertSame(255, $rgb[0]);
        $this->assertSame(255, $rgb[1]);
        $this->assertSame(255, $rgb[2]);
    }

    public function test_pipeline_source_alpha_empty_where_source_empty(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(5, 5, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $sa = $pipeline->getResult('SourceAlpha');

        $this->assertNull($sa->data[0][0]->fg);
    }

    public function test_pipeline_background_image_is_empty(): void
    {
        $source = Canvas::createBlank(10, 10);
        $pipeline = new FilterPipeline($source);
        $bg = $pipeline->getResult('BackgroundImage');

        $this->assertNull($bg->data[0][0]->fg);
    }

    public function test_pipeline_background_alpha_is_empty(): void
    {
        $source = Canvas::createBlank(10, 10);
        $pipeline = new FilterPipeline($source);
        $ba = $pipeline->getResult('BackgroundAlpha');

        $this->assertNull($ba->data[0][0]->fg);
    }

    public function test_pipeline_returns_null_for_unknown_result(): void
    {
        $source = Canvas::createBlank(10, 10);
        $pipeline = new FilterPipeline($source);

        $this->assertNull($pipeline->getResult('nonexistent'));
    }

    public function test_pipeline_last_result_tracks_output(): void
    {
        $source = Canvas::createBlank(10, 10);
        $pipeline = new FilterPipeline($source);

        $this->assertSame($source, $pipeline->getLastResult());

        $result = Canvas::createBlank(10, 10);
        $result->drawPoint(3, 3, new Color(0, null));
        $pipeline->setResult('step1', $result);

        $this->assertSame($result, $pipeline->getLastResult());
    }

    public function test_offset_shifts_pixels_right_and_down(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(2, 2, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $primitive = new OffsetPrimitive(3.0, 2.0);
        $result = $primitive->apply($source, $pipeline);

        $this->assertNull($result->data[2][2]->fg);
        $this->assertSame(4, $result->data[4][5]->fg);
    }

    public function test_offset_negative_values(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(7, 7, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $primitive = new OffsetPrimitive(-3.0, -2.0);
        $result = $primitive->apply($source, $pipeline);

        $this->assertNull($result->data[7][7]->fg);
        $this->assertSame(4, $result->data[5][4]->fg);
    }

    public function test_offset_out_of_bounds_pixels_lost(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(1, 1, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $primitive = new OffsetPrimitive(-5.0, -5.0);
        $result = $primitive->apply($source, $pipeline);

        $this->assertNull($result->data[0][0]->fg);
    }

    public function test_offset_with_named_input(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(2, 2, new Color(4, null));

        $other = Canvas::createBlank(10, 10);
        $other->drawPoint(5, 5, new Color(0, null));

        $pipeline = new FilterPipeline($source);
        $pipeline->setResult('other', $other);

        $primitive = new OffsetPrimitive(1.0, 0.0, input: 'other');
        $result = $primitive->apply($other, $pipeline);

        $this->assertNull($result->data[5][5]->fg);
        $this->assertSame(0, $result->data[5][6]->fg);
    }

    public function test_offset_stores_named_result(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(2, 2, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $primitive = new OffsetPrimitive(1.0, 0.0, result: 'shifted');
        $result = $primitive->apply($source, $pipeline);

        $this->assertSame($result, $pipeline->getResult('shifted'));
    }

    public function test_offset_zero_produces_copy(): void
    {
        $source = Canvas::createBlank(10, 10);
        $source->drawPoint(5, 5, new Color(4, null));

        $pipeline = new FilterPipeline($source);
        $primitive = new OffsetPrimitive(0.0, 0.0);
        $result = $primitive->apply($source, $pipeline);

        $this->assertSame(4, $result->data[5][5]->fg);
        $this->assertNotSame($source, $result);
    }
}
