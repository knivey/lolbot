<?php

namespace draw;

class DropShadowPrimitive implements FilterPrimitive
{
    public function __construct(
        private readonly float $dx,
        private readonly float $dy,
        private readonly float $stdDeviation,
        private readonly array $floodColor,
        private readonly float $floodOpacity,
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
        $sourceGraphic = $pipeline->getResult('SourceGraphic');

        $alphaCanvas = $this->extractAlpha($input);

        $blurPrimitive = new GaussianBlurPrimitive($this->stdDeviation);
        $blurredAlpha = $blurPrimitive->apply($alphaCanvas, $pipeline);

        $offsetPrimitive = new OffsetPrimitive($this->dx, $this->dy);
        $offsetShadow = $offsetPrimitive->apply($blurredAlpha, $pipeline);

        $shadowCanvas = $this->floodMask(
            $offsetShadow,
            $this->floodColor,
            $this->floodOpacity,
        );

        $output = Canvas::createBlank($input->w, $input->h, $input->halfblocks);
        Compositor::blend($output, $shadowCanvas);
        Compositor::blend($output, $sourceGraphic);

        if ($this->result !== null) {
            $pipeline->setResult($this->result, $output);
        }

        return $output;
    }

    private function extractAlpha(Canvas $input): Canvas
    {
        $alpha = Canvas::createBlank($input->w, $input->h, $input->halfblocks);

        for ($y = 0; $y < $input->h; $y++) {
            for ($x = 0; $x < $input->w; $x++) {
                $sp = $input->data[$y][$x];
                $dp = $alpha->data[$y][$x];
                if ($sp->fg !== null) {
                    $dp->fg = Color::White;
                    $dp->fgAlpha = $sp->fgAlpha;
                }
                if ($sp->bg !== null) {
                    $dp->bg = Color::White;
                    $dp->bgAlpha = $sp->bgAlpha;
                }
            }
        }

        return $alpha;
    }

    private function floodMask(Canvas $mask, array $floodColor, float $floodOpacity): Canvas
    {
        $flooded = Canvas::createBlank($mask->w, $mask->h, $mask->halfblocks);
        $floodCode = IrcPalette::nearestColor(
            (int) round($floodColor[0]),
            (int) round($floodColor[1]),
            (int) round($floodColor[2]),
        );

        for ($y = 0; $y < $mask->h; $y++) {
            for ($x = 0; $x < $mask->w; $x++) {
                $mp = $mask->data[$y][$x];
                $dp = $flooded->data[$y][$x];

                if ($mp->fg !== null) {
                    $maskRgb = IrcPalette::getRgb($mp->fg);
                    $maskLum = (0.2126 * $maskRgb[0] + 0.7152 * $maskRgb[1] + 0.0722 * $maskRgb[2]) / 255.0;
                    $effectiveAlpha = $maskLum * $mp->fgAlpha;
                    if ($effectiveAlpha > 0.001) {
                        $dp->fg = $floodCode;
                        $dp->fgAlpha = min(1.0, $effectiveAlpha * $floodOpacity);
                    }
                }

                if ($mp->bg !== null) {
                    $maskRgb = IrcPalette::getRgb($mp->bg);
                    $maskLum = (0.2126 * $maskRgb[0] + 0.7152 * $maskRgb[1] + 0.0722 * $maskRgb[2]) / 255.0;
                    $effectiveAlpha = $maskLum * $mp->bgAlpha;
                    if ($effectiveAlpha > 0.001) {
                        $dp->bg = $floodCode;
                        $dp->bgAlpha = min(1.0, $effectiveAlpha * $floodOpacity);
                    }
                }
            }
        }

        return $flooded;
    }
}
