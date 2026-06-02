<?php

namespace Tests\Canvas;

use draw\IrcPalette;
use PHPUnit\Framework\TestCase;

class IrcPaletteTest extends TestCase
{
    public function test_getRgb_returns_correct_values_for_known_colors(): void
    {
        // Index 0: white
        $this->assertSame([255, 255, 255], IrcPalette::getRgb(0));
        // Index 1: black
        $this->assertSame([0, 0, 0], IrcPalette::getRgb(1));
        // Index 4: red
        $this->assertSame([255, 0, 0], IrcPalette::getRgb(4));
    }

    public function test_getRgb_returns_extended_colors(): void
    {
        // Index 16: #470000
        $this->assertSame([0x47, 0x00, 0x00], IrcPalette::getRgb(16));
        // Index 40: #b50000
        $this->assertSame([0xb5, 0x00, 0x00], IrcPalette::getRgb(40));
    }

    public function test_getRgb_returns_grayscale(): void
    {
        // Index 88: #000000 (black again)
        $this->assertSame([0, 0, 0], IrcPalette::getRgb(88));
        // Index 98: #ffffff (white again)
        $this->assertSame([255, 255, 255], IrcPalette::getRgb(98));
    }

    public function test_getRgb_throws_on_invalid_code(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IrcPalette::getRgb(99);
    }

    public function test_getRgb_throws_on_negative_code(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IrcPalette::getRgb(-1);
    }

    public function test_nearestColor_finds_exact_match(): void
    {
        // Pure red should match index 4 (255,0,0)
        $this->assertSame(4, IrcPalette::nearestColor(255, 0, 0));
    }

    public function test_nearestColor_finds_close_match(): void
    {
        // Slightly off-white should still find white (index 0)
        $this->assertSame(0, IrcPalette::nearestColor(254, 254, 254));
    }

    public function test_nearestColor_finds_black(): void
    {
        // Very dark should find black (index 1)
        $this->assertSame(1, IrcPalette::nearestColor(1, 1, 1));
    }

    public function test_nearestColor_finds_mid_gray(): void
    {
        // Index 14 is #7F7F7F (grey)
        $this->assertSame(14, IrcPalette::nearestColor(127, 127, 127));
    }

    public function test_getColor_throws_on_invalid_code(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IrcPalette::getColor(99);
    }

    public function test_getColor_returns_color_object(): void
    {
        $color = IrcPalette::getColor(4);
        $rgb = $color->getRgb();
        $this->assertSame(255, $rgb->R);
        $this->assertSame(0, $rgb->G);
        $this->assertSame(0, $rgb->B);
    }

    public function test_palette_has_99_entries(): void
    {
        // Verify we can access index 0 through 98 without error
        $lastRgb = IrcPalette::getRgb(98);
        $this->assertSame([255, 255, 255], $lastRgb);
    }
}
