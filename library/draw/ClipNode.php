<?php

namespace draw;

class ClipNode implements SceneNode
{
    public function __construct(
        public readonly SceneNode $child,
        public readonly Group $clipContent,
        public readonly GradientUnits $clipPathUnits = GradientUnits::UserSpaceOnUse,
        public readonly ?Transform $transform = null,
    ) {
    }

    public function getChildren(): array
    {
        return [$this->child];
    }

    public function render(Canvas $canvas, RenderContext $ctx): void
    {
        $childBbox = self::computeBbox($this->child);

        $clipCanvas = Canvas::createBlank($canvas->w, $canvas->h, $canvas->halfblocks);
        $clipCanvas->setTransform($canvas->getTransform());
        $clipCanvas->setDithering($canvas->getDithering());
        if ($this->transform !== null) {
            $clipCanvas->concatTransform($this->transform);
        }
        if ($this->clipPathUnits === GradientUnits::ObjectBoundingBox && $childBbox !== null) {
            $bboxTransform = Transform::translate($childBbox['x'], $childBbox['y'])
                ->multiply(Transform::scale($childBbox['w'], $childBbox['h']));
            $clipCanvas->concatTransform($bboxTransform);
        }
        $this->clipContent->render($clipCanvas, $ctx);

        $childCanvas = Canvas::createBlank($canvas->w, $canvas->h, $canvas->halfblocks);
        $childCanvas->setTransform($canvas->getTransform());
        $childCanvas->setDithering($canvas->getDithering());
        $this->child->render($childCanvas, $ctx);

        Compositor::applyClip($canvas, $childCanvas, $clipCanvas);
    }

    public static function computeBbox(SceneNode $node): ?array
    {
        if ($node instanceof Shape) {
            return $node->path->getBBox();
        }
        if ($node instanceof Group) {
            $result = null;
            foreach ($node->getChildren() as $child) {
                $childBbox = self::computeBbox($child);
                if ($childBbox === null) {
                    continue;
                }
                if ($result === null) {
                    $result = $childBbox;
                } else {
                    $result = [
                        'x' => min($result['x'], $childBbox['x']),
                        'y' => min($result['y'], $childBbox['y']),
                        'w' => max($result['x'] + $result['w'], $childBbox['x'] + $childBbox['w']) - min($result['x'], $childBbox['x']),
                        'h' => max($result['y'] + $result['h'], $childBbox['y'] + $childBbox['h']) - min($result['y'], $childBbox['y']),
                    ];
                }
            }
            return $result;
        }
        if ($node instanceof ClipNode || $node instanceof MaskNode || $node instanceof FilterNode) {
            return self::computeBbox($node->child);
        }
        return null;
    }
}
