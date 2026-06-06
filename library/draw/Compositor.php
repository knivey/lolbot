<?php

namespace draw;

class Compositor
{
    private static function clearPixelMeta(Pixel $dst): void
    {
        $dst->dithered = false;
        $dst->secondBest = -1;
        $dst->t = 0.0;
    }

    private static function copyPixelMeta(Pixel $dst, Pixel $src, string $channel): void
    {
        if ($channel === 'fg') {
            $dst->dithered = $src->dithered;
            $dst->secondBest = $src->secondBest;
            $dst->t = $src->t;
        }
    }

    public static function blend(Canvas $dst, Canvas $src, float $opacity = 1.0): void
    {
        if ($src->w !== $dst->w || $src->h !== $dst->h) {
            throw new \InvalidArgumentException(
                "Canvas size mismatch: {$dst->w}x{$dst->h} vs {$src->w}x{$src->h}"
            );
        }

        self::blendRegion($dst, $src, $opacity, 0, 0);
    }

    public static function blendRegion(Canvas $dst, Canvas $src, float $opacity, int $dstX, int $dstY): void
    {
        $opacity = max(0.0, min(1.0, $opacity));
        $dithering = $dst->getDithering();

        for ($y = 0; $y < $src->h; $y++) {
            $dy = $dstY + $y;
            if ($dy < 0 || $dy >= $dst->h) {
                continue;
            }
            for ($x = 0; $x < $src->w; $x++) {
                $dx = $dstX + $x;
                if ($dx < 0 || $dx >= $dst->w) {
                    continue;
                }

                $sp = $src->data[$y][$x];
                if ($sp->fg === null && $sp->bg === null) {
                    continue;
                }

                $dp = $dst->data[$dy][$dx];
                $hasChange = false;

                if ($sp->fg !== null) {
                    $effectiveAlpha = $sp->fgAlpha * $opacity;
                    if ($effectiveAlpha < 0.001) {
                        // skip
                    } elseif ($dp->fg === null || $effectiveAlpha >= 0.999) {
                        $dp->fg = $sp->fg;
                        $dp->fgAlpha = 1.0;
                        self::copyPixelMeta($dp, $sp, 'fg');
                        $hasChange = true;
                    } else {
                        $srcRgb = IrcPalette::getRgb($sp->fg);
                        $dstRgb = IrcPalette::getRgb($dp->fg);
                        $r = (int) round($srcRgb[0] * $effectiveAlpha + $dstRgb[0] * (1.0 - $effectiveAlpha));
                        $g = (int) round($srcRgb[1] * $effectiveAlpha + $dstRgb[1] * (1.0 - $effectiveAlpha));
                        $b = (int) round($srcRgb[2] * $effectiveAlpha + $dstRgb[2] * (1.0 - $effectiveAlpha));
                        $r = max(0, min(255, $r));
                        $g = max(0, min(255, $g));
                        $b = max(0, min(255, $b));
                        if ($dithering === Dithering::ShaderBlocks || $dithering === Dithering::ShaderBlocksAll) {
                            $result = IrcPalette::nearestColorWithMeta($r, $g, $b, $dithering, $dx, $dy);
                            $dp->fg = $result->code;
                            $dp->fgAlpha = 1.0;
                            $dp->dithered = $result->dithered;
                            $dp->secondBest = $result->secondBest;
                            $dp->t = $result->t;
                        } else {
                            $dp->fg = IrcPalette::nearestColor($r, $g, $b, $dithering, $dx, $dy);
                            $dp->fgAlpha = 1.0;
                            self::clearPixelMeta($dp);
                        }
                        $hasChange = true;
                    }
                }

                if ($sp->bg !== null) {
                    $effectiveAlpha = $sp->bgAlpha * $opacity;
                    if ($effectiveAlpha < 0.001) {
                        // skip
                    } elseif ($dp->bg === null) {
                        $dp->bg = $sp->bg;
                        $dp->bgAlpha = 1.0;
                        $hasChange = true;
                    } else {
                        $srcRgb = IrcPalette::getRgb($sp->bg);
                        $dstRgb = IrcPalette::getRgb($dp->bg);
                        $r = (int) round($srcRgb[0] * $effectiveAlpha + $dstRgb[0] * (1.0 - $effectiveAlpha));
                        $g = (int) round($srcRgb[1] * $effectiveAlpha + $dstRgb[1] * (1.0 - $effectiveAlpha));
                        $b = (int) round($srcRgb[2] * $effectiveAlpha + $dstRgb[2] * (1.0 - $effectiveAlpha));
                        $r = max(0, min(255, $r));
                        $g = max(0, min(255, $g));
                        $b = max(0, min(255, $b));
                        $dp->bg = IrcPalette::nearestColor($r, $g, $b, $dithering, $dx, $dy);
                        $dp->bgAlpha = 1.0;
                        $hasChange = true;
                    }
                }

                if ($hasChange && $sp->text !== ' ') {
                    $dp->text = $sp->text;
                }
            }
        }
    }

