<?php

namespace draw;

class MaskNode implements SceneNode
{
    public function __construct(
        public readonly SceneNode $child,
        public readonly Group $maskContent,
        public readonly GradientUnits $maskUnits = GradientUnits::ObjectBoundingBox,
        public readonly GradientUnits $maskContentUnits = GradientUnits::UserSpaceOnUse,
        public readonly MaskType $maskType = MaskType::Luminance,
        public readonly ?Transform $transform = null,
    ) {
    }

    public function getChildren(): array
    {
        return [$this->child];
    }

    public function render(Canvas $canvas, RenderContext $ctx): void
    {
        $childBbox = ClipNode::computeBbox($this->child);

        $maskCanvas = Canvas::createBlank($canvas->w, $canvas->h, $canvas->halfblocks);
        if ($this->transform !== null) {
            $maskCanvas->concatTransform($this->transform);
        }
        if ($this->maskContentUnits === GradientUnits::ObjectBoundingBox && $childBbox !== null) {
            $bboxTransform = Transform::translate($childBbox['x'], $childBbox['y'])
                ->multiply(Transform::scale($childBbox['w'], $childBbox['h']));
            $maskCanvas->concatTransform($bboxTransform);
        }
        $this->maskContent->render($maskCanvas, $ctx);

        $childCanvas = Canvas::createBlank($canvas->w, $canvas->h, $canvas->halfblocks);
        $this->child->render($childCanvas, $ctx);

        Compositor::applyMask($canvas, $childCanvas, $maskCanvas, $this->maskType);
    }
}
