<?php
namespace draw;

class LineTo implements PathSegment
{
    public function __construct(
        private float $x,
        private float $y
    ) {
    }

    public function flatten(float $startX, float $startY, float $tolerance, int &$budget): array
    {
        if (--$budget < 0) {
            throw new \InvalidArgumentException('svg path too complex');
        }
        return [[$this->x, $this->y]];
    }

    /** @return array{float, float} */
    public function endPoint(): array
    {
        return [$this->x, $this->y];
    }
}
