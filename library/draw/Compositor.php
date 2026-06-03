<?php

namespace draw;

class Compositor
{
    public static function blend(Canvas $dst, Canvas $src, float $opacity = 1.0): void
    {
        if ($src->w !== $dst->w || $src->h !== $dst->h) {
            throw new \InvalidArgumentException(
                "Canvas size mismatch: {$dst->w}x{$dst->h} vs {$src->w}x{$src->h}"
            );
        }

        $opacity = max(0.0, min(1.0, $opacity));

        for ($y = 0; $y < $dst->h; $y++) {
            for ($x = 0; $x < $dst->w; $x++) {
                $dp = $dst->data[$y][$x];
                $sp = $src->data[$y][$x];

                $hasChange = false;

                if ($sp->fg !== null) {
                    $effectiveAlpha = $sp->fgAlpha * $opacity;
                    if ($effectiveAlpha < 0.001) {
                        // skip
                    } elseif ($dp->fg === null) {
                        $dp->fg = $sp->fg;
                        $dp->fgAlpha = 1.0;
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
}
