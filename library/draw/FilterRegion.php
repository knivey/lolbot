<?php

namespace draw;

class FilterRegion
{
    public function __construct(
        public readonly float $x,
        public readonly float $y,
        public readonly float $width,
        public readonly float $height,
    ) {
    }

    public static function defaults(): self
    {
        return new self(-0.1, -0.1, 1.2, 1.2);
    }

    /**
     * @return array{x: float, y: float, width: float, height: float}
     */
    public function toAbsolute(float $bboxX, float $bboxY, float $bboxW, float $bboxH): array
    {
        return [
            'x' => $bboxX + $this->x * $bboxW,
            'y' => $bboxY + $this->y * $bboxH,
            'width' => $this->width * $bboxW,
            'height' => $this->height * $bboxH,
        ];
    }
}
