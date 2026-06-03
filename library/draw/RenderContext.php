<?php

namespace draw;

class RenderContext
{
    public function __construct(
        public readonly Paint $fill,
        public readonly ?StrokeStyle $stroke,
        public readonly Transform $transform,
        public readonly float $opacity,
        public readonly float $fillOpacity,
        public readonly FillRule $fillRule,
        public readonly ?Dithering $dithering = null,
    ) {
    }

    public static function defaults(): self
    {
        return new self(
            fill: new Color(0, null),
            stroke: null,
            transform: Transform::identity(),
            opacity: 1.0,
            fillOpacity: 1.0,
            fillRule: FillRule::NonZero,
            dithering: null,
        );
    }

    public function merge(
        ?Paint $fill = null,
        ?StrokeStyle $stroke = null,
        ?Transform $transform = null,
        ?float $opacity = null,
        ?float $fillOpacity = null,
        ?FillRule $fillRule = null,
        ?Dithering $dithering = null,
    ): self {
        return new self(
            fill: $fill ?? $this->fill,
            stroke: $stroke ?? $this->stroke,
            transform: $transform !== null
                ? $this->transform->multiply($transform)
                : $this->transform,
            opacity: $opacity !== null
                ? $this->opacity * $opacity
                : $this->opacity,
            fillOpacity: $fillOpacity !== null
                ? $this->fillOpacity * $fillOpacity
                : $this->fillOpacity,
            fillRule: $fillRule ?? $this->fillRule,
            dithering: $dithering ?? $this->dithering,
        );
    }
}
