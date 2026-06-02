<?php
namespace draw;

class ClosePath implements PathSegment
{
    public function __construct(
        private float $returnX,
        private float $returnY
    ) {
    }

    public function flatten(float $startX, float $startY, float $tolerance): array
    {
        return [];
    }

    /** @return array{float, float} */
    public function endPoint(): array
    {
        return [$this->returnX, $this->returnY];
    }
}
