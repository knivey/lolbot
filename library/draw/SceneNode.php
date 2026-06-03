<?php

namespace draw;

interface SceneNode
{
    /** @return array<SceneNode> */
    public function getChildren(): array;

    public function render(Canvas $canvas, RenderContext $ctx): void;
}
