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
        $childBbox = ClipNode::computeBbox($this->child);
        $region = $this->filterRegion ?? FilterRegion::defaults();

        if ($this->filterUnits === GradientUnits::ObjectBoundingBox && $childBbox !== null) {
            $absRegion = $region->toAbsolute(
                $childBbox['x'], $childBbox['y'],
                $childBbox['w'], $childBbox['h'],
            );
        } else {
            $absRegion = [
                'x' => $region->x,
                'y' => $region->y,
                'w' => $region->width,
                'h' => $region->height,
            ];
        }

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
