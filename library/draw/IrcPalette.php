<?php

namespace draw;

use Itwmw\ColorDifference\Color;
use Itwmw\ColorDifference\Lib\RGB;

class IrcPalette
{
    /** @var array<int, string>|null */
    private static ?array $hexPalette = null;

    /** @var array<int, Color>|null */
    private static ?array $colorPalette = null;

    /** @var array<int, array{float, float, float}>|null */
    private static ?array $labPalette = null;

    /** @var array<int, array{int, int, int}>|null */
    private static ?array $rgbPalette = null;

    /** @var array<int, int> */
    private static array $nearestCache = [];

    /** @var array<string, int> */
    private static array $labCache = [];

    private const CACHE_LIMIT = 4096;

    private const DARK_L_THRESHOLD = 25.0;

    private const BAYER_4X4 = [
        [ 0,  8,  2, 10],
        [12,  4, 14,  6],
        [ 3, 11,  1,  9],
        [15,  7, 13,  5],
    ];

    /**
     * @return array<int, string>
     */
    private static function getHexPalette(): array
    {
        if (self::$hexPalette === null) {
            self::$hexPalette = [
                '#FFFFFF', '#000000', '#00007F', '#009300',
                '#FF0000', '#7F0000', '#9C009C', '#FC7F00',
                '#FFFF00', '#00FC00', '#009393', '#00FFFF',
                '#0000FC', '#FF00FF', '#7F7F7F', '#D2D2D2',
                '#470000', '#472100', '#474700', '#324700',
                '#004700', '#00472c', '#004747', '#002747',
                '#000047', '#2e0047', '#470047', '#47002a',
                '#740000', '#743a00', '#747400', '#517400',
                '#007400', '#007449', '#007474', '#004074',
                '#000074', '#4b0074', '#740074', '#740045',
                '#b50000', '#b56300', '#b5b500', '#7db500',
                '#00b500', '#00b571', '#00b5b5', '#0063b5',
                '#0000b5', '#7500b5', '#b500b5', '#b5006b',
                '#ff0000', '#ff8c00', '#ffff00', '#b2ff00',
                '#00ff00', '#00ffa0', '#00ffff', '#008cff',
                '#0000ff', '#a500ff', '#ff00ff', '#ff0098',
                '#ff5959', '#ffb459', '#ffff71', '#cfff60',
                '#6fff6f', '#65ffc9', '#6dffff', '#59b4ff',
                '#5959ff', '#c459ff', '#ff66ff', '#ff59bc',
                '#ff9c9c', '#ffd39c', '#ffff9c', '#e2ff9c',
                '#9cff9c', '#9cffdb', '#9cffff', '#9cd3ff',
                '#9c9cff', '#dc9cff', '#ff9cff', '#ff94d3',
                '#000000', '#131313', '#282828', '#363636',
                '#4d4d4d', '#656565', '#818181', '#9f9f9f',
                '#bcbcbc', '#e2e2e2', '#ffffff',
            ];
        }
        return self::$hexPalette;
    }

    /**
     * @return array{int, int, int}
     */
    public static function getRgb(int $ircCode): array
    {
        self::validateCode($ircCode);
        self::$rgbPalette ??= self::buildRgbPalette();
        return self::$rgbPalette[$ircCode];
    }

    public static function init(): void
    {
        self::$hexPalette ??= self::getHexPalette();
        self::$colorPalette ??= self::buildColorPalette();
        self::$rgbPalette ??= self::buildRgbPalette();
        self::$labPalette ??= self::buildLabPalette();
    }

    public static function getColor(int $ircCode): Color
    {
        self::validateCode($ircCode);
        self::$colorPalette ??= self::buildColorPalette();
        return self::$colorPalette[$ircCode];
    }

    /**
     * @return array{float, float, float}
     */
    public static function getLab(int $ircCode): array
    {
        self::validateCode($ircCode);
        return self::$labPalette[$ircCode];
    }

    private static function colorDistance(Color $target, Color $candidate, float $targetL): float
    {
        if ($targetL < self::DARK_L_THRESHOLD) {
            return $target->getDifferenceEuclideanRGB($candidate);
        }
        return $target->getDifferenceDin99($candidate);
    }

