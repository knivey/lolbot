<?php

namespace Tests\Canvas;

use draw\FontFile;
use draw\FontManager;
use PHPUnit\Framework\TestCase;

class FontManagerTest extends TestCase
{
    public function test_resolve_returns_font_file_for_system_font(): void
    {
        $font = FontManager::resolve('DejaVu Sans', null, null);
        $this->assertInstanceOf(FontFile::class, $font);
        $this->assertGreaterThan(0, $font->unitsPerEm);
    }

    public function test_resolve_returns_default_for_unknown_family(): void
    {
        $font = FontManager::resolve('NonExistentFontXYZ123', null, null);
        $this->assertInstanceOf(FontFile::class, $font);
    }

    public function test_get_default_returns_font_file(): void
    {
        $font = FontManager::getDefault();
        $this->assertInstanceOf(FontFile::class, $font);
    }

    public function test_resolve_caches_results(): void
    {
        $font1 = FontManager::resolve('DejaVu Sans', null, null);
        $font2 = FontManager::resolve('DejaVu Sans', null, null);
        $this->assertSame($font1, $font2);
    }

    public function test_resolve_with_weight_bold(): void
    {
        $font = FontManager::resolve('DejaVu Sans', 'bold', null);
        $this->assertInstanceOf(FontFile::class, $font);
    }

    public function test_resolve_with_style_italic(): void
    {
        $font = FontManager::resolve('DejaVu Sans', null, 'italic');
        $this->assertInstanceOf(FontFile::class, $font);
    }
}
