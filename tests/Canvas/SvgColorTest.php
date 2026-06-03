<?php

namespace Tests\Canvas;

use draw\SvgColor;
use PHPUnit\Framework\TestCase;

class SvgColorTest extends TestCase
{
    public function test_named_color_red(): void
    {
        $result = SvgColor::parse('red');
        $this->assertSame([255, 0, 0], $result);
    }

    public function test_named_color_blue(): void
    {
        $result = SvgColor::parse('blue');
        $this->assertSame([0, 0, 255], $result);
    }

    public function test_named_color_cornflowerblue(): void
    {
        $result = SvgColor::parse('cornflowerblue');
        $this->assertSame([100, 149, 237], $result);
    }

    public function test_named_color_black(): void
    {
        $result = SvgColor::parse('black');
        $this->assertSame([0, 0, 0], $result);
    }

    public function test_named_color_white(): void
    {
        $result = SvgColor::parse('white');
        $this->assertSame([255, 255, 255], $result);
    }

    public function test_named_color_is_case_insensitive(): void
    {
        $result = SvgColor::parse('ReD');
        $this->assertSame([255, 0, 0], $result);
    }

    public function test_none_returns_null(): void
    {
        $result = SvgColor::parse('none');
        $this->assertNull($result);
    }

    public function test_transparent_returns_null(): void
    {
        $result = SvgColor::parse('transparent');
        $this->assertNull($result);
    }

    public function test_currentcolor_returns_null(): void
    {
        $result = SvgColor::parse('currentColor');
        $this->assertNull($result);
    }
}
