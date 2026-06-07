<?php

namespace draw;

class TspanNode
{
    public function __construct(
        public string $text = '',
        public ?float $dx = null,
        public ?float $dy = null,
        public ?string $fontFamily = null,
        public ?float $fontSize = null,
        public ?string $fontWeight = null,
        public ?string $fontStyle = null,
        public ?Paint $fill = null,
        public ?StrokeStyle $stroke = null,
    ) {
    }
}
