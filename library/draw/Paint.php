<?php

namespace draw;

interface Paint
{
    /**
     * @return array{int, int, int}
     */
    public function getColorAt(float $x, float $y): array;

    public function isSolid(): bool;
}
