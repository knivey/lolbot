<?php

namespace draw;

class TextRun
{
    public function __construct(
        public readonly string $text,
        public readonly ?string $fontFamily = null,
        public readonly ?float $fontSize = null,
        public readonly ?string $fontWeight = null,
        public readonly ?string $fontStyle = null,
        public readonly ?Paint $fill = null,
        public readonly ?StrokeStyle $stroke = null,
        public readonly ?float $dx = null,
        public readonly ?float $dy = null,
    ) {
    }
}
