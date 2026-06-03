<?php

namespace draw;

class Shape implements SceneNode
{
    public function __construct(
        public readonly Path $path,
        public readonly ?Paint $fill = null,
        public readonly ?StrokeStyle $stroke = null,
        public readonly ?Transform $transform = null,
        public readonly ?float $opacity = null,
        public readonly ?float $fillOpacity = null,
        public readonly ?FillRule $fillRule = null,
    ) {
    }

    public function getChildren(): array
    {
        return [];
    }

    public function render(Canvas $canvas, RenderContext $ctx): void
    {
        $effective = $ctx->merge(
            fill: $this->fill,
            stroke: $this->stroke,
            opacity: $this->opacity,
            fillOpacity: $this->fillOpacity,
            fillRule: $this->fillRule,
        );

        if ($effective->fill === null && $effective->stroke === null) {
            return;
        }

        if ($effective->opacity < 0.001 && ($effective->stroke === null || $effective->stroke->opacity < 0.001)) {
            return;
        }

        $canvas->save();

        if ($this->transform !== null) {
            $canvas->concatTransform($this->transform);
        }

        $canvas->drawPath(
            $this->path,
            $effective->fill,
            $effective->stroke,
            '',
            $effective->fillRule,
            $effective->fillOpacity,
            $effective->opacity,
        );

        $canvas->restore();
    }
}
