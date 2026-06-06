<?php

namespace Tests\Canvas;

use draw\FilterRegion;
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
        $this->assertSame(24.0, $absolute['width']);
        $this->assertSame(12.0, $absolute['height']);
    }

    public function test_filter_region_to_absolute_identity(): void
    {
        $region = new FilterRegion(0.0, 0.0, 1.0, 1.0);
        $absolute = $region->toAbsolute(10.0, 5.0, 20.0, 10.0);
        $this->assertSame(10.0, $absolute['x']);
        $this->assertSame(5.0, $absolute['y']);
        $this->assertSame(20.0, $absolute['width']);
        $this->assertSame(10.0, $absolute['height']);
    }
}
