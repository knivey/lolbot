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
    private ?Transform $transform = null;

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

    /**
     * Flatten all segments into polygon vertices grouped by subpath.
     *
     * Each subpath is an array with keys:
     * - 'vertices': list of [x, y] float pairs
     * - 'closed': bool (true if the subpath ended with ClosePath)
     *
     * Degenerate subpaths (single MoveTo with no drawing commands) are omitted.
     *
     * @param float $tolerance Maximum deviation from true curve, in canvas units.
     * @return array<int, array{vertices: array<int, array{float, float}>, closed: bool}>
     */
    public function flatten(float $tolerance = 0.5): array
    {
        /** @var array<int, array{float, float}> $currentVertices */
        $currentVertices = [];
        $subpaths = [];
        $currentX = 0.0;
        $currentY = 0.0;
        $inSubpath = false;

        foreach ($this->segments as $seg) {
            if ($seg instanceof MoveTo) {
                // Finish previous subpath if it has vertices
                if ($inSubpath && count($currentVertices) >= 2) {
                    $subpaths[] = ['vertices' => $currentVertices, 'closed' => false];
                }
                // Start new subpath
                $ep = $seg->endPoint();
                $currentX = $ep[0];
                $currentY = $ep[1];
                $currentVertices = [[$currentX, $currentY]];
                $inSubpath = true;
            } elseif ($seg instanceof ClosePath) {
                if ($inSubpath && count($currentVertices) >= 2) {
                    $subpaths[] = ['vertices' => $currentVertices, 'closed' => true];
                }
                $ep = $seg->endPoint();
                $currentX = $ep[0];
                $currentY = $ep[1];
                $currentVertices = [];
                $inSubpath = false;
            } else {
                // Drawing segment: flatten and append vertices
                if (!$inSubpath) {
                    // Re-open an implicit subpath starting at the current point
                    $currentVertices = [[$currentX, $currentY]];
                    $inSubpath = true;
                }
                $vertices = $seg->flatten($currentX, $currentY, $tolerance);
                foreach ($vertices as $v) {
                    $currentVertices[] = $v;
                }
                $ep = $seg->endPoint();
                $currentX = $ep[0];
                $currentY = $ep[1];
            }
        }

        // Handle trailing open subpath
        if ($inSubpath && count($currentVertices) >= 2) {
            $subpaths[] = ['vertices' => $currentVertices, 'closed' => false];
        }

        return $subpaths;
    }

    public static function line(float $x1, float $y1, float $x2, float $y2): self
    {
        return (new self())
            ->moveTo($x1, $y1)
            ->lineTo($x2, $y2);
    }

    /**
     * @param array<int, array{float, float}> $points
     */
    public static function polyline(array $points): self
    {
        if (count($points) < 2) {
            throw new \InvalidArgumentException('polyline requires at least 2 points');
        }
        $path = new self();
        $path->moveTo($points[0][0], $points[0][1]);
        for ($i = 1; $i < count($points); $i++) {
            $path->lineTo($points[$i][0], $points[$i][1]);
        }
        return $path;
    }

    /**
     * @param array<int, array{float, float}> $points
     */
    public static function polygon(array $points): self
    {
        if (count($points) < 2) {
            throw new \InvalidArgumentException('polygon requires at least 2 points');
        }
        $path = new self();
        $path->moveTo($points[0][0], $points[0][1]);
        for ($i = 1; $i < count($points); $i++) {
            $path->lineTo($points[$i][0], $points[$i][1]);
        }
        $path->closePath();
        return $path;
    }

    public static function circle(float $cx, float $cy, float $r): self
    {
        return self::ellipse($cx, $cy, $r, $r);
    }

    public static function ellipse(float $cx, float $cy, float $rx, float $ry): self
    {
        $path = new self();
        $path->moveTo($cx + $rx, $cy);
        $path->arcTo($rx, $ry, 0, false, true, $cx, $cy + $ry);
        $path->arcTo($rx, $ry, 0, false, true, $cx - $rx, $cy);
        $path->arcTo($rx, $ry, 0, false, true, $cx, $cy - $ry);
        $path->arcTo($rx, $ry, 0, false, true, $cx + $rx, $cy);
        $path->closePath();
        return $path;
    }

    public static function rect(float $x, float $y, float $w, float $h, float $rx = 0, float $ry = 0): self
    {
        $path = new self();

        if ($rx > 0 || $ry > 0) {
            $maxRx = $w / 2;
            $maxRy = $h / 2;
            if ($rx <= 0) {
                $rx = $ry;
            }
            if ($ry <= 0) {
                $ry = $rx;
            }
            $rx = min($rx, $maxRx);
            $ry = min($ry, $maxRy);

            $path->moveTo($x + $rx, $y);
            $path->lineTo($x + $w - $rx, $y);
            $path->arcTo($rx, $ry, 0, false, true, $x + $w, $y + $ry);
            $path->lineTo($x + $w, $y + $h - $ry);
            $path->arcTo($rx, $ry, 0, false, true, $x + $w - $rx, $y + $h);
            $path->lineTo($x + $rx, $y + $h);
            $path->arcTo($rx, $ry, 0, false, true, $x, $y + $h - $ry);
            $path->lineTo($x, $y + $ry);
            $path->arcTo($rx, $ry, 0, false, true, $x + $rx, $y);
        } else {
            $path->moveTo($x, $y);
            $path->lineTo($x + $w, $y);
            $path->lineTo($x + $w, $y + $h);
            $path->lineTo($x, $y + $h);
        }

        $path->closePath();
        return $path;
    }

    public function setTransform(?Transform $t): self
    {
        $this->transform = $t;
        return $this;
    }

    public function getTransform(): ?Transform
    {
        return $this->transform;
    }

    private function ensureCurrentPoint(): void
    {
        if (!$this->hasCurrentPoint) {
            $this->moveTo(0.0, 0.0);
        }
    }
}
