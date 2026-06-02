<?php
namespace draw;

class EllipticalArc implements PathSegment
{
    public function __construct(
        private float $rx,
        private float $ry,
        private float $xAxisRot,
        private bool $largeArc,
        private bool $sweep,
        private float $x,
        private float $y
    ) {
    }

    public function flatten(float $startX, float $startY, float $tolerance): array
    {
        // Degenerate: start == end (no-op per SVG spec)
        if ($startX == $this->x && $startY == $this->y) {
            return [];
        }

        // Degenerate: zero radii → straight line
        if ($this->rx == 0.0 || $this->ry == 0.0) {
            return [[$this->x, $this->y]];
        }

        $rx = abs($this->rx);
        $ry = abs($this->ry);
        $phi = deg2rad($this->xAxisRot);
        $cosPhi = cos($phi);
        $sinPhi = sin($phi);

        // Step 1: Transform endpoint difference to ellipse coordinate system
        $dx = ($startX - $this->x) / 2.0;
        $dy = ($startY - $this->y) / 2.0;
        $x1p = $cosPhi * $dx + $sinPhi * $dy;
        $y1p = -$sinPhi * $dx + $cosPhi * $dy;

        // Step 2: Ensure radii are large enough
        $lambda = ($x1p * $x1p) / ($rx * $rx) + ($y1p * $y1p) / ($ry * $ry);
        if ($lambda > 1.0) {
            $factor = sqrt($lambda);
            $rx *= $factor;
            $ry *= $factor;
        }

        // Step 3: Compute center in ellipse coordinate system
        $sign = ($this->largeArc === $this->sweep) ? -1.0 : 1.0;
        $num = $rx * $rx * $ry * $ry - $rx * $rx * $y1p * $y1p - $ry * $ry * $x1p * $x1p;
        $den = $rx * $rx * $y1p * $y1p + $ry * $ry * $x1p * $x1p;
        $factor = $sign * sqrt(max(0.0, $num / $den));
        $cxp = $factor * ($rx * $y1p) / $ry;
        $cyp = $factor * -($ry * $x1p) / $rx;

        // Transform center back to original coordinate system
        $cx = $cosPhi * $cxp - $sinPhi * $cyp + ($startX + $this->x) / 2.0;
        $cy = $sinPhi * $cxp + $cosPhi * $cyp + ($startY + $this->y) / 2.0;

        // Step 4: Compute start and sweep angles
        $theta1 = atan2(($y1p - $cyp) / $ry, ($x1p - $cxp) / $rx);
        $dx2 = -$x1p - $cxp;
        $dy2 = -$y1p - $cyp;
        $theta2 = atan2($dy2 / $ry, $dx2 / $rx);

        $dtheta = $theta2 - $theta1;
        if (!$this->sweep && $dtheta > 0.0) {
            $dtheta -= 2.0 * M_PI;
        } elseif ($this->sweep && $dtheta < 0.0) {
            $dtheta += 2.0 * M_PI;
        }

        // Step 5: Split into ≤90° pieces, convert each to cubic Bézier
        $numPieces = max(1, (int) ceil(abs($dtheta) / (M_PI / 2.0)));
        $delta = $dtheta / $numPieces;

        $result = [];
        for ($i = 0; $i < $numPieces; $i++) {
            $a1 = $theta1 + $i * $delta;
            $a2 = $a1 + $delta;
            $k = 4.0 / 3.0 * tan(($a2 - $a1) / 4.0);

            // Points in ellipse parameter space (unit circle coords)
            $u0x = cos($a1); $u0y = sin($a1);
            $u3x = cos($a2); $u3y = sin($a2);
            $u1x = $u0x - $k * sin($a1); $u1y = $u0y + $k * cos($a1);
            $u2x = $u3x + $k * sin($a2); $u2y = $u3y - $k * cos($a2);

            // Scale by radii, rotate by phi, translate by center
            $ex0 = $u0x * $rx; $ey0 = $u0y * $ry;
            $c0x = $cosPhi * $ex0 - $sinPhi * $ey0 + $cx;
            $c0y = $sinPhi * $ex0 + $cosPhi * $ey0 + $cy;

            $ex1 = $u1x * $rx; $ey1 = $u1y * $ry;
            $c1x = $cosPhi * $ex1 - $sinPhi * $ey1 + $cx;
            $c1y = $sinPhi * $ex1 + $cosPhi * $ey1 + $cy;

            $ex2 = $u2x * $rx; $ey2 = $u2y * $ry;
            $c2x = $cosPhi * $ex2 - $sinPhi * $ey2 + $cx;
            $c2y = $sinPhi * $ex2 + $cosPhi * $ey2 + $cy;

            $ex3 = $u3x * $rx; $ey3 = $u3y * $ry;
            $c3x = $cosPhi * $ex3 - $sinPhi * $ey3 + $cx;
            $c3y = $sinPhi * $ex3 + $cosPhi * $ey3 + $cy;

            // Flatten this cubic piece
            $bezier = new CubicBezier($c1x, $c1y, $c2x, $c2y, $c3x, $c3y);
            $pieceVertices = $bezier->flatten($c0x, $c0y, $tolerance);
            foreach ($pieceVertices as $v) {
                $result[] = $v;
            }
        }

        return $result;
    }

    /** @return array{float, float} */
    public function endPoint(): array
    {
        return [$this->x, $this->y];
    }
}