    public static function nearestColorFromLab(float $L, float $a, float $b, bool $limit16 = false): int
    {
        $qL = (int)round($L * 10);
        $qa = (int)round($a * 10);
        $qb = (int)round($b * 10);
        $key = "{$qL},{$qa},{$qb}" . ($limit16 ? ',16' : '');
        if (isset(self::$labCache[$key])) {
            return self::$labCache[$key];
        }

        self::$colorPalette ??= self::buildColorPalette();
        $target = new Color(new \Itwmw\ColorDifference\Lib\Lab($L, $a, $b));
        $bestIdx = 0;
        $bestDist = INF;
        $maxIdx = $limit16 ? 15 : PHP_INT_MAX;
        foreach (self::$colorPalette as $idx => $palColor) {
            if ($idx > $maxIdx) break;
            $d = self::colorDistance($target, $palColor, $L);
            if ($d < $bestDist) {
                $bestIdx = $idx;
                $bestDist = $d;
            }
        }
        self::$labCache[$key] = $bestIdx;
        if (count(self::$labCache) > 8192) {
            self::$labCache = [];
        }
        return $bestIdx;
    }

    public static function nearestColor(int $r, int $g, int $b, Dithering $mode = Dithering::None, int $x = 0, int $y = 0): int
    {
        if ($mode === Dithering::Ordered4x4) {
            return self::nearestColorDithered($r, $g, $b, $x, $y);
        }

        if ($mode === Dithering::ShaderBlocks || $mode === Dithering::ShaderBlocksAll) {
            return self::nearestColorShaderBlocksCode($r, $g, $b);
        }

        $key = ($r << 16) | ($g << 8) | $b;
        if (isset(self::$nearestCache[$key])) {
            return self::$nearestCache[$key];
        }

        self::$colorPalette ??= self::buildColorPalette();
        $target = new Color(new RGB($r, $g, $b));
        $targetL = $target->getLab()->L;
        $bestIdx = 0;
        $bestDist = INF;
        foreach (self::$colorPalette as $idx => $palColor) {
            $d = self::colorDistance($target, $palColor, $targetL);
            if ($d < $bestDist) {
                $bestIdx = $idx;
                $bestDist = $d;
            }
        }
        self::$nearestCache[$key] = $bestIdx;
        if (count(self::$nearestCache) > self::CACHE_LIMIT) {
            self::$nearestCache = [];
        }
        return $bestIdx;
    }

    public static function nearestColorWithMeta(int $r, int $g, int $b, Dithering $mode = Dithering::None, int $x = 0, int $y = 0): DitherResult
    {
        if ($mode === Dithering::Ordered4x4) {
            return new DitherResult(self::nearestColorDithered($r, $g, $b, $x, $y));
        }

        if ($mode === Dithering::ShaderBlocks || $mode === Dithering::ShaderBlocksAll) {
            return self::nearestColorShaderBlocksMeta($r, $g, $b);
        }

        $key = ($r << 16) | ($g << 8) | $b;
        if (isset(self::$nearestCache[$key])) {
            return new DitherResult(self::$nearestCache[$key]);
        }

        self::$colorPalette ??= self::buildColorPalette();
        $target = new Color(new RGB($r, $g, $b));
        $targetL = $target->getLab()->L;
        $bestIdx = 0;
        $bestDist = INF;
        foreach (self::$colorPalette as $idx => $palColor) {
            $d = self::colorDistance($target, $palColor, $targetL);
            if ($d < $bestDist) {
                $bestIdx = $idx;
                $bestDist = $d;
            }
        }
        self::$nearestCache[$key] = $bestIdx;
        if (count(self::$nearestCache) > self::CACHE_LIMIT) {
            self::$nearestCache = [];
        }
        return new DitherResult($bestIdx);
    }

