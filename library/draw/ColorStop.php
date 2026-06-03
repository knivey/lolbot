<?php

namespace draw;

class ColorStop
{
    public function __construct(
        public readonly float $offset,
        public readonly int $r,
        public readonly int $g,
        public readonly int $b,
    ) {
        if ($offset < 0.0 || $offset > 1.0) {
            throw new \InvalidArgumentException("ColorStop offset must be 0.0-1.0, got $offset");
        }
        if ($r < 0 || $r > 255) {
            throw new \InvalidArgumentException("ColorStop r must be 0-255, got $r");
        }
        if ($g < 0 || $g > 255) {
            throw new \InvalidArgumentException("ColorStop g must be 0-255, got $g");
        }
        if ($b < 0 || $b > 255) {
            throw new \InvalidArgumentException("ColorStop b must be 0-255, got $b");
        }
    }
}