    public static function applyClip(Canvas $dst, Canvas $src, Canvas $clip, float $opacity = 1.0): void
    {
        if ($src->w !== $dst->w || $src->h !== $dst->h || $clip->w !== $dst->w || $clip->h !== $dst->h) {
            throw new \InvalidArgumentException(
                "Canvas size mismatch: {$dst->w}x{$dst->h} vs {$src->w}x{$src->h} vs {$clip->w}x{$clip->h}"
            );
        }

        $opacity = max(0.0, min(1.0, $opacity));

        for ($y = 0; $y < $dst->h; $y++) {
            for ($x = 0; $x < $dst->w; $x++) {
                $cp = $clip->data[$y][$x];
                if ($cp->fg === null && $cp->bg === null) {
                    continue;
                }

                $sp = $src->data[$y][$x];
                $dp = $dst->data[$y][$x];
                $hasChange = false;

                if ($sp->fg !== null) {
                    $effectiveAlpha = $sp->fgAlpha * $opacity;
                    if ($effectiveAlpha < 0.001) {
                        // skip
                    } elseif ($dp->fg === null || $effectiveAlpha >= 0.999) {
                        $dp->fg = $sp->fg;
                        $dp->fgAlpha = 1.0;
                        self::copyPixelMeta($dp, $sp, 'fg');
                        $hasChange = true;
                    } else {
                        $srcRgb = IrcPalette::getRgb($sp->fg);
                        $dstRgb = IrcPalette::getRgb($dp->fg);
                        $r = (int) round($srcRgb[0] * $effectiveAlpha + $dstRgb[0] * (1.0 - $effectiveAlpha));
                        $g = (int) round($srcRgb[1] * $effectiveAlpha + $dstRgb[1] * (1.0 - $effectiveAlpha));
                        $b = (int) round($srcRgb[2] * $effectiveAlpha + $dstRgb[2] * (1.0 - $effectiveAlpha));
                        $r = max(0, min(255, $r));
                        $g = max(0, min(255, $g));
                        $b = max(0, min(255, $b));
                        $dp->fg = IrcPalette::nearestColor($r, $g, $b);
                        $dp->fgAlpha = 1.0;
                        self::clearPixelMeta($dp);
                        $hasChange = true;
                    }
                }

                if ($sp->bg !== null) {
                    $effectiveAlpha = $sp->bgAlpha * $opacity;
                    if ($effectiveAlpha < 0.001) {
                        // skip
                    } elseif ($dp->bg === null) {
                        $dp->bg = $sp->bg;
                        $dp->bgAlpha = 1.0;
                        $hasChange = true;
                    } else {
                        $srcRgb = IrcPalette::getRgb($sp->bg);
                        $dstRgb = IrcPalette::getRgb($dp->bg);
                        $r = (int) round($srcRgb[0] * $effectiveAlpha + $dstRgb[0] * (1.0 - $effectiveAlpha));
                        $g = (int) round($srcRgb[1] * $effectiveAlpha + $dstRgb[1] * (1.0 - $effectiveAlpha));
                        $b = (int) round($srcRgb[2] * $effectiveAlpha + $dstRgb[2] * (1.0 - $effectiveAlpha));
                        $r = max(0, min(255, $r));
                        $g = max(0, min(255, $g));
                        $b = max(0, min(255, $b));
                        $dp->bg = IrcPalette::nearestColor($r, $g, $b);
                        $dp->bgAlpha = 1.0;
                        $hasChange = true;
                    }
                }

                if ($hasChange && $sp->text !== ' ') {
                    $dp->text = $sp->text;
                }
            }
        }
    }

