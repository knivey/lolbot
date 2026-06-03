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

    public function test_hex_3_digit(): void
    {
        $result = SvgColor::parse('#f00');
        $this->assertSame([255, 0, 0], $result);
    }

    public function test_hex_6_digit(): void
    {
        $result = SvgColor::parse('#ff0000');
        $this->assertSame([255, 0, 0], $result);
    }

    public function test_hex_8_digit_ignores_alpha(): void
    {
        $result = SvgColor::parse('#ff000080');
        $this->assertSame([255, 0, 0], $result);
    }

    public function test_hex_uppercase(): void
    {
        $result = SvgColor::parse('#FF0000');
        $this->assertSame([255, 0, 0], $result);
    }

    public function test_hex_invalid_length_returns_null(): void
    {
        $result = SvgColor::parse('#ff');
        $this->assertNull($result);
    }

    public function test_rgb_functional(): void
    {
        $result = SvgColor::parse('rgb(255, 128, 0)');
        $this->assertSame([255, 128, 0], $result);
    }

    public function test_rgba_functional_ignores_alpha(): void
    {
        $result = SvgColor::parse('rgba(255, 128, 0, 0.5)');
        $this->assertSame([255, 128, 0], $result);
    }

    public function test_rgb_percent(): void
    {
        $result = SvgColor::parse('rgb(100%, 0%, 50%)');
        $this->assertSame([255, 0, 128], $result);
    }

    public function test_rgb_clamps_to_255(): void
    {
        $result = SvgColor::parse('rgb(300, -10, 128)');
        $this->assertSame([255, 0, 128], $result);
    }

    public function test_unknown_string_returns_null(): void
    {
        $result = SvgColor::parse('notacolor');
        $this->assertNull($result);
    }
}
