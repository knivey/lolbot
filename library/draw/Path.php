<?php
namespace draw;

class Path
{
    /** @var array<PathSegment> */
    private array $segments = [];

    private float $currentX = 0.0;
    private float $currentY = 0.0;
    private float $subpathStartX = 0.0;
    private float $subpathStartY = 0.0;
    private bool $hasCurrentPoint = false;

    // Previous curve control point for smooth-curve reflection.
    // Only set when the previous segment was a cubic or quadratic.
    private ?float $prevCubicC2x = null;
    private ?float $prevCubicC2y = null;
    private ?float $prevQuadCpx = null;
    private ?float $prevQuadCpy = null;

    public function moveTo(float $x, float $y): self
    {
        $this->segments[] = new MoveTo($x, $y);
        $this->currentX = $x;
        $this->currentY = $y;
        $this->subpathStartX = $x;
        $this->subpathStartY = $y;
        $this->hasCurrentPoint = true;
        $this->prevCubicC2x = null;
        $this->prevCubicC2y = null;
        $this->prevQuadCpx = null;
        $this->prevQuadCpy = null;
        return $this;
    }

    public function lineTo(float $x, float $y): self
    {
        $this->ensureCurrentPoint();
        $this->segments[] = new LineTo($x, $y);
        $this->currentX = $x;
        $this->currentY = $y;
        $this->prevCubicC2x = null;
        $this->prevCubicC2y = null;
        $this->prevQuadCpx = null;
        $this->prevQuadCpy = null;
        return $this;
    }

    public function horizontalLineTo(float $x): self
    {
        return $this->lineTo($x, $this->currentY);
    }

    public function verticalLineTo(float $y): self
    {
        return $this->lineTo($this->currentX, $y);
    }

    public function cubicTo(float $c1x, float $c1y, float $c2x, float $c2y, float $x, float $y): self
    {
        $this->ensureCurrentPoint();
        $this->segments[] = new CubicBezier($c1x, $c1y, $c2x, $c2y, $x, $y);
        $this->currentX = $x;
        $this->currentY = $y;
        $this->prevCubicC2x = $c2x;
        $this->prevCubicC2y = $c2y;
        $this->prevQuadCpx = null;
        $this->prevQuadCpy = null;
        return $this;
    }

    public function smoothCubicTo(float $c2x, float $c2y, float $x, float $y): self
    {
        $this->ensureCurrentPoint();
        if ($this->prevCubicC2x !== null) {
            $c1x = 2.0 * $this->currentX - $this->prevCubicC2x;
            $c1y = 2.0 * $this->currentY - $this->prevCubicC2y;
        } else {
            $c1x = $this->currentX;
            $c1y = $this->currentY;
        }
        return $this->cubicTo($c1x, $c1y, $c2x, $c2y, $x, $y);
    }

    public function quadTo(float $cpx, float $cpy, float $x, float $y): self
    {
        $this->ensureCurrentPoint();
        $this->segments[] = new QuadraticBezier($cpx, $cpy, $x, $y);
        $this->currentX = $x;
        $this->currentY = $y;
        $this->prevCubicC2x = null;
        $this->prevCubicC2y = null;
        $this->prevQuadCpx = $cpx;
        $this->prevQuadCpy = $cpy;
        return $this;
    }

    public function smoothQuadTo(float $x, float $y): self
    {
        $this->ensureCurrentPoint();
        if ($this->prevQuadCpx !== null) {
            $cpx = 2.0 * $this->currentX - $this->prevQuadCpx;
            $cpy = 2.0 * $this->currentY - $this->prevQuadCpy;
        } else {
            $cpx = $this->currentX;
            $cpy = $this->currentY;
        }
        return $this->quadTo($cpx, $cpy, $x, $y);
    }

    public function arcTo(
        float $rx,
        float $ry,
        float $xAxisRot,
        bool $largeArc,
        bool $sweep,
        float $x,
        float $y
    ): self {
        $this->ensureCurrentPoint();
        $this->segments[] = new EllipticalArc($rx, $ry, $xAxisRot, $largeArc, $sweep, $x, $y);
        $this->currentX = $x;
        $this->currentY = $y;
        $this->prevCubicC2x = null;
        $this->prevCubicC2y = null;
        $this->prevQuadCpx = null;
        $this->prevQuadCpy = null;
        return $this;
    }

    public function closePath(): self
    {
        if (!$this->hasCurrentPoint) {
            return $this;
        }
        $this->segments[] = new ClosePath($this->subpathStartX, $this->subpathStartY);
        $this->currentX = $this->subpathStartX;
        $this->currentY = $this->subpathStartY;
        $this->prevCubicC2x = null;
        $this->prevCubicC2y = null;
        $this->prevQuadCpx = null;
        $this->prevQuadCpy = null;
        return $this;
    }

    /** @return array{float, float} */
    public function getCurrentPoint(): array
    {
        return [$this->currentX, $this->currentY];
    }

    public function isEmpty(): bool
    {
        return count($this->segments) === 0;
    }

    private function ensureCurrentPoint(): void
    {
        if (!$this->hasCurrentPoint) {
            $this->moveTo(0.0, 0.0);
        }
    }
}
