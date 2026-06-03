<?php

namespace draw;

class StrokeStyle
{
    public function __construct(
        public readonly Color $color,
        public readonly float $width = 1.0,
        public readonly ?array $dashArray = null,
        public readonly float $dashOffset = 0.0,
        public readonly LineCap $lineCap = LineCap::Butt,
        public readonly LineJoin $lineJoin = LineJoin::Miter,
        public readonly float $miterLimit = 4.0,
        public readonly float $opacity = 1.0,
    ) {
        if ($dashArray !== null) {
            foreach ($dashArray as $v) {
                if ($v < 0) {
                    throw new \InvalidArgumentException('dashArray values must be >= 0');
                }
            }
        }
    }
}
