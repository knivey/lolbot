<?php

namespace Tests\Canvas;

use draw\FontManager;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

class FontManagerCacheCapTest extends TestCase
{
    protected function tearDown(): void
    {
        $prop = new \ReflectionProperty(FontManager::class, 'pathCache');
        $prop->setValue(null, []);
    }

    public function test_full_cache_returns_null_without_spawning_fc_match(): void
    {
        $prop = new \ReflectionProperty(FontManager::class, 'pathCache');
        $dummy = [];
        for ($i = 0; $i < 128; $i++) {
            $dummy["font$i||"] = '/nonexistent/font' . $i . '.ttf';
        }
        $prop->setValue(null, $dummy);

        $method = new \ReflectionMethod(FontManager::class, 'resolveFontPath');
        $result = $method->invoke(null, 'brand-new-bomb-font', null, null);

        $this->assertNull($result);
        $this->assertSame($dummy, $prop->getValue());
    }

    public function test_oversized_family_returns_null_and_stays_uncached(): void
    {
        $prop = new \ReflectionProperty(FontManager::class, 'pathCache');
        $prop->setValue(null, []);

        $method = new \ReflectionMethod(FontManager::class, 'resolveFontPath');
        $result = $method->invoke(null, str_repeat('a', 300), null, null);

        $this->assertNull($result);
        $this->assertSame([], $prop->getValue());
    }
}
