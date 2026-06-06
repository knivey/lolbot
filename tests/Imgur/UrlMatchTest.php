<?php

namespace Tests\Imgur;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UrlMatchTest extends TestCase
{
    public static function galleryUrls(): array
    {
        return [
            'gallery with slug' => ['https://imgur.com/gallery/froggy-friday-AqeT58Y'],
            'gallery with id only' => ['https://imgur.com/gallery/AqeT58Y'],
            'gallery http' => ['http://imgur.com/gallery/slug-AqeT58Y'],
            'gallery www' => ['https://www.imgur.com/gallery/slug-AqeT58Y'],
        ];
    }

    public static function albumUrls(): array
    {
        return [
            'album' => ['https://imgur.com/a/FpuLRBp'],
            'album http' => ['http://imgur.com/a/FpuLRBp'],
            'album www' => ['https://www.imgur.com/a/FpuLRBp'],
        ];
    }

    public static function directUrls(): array
    {
        return [
            'jpeg' => ['https://i.imgur.com/vOFL64u.jpeg'],
            'jpg' => ['https://i.imgur.com/vOFL64u.jpg'],
            'png' => ['https://i.imgur.com/vOFL64u.png'],
            'gif' => ['https://i.imgur.com/vOFL64u.gif'],
            'gifv' => ['https://i.imgur.com/vOFL64u.gifv'],
            'mp4' => ['https://i.imgur.com/vOFL64u.mp4'],
            'http' => ['http://i.imgur.com/vOFL64u.jpeg'],
        ];
    }

    public static function singleImageUrls(): array
    {
        return [
            'single image' => ['https://imgur.com/vOFL64u'],
            'single image http' => ['http://imgur.com/vOFL64u'],
            'single image www' => ['https://www.imgur.com/vOFL64u'],
        ];
    }

    public static function nonImgurUrls(): array
    {
        return [
            'google' => ['https://google.com/foo'],
            'reddit' => ['https://reddit.com/r/test'],
            'imgur subpage' => ['https://imgur.com/tos'],
            'imgur settings' => ['https://imgur.com/settings'],
        ];
    }

    #[DataProvider('galleryUrls')]
    public function testGalleryMatch(string $url): void
    {
        $this->assertNotNull(\scripts\imgur\imgur::isGalleryUrl($url));
    }

    #[DataProvider('albumUrls')]
    public function testAlbumMatch(string $url): void
    {
        $this->assertNotNull(\scripts\imgur\imgur::isAlbumUrl($url));
    }

    #[DataProvider('directUrls')]
    public function testDirectMatch(string $url): void
    {
        $this->assertNotNull(\scripts\imgur\imgur::isDirectUrl($url));
    }

    #[DataProvider('singleImageUrls')]
    public function testSingleImageMatch(string $url): void
    {
        $this->assertNotNull(\scripts\imgur\imgur::isSingleImageUrl($url));
    }

    #[DataProvider('nonImgurUrls')]
    public function testNonImgurNoMatch(string $url): void
    {
        $this->assertNull(\scripts\imgur\imgur::isGalleryUrl($url));
        $this->assertNull(\scripts\imgur\imgur::isAlbumUrl($url));
        $this->assertNull(\scripts\imgur\imgur::isDirectUrl($url));
        $this->assertNull(\scripts\imgur\imgur::isSingleImageUrl($url));
    }
}