    private static function nearestColorDithered(int $r, int $g, int $b, int $x, int $y): int
    {
        self::$colorPalette ??= self::buildColorPalette();
        $target = new Color(new RGB($r, $g, $b));
        $targetL = $target->getLab()->L;

        $bestIdx = 0;
        $bestDist = INF;
        $secondIdx = -1;
        $secondDist = INF;
        foreach (self::$colorPalette as $idx => $palColor) {
            $d = self::colorDistance($target, $palColor, $targetL);
            if ($d < $bestDist) {
                $secondIdx = $bestIdx;
                $secondDist = $bestDist;
                $bestIdx = $idx;
                $bestDist = $d;
            } elseif ($d < $secondDist) {
                $secondIdx = $idx;
                $secondDist = $d;
            }
        }

        if ($secondIdx === -1) {
            return $bestIdx;
        }

        if ($bestDist < 0.001) {
            return $bestIdx;
        }

        self::$rgbPalette ??= self::buildRgbPalette();
        $br = self::$rgbPalette[$bestIdx];

        $secondIdx = self::findDitherCandidate($target, $br, $bestIdx, $r, $g, $b, $secondIdx, $targetL);
        if ($secondIdx === -1) {
            return $bestIdx;
        }

        $sr = self::$rgbPalette[$secondIdx];
        $dr = $sr[0] - $br[0];
        $dg = $sr[1] - $br[1];
        $db = $sr[2] - $br[2];
        $lenSq = $dr * $dr + $dg * $dg + $db * $db;
        if ($lenSq < 0.001) {
            return $bestIdx;
        }
        $ir = $r - $br[0];
        $ig = $g - $br[1];
        $ib = $b - $br[2];
        $t = ($ir * $dr + $ig * $dg + $ib * $db) / $lenSq;
        $t = max(0.0, min(1.0, $t));

        $bayer = self::BAYER_4X4[$y & 3][$x & 3];
        $threshold = ($bayer + 0.5) / 16.0;

        if ($t >= $threshold) {
            return $secondIdx;
        }
        return $bestIdx;
    }

    private static function nearestColorShaderBlocksCode(int $r, int $g, int $b): int
    {
        $key = ($r << 16) | ($g << 8) | $b;
        if (isset(self::$nearestCache[$key])) {
            return self::$nearestCache[$key];
        }

        self::$colorPalette ??= self::buildColorPalette();
        $target = new Color(new RGB($r, $g, $b));
        $targetL = $target->getLab()->L;
        $bestIdx = 0;
        $bestDist = INF;
        foreach (self::$colorPalette as $idx => $palColor) {
            $d = self::colorDistance($target, $palColor, $targetL);
            if ($d < $bestDist) {
                $bestIdx = $idx;
                $bestDist = $d;
            }
        }
        self::$nearestCache[$key] = $bestIdx;
        if (count(self::$nearestCache) > self::CACHE_LIMIT) {
            self::$nearestCache = [];
        }
        return $bestIdx;
    }

    private static function nearestColorShaderBlocksMeta(int $r, int $g, int $b): DitherResult
    {
        self::$colorPalette ??= self::buildColorPalette();
        $target = new Color(new RGB($r, $g, $b));
        $targetL = $target->getLab()->L;

        $bestIdx = 0;
        $bestDist = INF;
        $secondIdx = -1;
        $secondDist = INF;
        foreach (self::$colorPalette as $idx => $palColor) {
            $d = self::colorDistance($target, $palColor, $targetL);
            if ($d < $bestDist) {
                $secondIdx = $bestIdx;
                $secondDist = $bestDist;
                $bestIdx = $idx;
                $bestDist = $d;
            } elseif ($d < $secondDist) {
                $secondIdx = $idx;
                $secondDist = $d;
            }
        }

        if ($secondIdx === -1) {
            return new DitherResult($bestIdx);
        }

        if ($bestDist < 0.001) {
            return new DitherResult($bestIdx);
        }

        self::$rgbPalette ??= self::buildRgbPalette();
        $br = self::$rgbPalette[$bestIdx];

        $secondIdx = self::findDitherCandidate($target, $br, $bestIdx, $r, $g, $b, $secondIdx, $targetL);
        if ($secondIdx === -1) {
            return new DitherResult($bestIdx);
        }

        $sr = self::$rgbPalette[$secondIdx];
        $dr = $sr[0] - $br[0];
        $dg = $sr[1] - $br[1];
        $db = $sr[2] - $br[2];
        $lenSq = $dr * $dr + $dg * $dg + $db * $db;
        if ($lenSq < 0.001) {
            return new DitherResult($bestIdx);
        }
        $ir = $r - $br[0];
        $ig = $g - $br[1];
        $ib = $b - $br[2];
        $t = ($ir * $dr + $ig * $dg + $ib * $db) / $lenSq;
        $t = max(0.0, min(1.0, $t));

        return new DitherResult($bestIdx, dithered: true, secondBest: $secondIdx, t: $t);
    }

