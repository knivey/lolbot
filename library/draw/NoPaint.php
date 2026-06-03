<?php

namespace draw;

class NoPaint implements Paint
{
    public function getColorAt(float $x, float $y): array
    {
        return [0, 0, 0];
    }

    public function isSolid(): bool
    {
        return false;
    }

    public function getDithering(): ?Dithering
    {
        return null;
    }
}
