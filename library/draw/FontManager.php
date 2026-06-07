<?php

namespace draw;

class FontManager
{
    private static ?FontFile $defaultFont = null;

    /** @var array<string, FontFile> */
    private static array $fontCache = [];

    /** @var array<string, string|null> */
    private static array $pathCache = [];

    public static function resolve(string $fontFamily, ?string $weight, ?string $style): FontFile
    {
        $cacheKey = $fontFamily . '|' . ($weight ?? '') . '|' . ($style ?? '');

        if (isset(self::$fontCache[$cacheKey])) {
            return self::$fontCache[$cacheKey];
        }

        $path = self::resolveFontPath($fontFamily, $weight, $style);

        if ($path === null) {
            return self::getDefault();
        }

        return self::$fontCache[$cacheKey] = self::loadFontFile($path);
    }

    public static function getDefault(): FontFile
    {
        if (self::$defaultFont !== null) {
            return self::$defaultFont;
        }

        $path = self::resolveFontPath('sans-serif', null, null);
        if ($path === null) {
            throw new \RuntimeException('No default font available. Install fontconfig and a system font.');
        }

        return self::$defaultFont = self::loadFontFile($path);
    }

    private static function resolveFontPath(string $fontFamily, ?string $weight, ?string $style): ?string
    {
        $cacheKey = $fontFamily . '|' . ($weight ?? '') . '|' . ($style ?? '');

        if (array_key_exists($cacheKey, self::$pathCache)) {
            return self::$pathCache[$cacheKey];
        }

        $pattern = $fontFamily;
        if ($weight !== null) {
            $pattern .= ':weight=' . $weight;
        }
        if ($style !== null) {
            $pattern .= ':style=' . $style;
        }

        $output = shell_exec('fc-match ' . escapeshellarg($pattern) . ' --format="%{file}" 2>/dev/null');
        $path = ($output !== null && $output !== '') ? $output : null;

        self::$pathCache[$cacheKey] = $path;
        return $path;
    }

    private static function loadFontFile(string $path): FontFile
    {
        return FontFile::load($path);
    }
}
