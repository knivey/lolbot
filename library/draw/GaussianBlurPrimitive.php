<?php

namespace draw;

class GaussianBlurPrimitive implements FilterPrimitive
{
    public function __construct(
        private readonly float $stdDeviation,
        private readonly ?string $input = null,
        private readonly ?string $result = null,
    ) {
    }

    public function getInput(): ?string
    {
        return $this->input;
    }

    public function getResult(): ?string
    {
        return $this->result;
    }

    public function apply(Canvas $input, FilterPipeline $pipeline): Canvas
    {
        if ($this->stdDeviation < 0.001) {
            $output = Canvas::createBlank($input->w, $input->h, $input->halfblocks);
            Compositor::blend($output, $input);
            if ($this->result !== null) {
                $pipeline->setResult($this->result, $output);
            }
            return $output;
        }

        $boxRadius = (int) floor($this->stdDeviation * sqrt(12.0 / 3.0) / 2.0 + 0.5);
        if ($boxRadius < 1) {
            $boxRadius = 1;
        }

        $pass1 = self::boxBlurH($input, $boxRadius);
        $pass2 = self::boxBlurV($pass1, $boxRadius);
        $output = self::boxBlurH($pass2, $boxRadius);

        if ($this->result !== null) {
            $pipeline->setResult($this->result, $output);
        }

        return $output;
    }

    private static function boxBlurH(Canvas $src, int $radius): Canvas
    {
        $dst = Canvas::createBlank($src->w, $src->h, $src->halfblocks);

        for ($y = 0; $y < $src->h; $y++) {
            for ($x = 0; $x < $src->w; $x++) {
                $fgR = 0.0;
                $fgG = 0.0;
                $fgB = 0.0;
                $fgCount = 0;

                $bgR = 0.0;
                $bgG = 0.0;
                $bgB = 0.0;
                $bgCount = 0;

                for ($k = -$radius; $k <= $radius; $k++) {
                    $sx = $x + $k;
                    if ($sx < 0) {
                        $sx = 0;
                    }
                    if ($sx >= $src->w) {
                        $sx = $src->w - 1;
                    }

                    $sp = $src->data[$y][$sx];

                    if ($sp->fg !== null) {
                        $rgb = IrcPalette::getRgb($sp->fg);
                        $fgR += $rgb[0];
                        $fgG += $rgb[1];
                        $fgB += $rgb[2];
                        $fgCount++;
                    }

                    if ($sp->bg !== null) {
                        $rgb = IrcPalette::getRgb($sp->bg);
                        $bgR += $rgb[0];
                        $bgG += $rgb[1];
                        $bgB += $rgb[2];
                        $bgCount++;
                    }
                }

                $dp = $dst->data[$y][$x];
                if ($fgCount > 0) {
                    $dp->fg = IrcPalette::nearestColor(
                        (int) round($fgR / $fgCount),
                        (int) round($fgG / $fgCount),
                        (int) round($fgB / $fgCount),
                    );
                    $dp->fgAlpha = 1.0;
                }
                if ($bgCount > 0) {
                    $dp->bg = IrcPalette::nearestColor(
                        (int) round($bgR / $bgCount),
                        (int) round($bgG / $bgCount),
                        (int) round($bgB / $bgCount),
                    );
                    $dp->bgAlpha = 1.0;
                }
            }
        }

        return $dst;
    }

    private static function boxBlurV(Canvas $src, int $radius): Canvas
    {
        $dst = Canvas::createBlank($src->w, $src->h, $src->halfblocks);

        for ($y = 0; $y < $src->h; $y++) {
            for ($x = 0; $x < $src->w; $x++) {
                $fgR = 0.0;
                $fgG = 0.0;
                $fgB = 0.0;
                $fgCount = 0;

                $bgR = 0.0;
                $bgG = 0.0;
                $bgB = 0.0;
                $bgCount = 0;

                for ($k = -$radius; $k <= $radius; $k++) {
                    $sy = $y + $k;
                    if ($sy < 0) {
                        $sy = 0;
                    }
                    if ($sy >= $src->h) {
                        $sy = $src->h - 1;
                    }

                    $sp = $src->data[$sy][$x];

                    if ($sp->fg !== null) {
                        $rgb = IrcPalette::getRgb($sp->fg);
                        $fgR += $rgb[0];
                        $fgG += $rgb[1];
                        $fgB += $rgb[2];
                        $fgCount++;
                    }

                    if ($sp->bg !== null) {
                        $rgb = IrcPalette::getRgb($sp->bg);
                        $bgR += $rgb[0];
                        $bgG += $rgb[1];
                        $bgB += $rgb[2];
                        $bgCount++;
                    }
                }

                $dp = $dst->data[$y][$x];
                if ($fgCount > 0) {
                    $dp->fg = IrcPalette::nearestColor(
                        (int) round($fgR / $fgCount),
                        (int) round($fgG / $fgCount),
                        (int) round($fgB / $fgCount),
                    );
                    $dp->fgAlpha = 1.0;
                }
                if ($bgCount > 0) {
                    $dp->bg = IrcPalette::nearestColor(
                        (int) round($bgR / $bgCount),
                        (int) round($bgG / $bgCount),
                        (int) round($bgB / $bgCount),
                    );
                    $dp->bgAlpha = 1.0;
                }
            }
        }

        return $dst;
    }
}