    private static function findDitherCandidate(
        Color $target,
        array $br,
        int $bestIdx,
        int $r,
        int $g,
        int $b,
        int $initialSecondIdx,
        float $targetL,
    ): int {
        $secondIdx = $initialSecondIdx;
        $sr = self::$rgbPalette[$secondIdx];
        if ($br[0] === $sr[0] && $br[1] === $sr[1] && $br[2] === $sr[2]) {
            $secondIdx = -1;
        }

        if ($secondIdx !== -1) {
            $dr = $sr[0] - $br[0];
            $dg = $sr[1] - $br[1];
            $db = $sr[2] - $br[2];
            $lenSq = $dr * $dr + $dg * $dg + $db * $db;
            if ($lenSq > 0.001) {
                $ir = $r - $br[0];
                $ig = $g - $br[1];
                $ib = $b - $br[2];
                $t = ($ir * $dr + $ig * $dg + $ib * $db) / $lenSq;
                if ($t > 0) {
                    return $secondIdx;
                }
            }
        }

        $secondDist = INF;
        $secondIdx = -1;
        foreach (self::$colorPalette as $idx => $palColor) {
            $pr = self::$rgbPalette[$idx];
            if ($pr[0] === $br[0] && $pr[1] === $br[1] && $pr[2] === $br[2]) {
                continue;
            }
            $dr = $pr[0] - $br[0];
            $dg = $pr[1] - $br[1];
            $db = $pr[2] - $br[2];
            $lenSq = $dr * $dr + $dg * $dg + $db * $db;
            if ($lenSq < 0.001) {
                continue;
            }
            $ir = $r - $br[0];
            $ig = $g - $br[1];
            $ib = $b - $br[2];
            $t = ($ir * $dr + $ig * $dg + $ib * $db) / $lenSq;
            if ($t <= 0) {
                continue;
            }
            $d = self::colorDistance($target, $palColor, $targetL);
            if ($d < $secondDist) {
                $secondDist = $d;
                $secondIdx = $idx;
            }
        }

        return $secondIdx;
    }

    private static function validateCode(int $ircCode): void
    {
        if ($ircCode < 0 || $ircCode > 98) {
            throw new \InvalidArgumentException("IRC color code must be 0-98, got $ircCode");
        }
    }

    /**
     * @return array<int, array{int, int, int}>
     */
    private static function buildRgbPalette(): array
    {
        $palette = [];
        foreach (self::getHexPalette() as $idx => $hex) {
            $r = hexdec(substr($hex, 1, 2));
            $g = hexdec(substr($hex, 3, 2));
            $b = hexdec(substr($hex, 5, 2));
            $palette[$idx] = [(int) $r, (int) $g, (int) $b];
        }
        return $palette;
    }

    /**
     * @return array<int, Color>
     */
    private static function buildColorPalette(): array
    {
        $palette = [];
        foreach (self::getHexPalette() as $idx => $hex) {
            $palette[$idx] = new Color($hex);
        }
        return $palette;
    }

    /**
     * @return array<int, array{float, float, float}>
     */
    private static function buildLabPalette(): array
    {
        self::$colorPalette ??= self::buildColorPalette();
        $palette = [];
        foreach (self::$colorPalette as $idx => $color) {
            $lab = $color->getLab();
            $palette[$idx] = [$lab->L, $lab->a, $lab->b];
        }
        return $palette;
    }
}

IrcPalette::init();
