<?php

namespace draw;

class ColorMatrixPrimitive implements FilterPrimitive
{
    public function __construct(
        private readonly string $type,
        private readonly array $values,
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
        $matrix = $this->buildMatrix();
        $output = Canvas::createBlank($input->w, $input->h, $input->halfblocks);

        for ($y = 0; $y < $input->h; $y++) {
            for ($x = 0; $x < $input->w; $x++) {
                $sp = $input->data[$y][$x];
                $dp = $output->data[$y][$x];

                if ($sp->fg !== null) {
                    $rgb = IrcPalette::getRgb($sp->fg);
                    $this->applyMatrix($dp, 'fg', $rgb, $sp->fgAlpha, $matrix);
                }

                if ($sp->bg !== null) {
                    $rgb = IrcPalette::getRgb($sp->bg);
                    $this->applyMatrix($dp, 'bg', $rgb, $sp->bgAlpha, $matrix);
                }
            }
        }

        if ($this->result !== null) {
            $pipeline->setResult($this->result, $output);
        }

        return $output;
    }

    private function buildMatrix(): array
    {
        return match ($this->type) {
            'matrix' => $this->values,
            'saturate' => $this->buildSaturateMatrix($this->values[0] ?? 1.0),
            'hueRotate' => $this->buildHueRotateMatrix($this->values[0] ?? 0.0),
            'luminanceToAlpha' => [
                0, 0, 0, 0, 0,
                0, 0, 0, 0, 0,
                0, 0, 0, 0, 0,
                0.2126, 0.7152, 0.0722, 0, 0,
            ],
            default => [
                1, 0, 0, 0, 0,
                0, 1, 0, 0, 0,
                0, 0, 1, 0, 0,
                0, 0, 0, 1, 0,
            ],
        };
    }

    private function buildSaturateMatrix(float $s): array
    {
        return [
            0.213 + 0.787 * $s, 0.715 - 0.715 * $s, 0.072 - 0.072 * $s, 0, 0,
            0.213 - 0.213 * $s, 0.715 + 0.285 * $s, 0.072 - 0.072 * $s, 0, 0,
            0.213 - 0.213 * $s, 0.715 - 0.715 * $s, 0.072 + 0.928 * $s, 0, 0,
            0, 0, 0, 1, 0,
        ];
    }

    private function buildHueRotateMatrix(float $angle): array
    {
        $rad = deg2rad($angle);
        $cos = cos($rad);
        $sin = sin($rad);

        $a00 = 0.213 + $cos * 0.787 - $sin * 0.213;
        $a01 = 0.715 - $cos * 0.715 - $sin * 0.715;
        $a02 = 0.072 - $cos * 0.072 + $sin * 0.928;

        $a10 = 0.213 - $cos * 0.213 + $sin * 0.143;
        $a11 = 0.715 + $cos * 0.285 + $sin * 0.140;
        $a12 = 0.072 - $cos * 0.072 - $sin * 0.283;

        $a20 = 0.213 - $cos * 0.213 - $sin * 0.787;
        $a21 = 0.715 - $cos * 0.715 + $sin * 0.715;
        $a22 = 0.072 + $cos * 0.928 + $sin * 0.072;

        return [
            $a00, $a01, $a02, 0, 0,
            $a10, $a11, $a12, 0, 0,
            $a20, $a21, $a22, 0, 0,
            0, 0, 0, 1, 0,
        ];
    }

    private function applyMatrix(Pixel $dp, string $channel, array $rgb, float $alpha, array $m): void
    {
        $r = $rgb[0] / 255.0;
        $g = $rgb[1] / 255.0;
        $b = $rgb[2] / 255.0;
        $a = $alpha;

        $nr = $m[0] * $r + $m[1] * $g + $m[2] * $b + $m[3] * $a + $m[4];
        $ng = $m[5] * $r + $m[6] * $g + $m[7] * $b + $m[8] * $a + $m[9];
        $nb = $m[10] * $r + $m[11] * $g + $m[12] * $b + $m[13] * $a + $m[14];
        $na = $m[15] * $r + $m[16] * $g + $m[17] * $b + $m[18] * $a + $m[19];

        $ir = (int) round(max(0, min(255, $nr * 255.0)));
        $ig = (int) round(max(0, min(255, $ng * 255.0)));
        $ib = (int) round(max(0, min(255, $nb * 255.0)));
        $ia = max(0.0, min(1.0, $na));

        $code = IrcPalette::nearestColor($ir, $ig, $ib);

        if ($channel === 'fg') {
            $dp->fg = $code;
            $dp->fgAlpha = $ia;
        } else {
            $dp->bg = $code;
            $dp->bgAlpha = $ia;
        }
    }
}
