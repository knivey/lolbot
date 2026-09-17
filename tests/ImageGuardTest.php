<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../library/ImageGuard.php';

class ImageGuardTest extends TestCase
{
    public function test_bomb_header_rejected(): void
    {
        $body = file_get_contents(__DIR__ . '/fixtures/bomb_header_60000x60000.png');
        assert(is_string($body));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('too large');
        \ImageGuard::guardBody($body);
    }

    public function test_animated_bomb_rejected(): void
    {
        $body = file_get_contents(__DIR__ . '/fixtures/animated_bomb_10f_1600x1600.gif');
        assert(is_string($body));
        $this->expectException(\InvalidArgumentException::class);
        \ImageGuard::guardBody($body);
    }

    public function test_normal_image_passes(): void
    {
        $body = file_get_contents(__DIR__ . '/fixtures/200x100_blue.png');
        assert(is_string($body));
        \ImageGuard::guardBody($body);
        $ping = \ImageGuard::ping($body);
        $this->assertFalse(\ImageGuard::oversize($ping));
        $ping->clear();
    }

    public function test_ping_reports_frame_aware_pixels(): void
    {
        $body = file_get_contents(__DIR__ . '/fixtures/animated_bomb_10f_1600x1600.gif');
        assert(is_string($body));
        $ping = \ImageGuard::ping($body);
        $this->assertTrue(\ImageGuard::oversize($ping));
        $ping->clear();
    }
}