    public static function applyMask(Canvas $dst, Canvas $src, Canvas $mask, MaskType $maskType, float $opacity = 1.0): void
    {
        if ($src->w !== $dst->w || $src->h !== $dst->h || $mask->w !== $dst->w || $mask->h !== $dst->h) {
            throw new \InvalidArgumentException(
                "Canvas size mismatch: {$dst->w}x{$dst->h} vs {$src->w}x{$src->h} vs {$mask->w}x{$mask->h}"
            );
        }

        $opacity = max(0.0, min(1.0, $opacity));

        for ($y = 0; $y < $dst->h; $y++) {
            for ($x = 0; $x < $dst->w; $x++) {
                $mp = $mask->data[$y][$x];
                $sp = $src->data[$y][$x];

                if ($mp->fg === null && $mp->bg === null) {
                    continue;
                }

                if ($maskType === MaskType::Luminance) {
                    if ($mp->fg !== null) {
                        $rgb = IrcPalette::getRgb($mp->fg);
                        $maskValue = (0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2]) / 255.0;
                    } else {
                        $rgb = IrcPalette::getRgb($mp->bg);
                        $maskValue = (0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2]) / 255.0;
                    }
                } else {
                    $maskValue = ($mp->fg !== null) ? $mp->fgAlpha : $mp->bgAlpha;
                }

                if ($maskValue < 0.001) {
                    continue;
                }

                $effectiveOpacity = $maskValue * $opacity;
                $dp = $dst->data[$y][$x];
                $hasChange = false;

                if ($sp->fg !== null) {
                    $effectiveAlpha = $sp->fgAlpha * $effectiveOpacity;
                    if ($effectiveAlpha < 0.001) {
                        // skip
                    } elseif ($dp->fg === null || $effectiveAlpha >= 0.999) {
                        $dp->fg = $sp->fg;
                        $dp->fgAlpha = 1.0;
                        self::copyPixelMeta($dp, $sp, 'fg');
                        $hasChange = true;
                    } else {
                        $srcRgb = IrcPalette::getRgb($sp->fg);
                        $dstRgb = IrcPalette::getRgb($dp->fg);
                        $r = (int) round($srcRgb[0] * $effectiveAlpha + $dstRgb[0] * (1.0 - $effectiveAlpha));
                        $g = (int) round($srcRgb[1] * $effectiveAlpha + $dstRgb[1] * (1.0 - $effectiveAlpha));
                        $b = (int) round($srcRgb[2] * $effectiveAlpha + $dstRgb[2] * (1.0 - $effectiveAlpha));
                        $r = max(0, min(255, $r));
                        $g = max(0, min(255, $g));
                        $b = max(0, min(255, $b));
                        $dp->fg = IrcPalette::nearestColor($r, $g, $b);
                        $dp->fgAlpha = 1.0;
                        self::clearPixelMeta($dp);
                        $hasChange = true;
                    }
                }

                if ($sp->bg !== null) {
                    $effectiveAlpha = $sp->bgAlpha * $effectiveOpacity;
                    if ($effectiveAlpha < 0.001) {
                        // skip
                    } elseif ($dp->bg === null) {
                        $dp->bg = $sp->bg;
                        $dp->bgAlpha = 1.0;
                        $hasChange = true;
                    } else {
                        $srcRgb = IrcPalette::getRgb($sp->bg);
                        $dstRgb = IrcPalette::getRgb($dp->bg);
                        $r = (int) round($srcRgb[0] * $effectiveAlpha + $dstRgb[0] * (1.0 - $effectiveAlpha));
                        $g = (int) round($srcRgb[1] * $effectiveAlpha + $dstRgb[1] * (1.0 - $effectiveAlpha));
                        $b = (int) round($srcRgb[2] * $effectiveAlpha + $dstRgb[2] * (1.0 - $effectiveAlpha));
                        $r = max(0, min(255, $r));
                        $g = max(0, min(255, $g));
                        $b = max(0, min(255, $b));
                        $dp->bg = IrcPalette::nearestColor($r, $g, $b);
                        $dp->bgAlpha = 1.0;
                        $hasChange = true;
                    }
                }

                if ($hasChange && $sp->text !== ' ') {
                    $dp->text = $sp->text;
                }
            }
        }
    }
}
