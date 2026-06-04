<?php

namespace draw;

class LinearGradient implements Paint
{
    /** @var ColorStop[] */
    public readonly array $stops;

    private ?Transform $sampleTransformInverse = null;

    /**
     * @param array<ColorStop> $stops
     */
    public function __construct(
        public readonly float $x1,
        public readonly float $y1,
        public readonly float $x2,
        public readonly float $y2,
        array $stops,
        public readonly SpreadMethod $spreadMethod = SpreadMethod::Pad,
        public readonly ?Dithering $dithering = null,
        ?Transform $sampleTransform = null,
    ) {
        if (count($stops) < 2) {
            throw new \InvalidArgumentException('LinearGradient requires at least 2 stops');
        }
        usort($stops, fn(ColorStop $a, ColorStop $b) => $a->offset <=> $b->offset);
        $this->stops = $stops;
        if ($sampleTransform !== null) {
            $this->sampleTransformInverse = $sampleTransform->inverse();
        }
    }

    use GradientMath;

    public function isSolid(): bool
    {
        return false;
    }

    public function getColorAt(float $x, float $y): array
    {
        if ($this->sampleTransformInverse !== null) {
            [$x, $y] = $this->sampleTransformInverse->apply($x, $y);
        }
        $dx = $this->x2 - $this->x1;
        $dy = $this->y2 - $this->y1;
        $lenSq = $dx * $dx + $dy * $dy;
        if ($lenSq == 0.0) {
            return [$this->stops[0]->r, $this->stops[0]->g, $this->stops[0]->b];
        }
        $t = (($x - $this->x1) * $dx + ($y - $this->y1) * $dy) / $lenSq;
        $t = $this->applySpread($t);
        return $this->interpolateStops($t);
    }

    public function getDithering(): ?Dithering
    {
        return $this->dithering;
    }

    public function withSampleTransform(?Transform $sampleTransform): self
    {
        return new self(
            $this->x1,
            $this->y1,
            $this->x2,
            $this->y2,
            $this->stops,
            $this->spreadMethod,
            $this->dithering,
            $sampleTransform,
        );
    }

}
