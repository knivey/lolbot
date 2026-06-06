<?php

namespace draw;

class OffsetPrimitive implements FilterPrimitive
{
    public function __construct(
        private readonly float $dx,
        private readonly float $dy,
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
        $output = Canvas::createBlank($input->w, $input->h, $input->halfblocks);

        $shiftX = (int) round($this->dx);
        $shiftY = (int) round($this->dy);

        for ($y = 0; $y < $input->h; $y++) {
            for ($x = 0; $x < $input->w; $x++) {
                $srcX = $x - $shiftX;
                $srcY = $y - $shiftY;
                if ($srcX < 0 || $srcX >= $input->w || $srcY < 0 || $srcY >= $input->h) {
                    continue;
                }
                $sp = $input->data[$srcY][$srcX];
                if ($sp->fg === null && $sp->bg === null) {
                    continue;
                }
                $dp = $output->data[$y][$x];
                if ($sp->fg !== null) {
                    $dp->fg = $sp->fg;
                    $dp->fgAlpha = $sp->fgAlpha;
                    $dp->dithered = $sp->dithered;
                    $dp->secondBest = $sp->secondBest;
                    $dp->t = $sp->t;
                }
                if ($sp->bg !== null) {
                    $dp->bg = $sp->bg;
                    $dp->bgAlpha = $sp->bgAlpha;
                }
                if ($sp->text !== ' ') {
                    $dp->text = $sp->text;
                }
            }
        }

        if ($this->result !== null) {
            $pipeline->setResult($this->result, $output);
        }

        return $output;
    }
}
