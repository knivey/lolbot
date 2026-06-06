<?php

namespace Tests\Imgur;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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

    public function testExtractsSingleIdFromEmbed(): void
    {
        $html = '<img src="https://i.imgur.com/vOFL64us.jpg">';
        $result = \scripts\imgur\imgur::extractImageIdsFromEmbed($html);
        $this->assertNotNull($result);
        $this->assertCount(1, $result);
        $this->assertSame('vOFL64us', $result[0]);
    }

    public function testExtractsMultipleIdsFromEmbed(): void
    {
        $html = '<img src="https://i.imgur.com/abc123s.jpg"><img src="https://i.imgur.com/def456s.jpg">';
        $result = \scripts\imgur\imgur::extractImageIdsFromEmbed($html);
        $this->assertNotNull($result);
        $this->assertCount(2, $result);
        $this->assertSame('abc123s', $result[0]);
        $this->assertSame('def456s', $result[1]);
    }

    public function testDeduplicatesIdsFromEmbed(): void
    {
        $html = '<img src="https://i.imgur.com/abc123s.jpg"><img src="https://i.imgur.com/abc123s.jpg">';
        $result = \scripts\imgur\imgur::extractImageIdsFromEmbed($html);
        $this->assertNotNull($result);
        $this->assertCount(1, $result);
    }

    public function testReturnsNullWhenNoImagesInEmbed(): void
    {
        $html = '<html><body>nothing here</body></html>';
        $result = \scripts\imgur\imgur::extractImageIdsFromEmbed($html);
        $this->assertNull($result);
    }
}
