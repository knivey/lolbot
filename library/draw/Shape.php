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
        public readonly ?Dithering $dithering = null,
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
            dithering: $this->dithering,
        );

        $effectiveFill = ($effective->fill instanceof NoPaint) ? null : $effective->fill;
        $effectiveStroke = $effective->stroke;
        if ($effectiveStroke !== null && $effectiveStroke->paint instanceof NoPaint) {
            $effectiveStroke = null;
        }

        if ($effectiveFill === null && $effectiveStroke === null) {
            return;
        }

        if ($effective->opacity < 0.001 && ($effective->stroke === null || $effective->stroke->opacity < 0.001)) {
            return;
        }

        $canvas->save();

        if ($this->transform !== null) {
            $canvas->concatTransform($this->transform);
        }

        $prevDithering = $canvas->getDithering();
        $resolvedDithering = $effective->dithering ?? $prevDithering;
        $canvas->setDithering($resolvedDithering);

        $canvas->drawPath(
            $this->path,
            $effectiveFill,
            $effectiveStroke,
            '',
            $effective->fillRule,
            $effective->fillOpacity,
            $effective->opacity,
        );

        $canvas->setDithering($prevDithering);

        $canvas->restore();
    }
}
