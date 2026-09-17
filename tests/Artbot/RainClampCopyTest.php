<?php

namespace Tests\Artbot;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../artbot_scripts/rain.php';

class RainClampCopyTest extends TestCase
{
    public function test_aspect_bomb_copy_clamped(): void
    {
        [$w, $h] = rainClampCopy(60, 100000000, 200, 100);
        $this->assertLessThanOrEqual(400, $w);
        $this->assertLessThanOrEqual(200, $h);
    }

    public function test_normal_copy_unchanged(): void
    {
        [$w, $h] = rainClampCopy(60, 40, 200, 100);
        $this->assertSame(60, $w);
        $this->assertSame(40, $h);
    }
}
