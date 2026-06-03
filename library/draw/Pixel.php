<?php

namespace draw;

class Pixel
{
    public ?int $fg = null;
    public ?int $bg = null;
    public float $fgAlpha = 1.0;
    public float $bgAlpha = 1.0;
    public string $text = ' ';
    public bool $dithered = false;
    public int $secondBest = -1;
    public float $t = 0.0;

    public function __toString(): string
    {
        //can't do colors here because it doesnt know whats before it
        return $this->text;
    }
}