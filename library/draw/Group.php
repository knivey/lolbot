<?php

namespace draw;

class Group implements SceneNode
{
    /** @var array<SceneNode> */
    private array $children = [];

    public function __construct(
        public readonly ?Paint $fill = null,
        public readonly ?StrokeStyle $stroke = null,
        public readonly ?Transform $transform = null,
        public readonly ?float $opacity = null,
        public readonly ?float $fillOpacity = null,
        public readonly ?FillRule $fillRule = null,
    ) {
    }

    public function addChild(SceneNode $node): void
    {
        $this->children[] = $node;
    }

    public function removeChild(SceneNode $node): void
    {
        foreach ($this->children as $i => $child) {
            if ($child === $node) {
                array_splice($this->children, $i, 1);
                return;
            }
        }
    }

    public function getChildren(): array
    {
        return $this->children;
    }

    public function render(Canvas $canvas, RenderContext $ctx): void
    {
        $childCtx = $ctx->merge(
            fill: $this->fill,
            stroke: $this->stroke,
            opacity: $this->opacity,
            fillOpacity: $this->fillOpacity,
            fillRule: $this->fillRule,
        );

        $canvas->save();

        if ($this->transform !== null) {
            $canvas->concatTransform($this->transform);
        }

        foreach ($this->children as $child) {
            $child->render($canvas, $childCtx);
        }

        $canvas->restore();
    }
}
