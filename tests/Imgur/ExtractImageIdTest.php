<?php

namespace Tests\Imgur;

use PHPUnit\Framework\TestCase;

class ExtractImageIdTest extends TestCase
{
    public function testExtractsFromOgImage(): void
    {
        $html = '<meta property="og:image" content="https://i.imgur.com/vOFL64u.jpeg?fb">';
        $result = \scripts\imgur\imgur::extractImageIdFromHtml($html);
        $this->assertSame('vOFL64u', $result);
    }

    public function testExtractsFromOgImageWithoutFb(): void
    {
        $html = '<meta property="og:image" content="https://i.imgur.com/abc123.png">';
        $result = \scripts\imgur\imgur::extractImageIdFromHtml($html);
        $this->assertSame('abc123', $result);
    }

    public function testReturnsNullWhenNotFound(): void
    {
        $html = '<html><body>no image here</body></html>';
        $result = \scripts\imgur\imgur::extractImageIdFromHtml($html);
        $this->assertNull($result);
    }
}
