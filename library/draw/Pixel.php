<?php

namespace draw;

class Pixel
{
    public ?int $fg = null;
    public ?int $bg = null;
    public string $text = ' ';

    public function __toString(): string
    {
        //can't do colors here because it doesnt know whats before it
        return $this->text;
    }
}