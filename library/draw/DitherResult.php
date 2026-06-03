<?php

namespace draw;

readonly class DitherResult
{
    public function __construct(
        public int $code,
        public bool $dithered = false,
        public int $secondBest = -1,
        public float $t = 0.0,
    ) {}
}
