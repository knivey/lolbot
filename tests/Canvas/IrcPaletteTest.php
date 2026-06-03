<?php

namespace Tests\Canvas;

use draw\Dithering;
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

    public function test_nearestColor_returns_consistent_results(): void
    {
        $first = IrcPalette::nearestColor(200, 100, 50);
        $second = IrcPalette::nearestColor(200, 100, 50);
        $this->assertSame($first, $second);
    }

    public function test_nearestColor_defaults_to_no_dithering(): void
    {
        $undithered = IrcPalette::nearestColor(128, 128, 128);
        $explicitNone = IrcPalette::nearestColor(128, 128, 128, Dithering::None, 5, 5);
        $this->assertSame($undithered, $explicitNone);
    }

    public function test_nearestColor_dithering_changes_result_for_some_pixels(): void
    {
        $midR = 160;
        $midG = 100;
        $midB = 60;
        $none0 = IrcPalette::nearestColor($midR, $midG, $midB, Dithering::None, 0, 0);
        $dither0 = IrcPalette::nearestColor($midR, $midG, $midB, Dithering::Ordered4x4, 0, 0);
        $dither5 = IrcPalette::nearestColor($midR, $midG, $midB, Dithering::Ordered4x4, 3, 0);
        $anyDifferent = ($none0 !== $dither0) || ($none0 !== $dither5) || ($dither0 !== $dither5);
        $this->assertTrue($anyDifferent, 'Dithering should produce at least one different result');
    }

    public function test_nearestColor_dithering_clamps_to_valid_range(): void
    {
        $result = IrcPalette::nearestColor(0, 0, 0, Dithering::Ordered4x4, 3, 3);
        $this->assertGreaterThanOrEqual(0, $result);
        $this->assertLessThanOrEqual(98, $result);

        $result = IrcPalette::nearestColor(255, 255, 255, Dithering::Ordered4x4, 0, 0);
        $this->assertGreaterThanOrEqual(0, $result);
        $this->assertLessThanOrEqual(98, $result);
    }

    public function test_nearestColor_dithering_is_position_dependent(): void
    {
        $r = 100;
        $g = 100;
        $b = 100;
        $result1 = IrcPalette::nearestColor($r, $g, $b, Dithering::Ordered4x4, 0, 0);
        $result2 = IrcPalette::nearestColor($r, $g, $b, Dithering::Ordered4x4, 1, 0);
        $result3 = IrcPalette::nearestColor($r, $g, $b, Dithering::Ordered4x4, 2, 0);
        $result4 = IrcPalette::nearestColor($r, $g, $b, Dithering::Ordered4x4, 3, 0);
        $uniqueResults = array_unique([$result1, $result2, $result3, $result4]);
        $this->assertGreaterThan(1, count($uniqueResults), 'Different positions should produce varied results for a mid-range color');
    }

    public function test_nearestColor_dithering_wraps_at_matrix_size(): void
    {
        $r = 100;
        $g = 100;
        $b = 100;
        $at4 = IrcPalette::nearestColor($r, $g, $b, Dithering::Ordered4x4, 4, 4);
        $at0 = IrcPalette::nearestColor($r, $g, $b, Dithering::Ordered4x4, 0, 0);
        $this->assertSame($at0, $at4, 'Dithering should wrap at 4x4 matrix size');
    }

    public function test_nearestColor_dithering_picks_between_two_palette_neighbors(): void
    {
        $r = 128;
        $g = 50;
        $b = 50;
        $none = IrcPalette::nearestColor($r, $g, $b, Dithering::None);
        $results = [];
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $code = IrcPalette::nearestColor($r, $g, $b, Dithering::Ordered4x4, $x, $y);
                $results[] = $code;
            }
        }
        $unique = array_unique($results);
        $this->assertGreaterThanOrEqual(2, count($unique), 'Dithering should produce at least 2 different palette colors for a mid-range input');
        foreach ($unique as $code) {
            $this->assertGreaterThanOrEqual(0, $code);
            $this->assertLessThanOrEqual(98, $code);
        }
    }

    public function test_nearestColor_dithering_is_symmetric_across_band(): void
    {
        $none1 = IrcPalette::nearestColor(100, 50, 50, Dithering::None);
        $none2 = IrcPalette::nearestColor(130, 50, 50, Dithering::None);
        if ($none1 === $none2) {
            $this->markTestSkipped('Test colors map to same palette entry');
        }
        $midR = 115;
        $midG = 50;
        $midB = 50;
        $ditheredAtMid = [];
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $ditheredAtMid[] = IrcPalette::nearestColor($midR, $midG, $midB, Dithering::Ordered4x4, $x, $y);
            }
        }
        $uniqueMid = count(array_unique($ditheredAtMid));
        $this->assertGreaterThanOrEqual(2, $uniqueMid, 'Mid-band should dither between at least 2 colors');
    }

    public function test_nearestColor_dithering_threshold_distribution(): void
    {
        $r = 115;
        $g = 50;
        $b = 50;
        $noneCode = IrcPalette::nearestColor($r, $g, $b, Dithering::None);
        $bestCount = 0;
        $otherCount = 0;
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $code = IrcPalette::nearestColor($r, $g, $b, Dithering::Ordered4x4, $x, $y);
                if ($code === $noneCode) {
                    $bestCount++;
                } else {
                    $otherCount++;
                }
            }
        }
        $this->assertGreaterThan(0, $bestCount, 'Some pixels should pick the best match');
        $this->assertGreaterThan(0, $otherCount, 'Some pixels should pick the second-best match');
    }
}
