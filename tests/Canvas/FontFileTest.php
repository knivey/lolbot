<?php

namespace Tests\Canvas;

use draw\FontFile;
use draw\Path;
use PHPUnit\Framework\TestCase;

class FontFileTest extends TestCase
{
    private static FontFile $font;

    public static function setUpBeforeClass(): void
    {
        self::$font = FontFile::load(__DIR__ . '/../fixtures/DejaVuSans.ttf');
    }

    public function test_load_returns_font_file(): void
    {
        $this->assertInstanceOf(FontFile::class, self::$font);
    }

    public function test_units_per_em(): void
    {
        $this->assertSame(2048.0, self::$font->unitsPerEm);
    }

    public function test_ascent_is_positive(): void
    {
        $this->assertGreaterThan(0, self::$font->ascent);
    }

    public function test_descent_is_negative(): void
    {
        $this->assertLessThan(0, self::$font->descent);
    }

    public function test_get_glyph_path_returns_path_for_ascii(): void
    {
        $path = self::$font->getGlyphPath('A');
        $this->assertInstanceOf(Path::class, $path);
    }

    public function test_get_glyph_path_returns_null_for_missing(): void
    {
        $path = self::$font->getGlyphPath("\u{10FFFF}");
        $this->assertNull($path);
    }

    public function test_get_advance_width_returns_positive(): void
    {
        $width = self::$font->getAdvanceWidth('A');
        $this->assertGreaterThan(0, $width);
    }

    public function test_get_advance_width_space(): void
    {
        $width = self::$font->getAdvanceWidth(' ');
        $this->assertGreaterThan(0, $width);
    }

    public function test_get_kerning_returns_float(): void
    {
        $kern = self::$font->getKerning('A', 'x');
        $this->assertIsFloat($kern);
    }

    public function test_glyph_path_is_cached(): void
    {
        $path1 = self::$font->getGlyphPath('A');
        $path2 = self::$font->getGlyphPath('A');
        $this->assertSame($path1, $path2);
    }

    public function test_composite_glyph_accented_char(): void
    {
        $path = self::$font->getGlyphPath("\u{00E9}");
        $this->assertInstanceOf(Path::class, $path);
    }
}
