<?php

namespace draw;

interface FilterPrimitive
{
    public function getInput(): ?string;

    public function getResult(): ?string;

    /**
     * @param Canvas $input
     * @param FilterPipeline $pipeline
     * @return Canvas
     */
    public function apply(Canvas $input, FilterPipeline $pipeline): Canvas;
}
