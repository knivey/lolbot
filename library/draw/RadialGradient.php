<?php

namespace draw;

class RadialGradient implements Paint
{
    /** @var ColorStop[] */
    public readonly array $stops;
    public readonly float $fx;
    public readonly float $fy;

    private ?Transform $sampleTransformInverse = null;

    /**
     * @param array<ColorStop> $stops
     */
    public function __construct(
        public readonly float $cx,
        public readonly float $cy,
        public readonly float $r,
        array $stops,
        ?float $fx = null,
        ?float $fy = null,
        public readonly SpreadMethod $spreadMethod = SpreadMethod::Pad,
        public readonly ?Dithering $dithering = null,
        ?Transform $sampleTransform = null,
    ) {
        if ($r <= 0) {
            throw new \InvalidArgumentException("RadialGradient radius must be > 0, got $r");
        }
        if (count($stops) < 2) {
            throw new \InvalidArgumentException('RadialGradient requires at least 2 stops');
        }
        usort($stops, fn(ColorStop $a, ColorStop $b) => $a->offset <=> $b->offset);
        $this->stops = $stops;
        $this->fx = $fx ?? $cx;
        $this->fy = $fy ?? $cy;
        $fdx = $this->fx - $cx;
        $fdy = $this->fy - $cy;
        if (sqrt($fdx * $fdx + $fdy * $fdy) >= $r) {
            throw new \InvalidArgumentException('Focal point must be inside the circle');
        }
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
        $dx = $x - $this->fx;
        $dy = $y - $this->fy;
        $dist = sqrt($dx * $dx + $dy * $dy);
        $t = $dist / $this->r;
        $t = $this->applySpread($t);
        return $this->interpolateStops($t);
    }

    public function getDithering(): ?Dithering
    {
        return $this->dithering;
    }

    public function withSampleTransform(?Transform $sampleTransform): self
    {
        $fx = ($this->fx == $this->cx) ? null : $this->fx;
        $fy = ($this->fy == $this->cy) ? null : $this->fy;
        return new self(
            $this->cx,
            $this->cy,
            $this->r,
            $this->stops,
            $fx,
            $fy,
            $this->spreadMethod,
            $this->dithering,
            $sampleTransform,
        );
    }

}
