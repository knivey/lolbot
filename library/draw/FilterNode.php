<?php

namespace draw;

use Psr\Log\LoggerInterface;

class FilterNode implements SceneNode
{
    public function __construct(
        public readonly SceneNode $child,
        public readonly array $primitives,
        public readonly ?FilterRegion $filterRegion = null,
        public readonly GradientUnits $filterUnits = GradientUnits::ObjectBoundingBox,
        public readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function getChildren(): array
    {
        return [$this->child];
    }

    public function render(Canvas $canvas, RenderContext $ctx): void
    {
        // Filter region support is deferred — currently renders to a full-size offscreen canvas.
        $childCanvas = Canvas::createBlank($canvas->w, $canvas->h, $canvas->halfblocks);
        $childCanvas->setTransform($canvas->getTransform());
        $childCanvas->setDithering($canvas->getDithering());
        $this->child->render($childCanvas, $ctx);

        $pipeline = new FilterPipeline($childCanvas, $this->logger);

        $lastResult = $childCanvas;
        foreach ($this->primitives as $primitive) {
            $inputName = $primitive->getInput();
            if ($inputName !== null) {
                $resolvedInput = $pipeline->getResult($inputName);
                if ($resolvedInput === null) {
                    $this->logger?->warning("SVG filter primitive references unknown input '{$inputName}', skipping");
                    continue;
                }
            } else {
                $resolvedInput = $lastResult;
            }
            $lastResult = $primitive->apply($resolvedInput, $pipeline);
        }

        Compositor::blend($canvas, $lastResult);
    }
}
