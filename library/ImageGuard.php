<?php

/**
 * Guards against image decompression bombs before Imagick decodes them.
 * Mirrors the linktitles ai vision guard: pingImageBlob reads headers only,
 * total cost scales with pixels * frames.
 */
class ImageGuard
{
    public const maxPixels = 25000000;

    public static function ping(string $body): \Imagick
    {
        $ping = new \Imagick();
        $ping->pingImageBlob($body);
        return $ping;
    }

    public static function oversize(\Imagick $ping): bool
    {
        $w = $ping->getImageWidth();
        $h = $ping->getImageHeight();
        $frames = max(1, $ping->getNumberImages());
        return $w * $h * $frames > self::maxPixels;
    }

    /**
     * @throws \InvalidArgumentException when the image exceeds maxPixels
     */
    public static function guardBody(string $body, string $what = 'image'): void
    {
        $ping = self::ping($body);
        try {
            if (self::oversize($ping)) {
                $w = $ping->getImageWidth();
                $h = $ping->getImageHeight();
                throw new \InvalidArgumentException("{$what} too large {$w}x{$h} (max " . self::maxPixels . " pixels)");
            }
        } finally {
            $ping->clear();
        }
    }
}
