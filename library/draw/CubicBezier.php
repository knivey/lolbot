<?php
namespace draw;

class CubicBezier implements PathSegment
{
    public function __construct(
        private float $c1x,
        private float $c1y,
        private float $c2x,
        private float $c2y,
        private float $x,
        private float $y
    ) {
    }

    public function flatten(float $startX, float $startY, float $tolerance): array
    {
        $result = [];
        $this->flattenRecursive(
            $startX, $startY,
            $this->c1x, $this->c1y,
            $this->c2x, $this->c2y,
            $this->x, $this->y,
            $tolerance, 0, $result
        );
        return $result;
    }

    /** @return array{float, float} */
    public function endPoint(): array
    {
        return [$this->x, $this->y];
    }

    /**
     * @param array<int, array{float, float}> $result
     */
    private function flattenRecursive(
        float $p0x, float $p0y,
        float $p1x, float $p1y,
        float $p2x, float $p2y,
        float $p3x, float $p3y,
        float $tolerance,
        int $depth,
        array &$result
    ): void {
        if ($depth > 20) {
            $result[] = [$p3x, $p3y];
            return;
        }

        // Flatness: perpendicular distances from P1 and P2 to line P0→P3
        $dx = $p3x - $p0x;
        $dy = $p3y - $p0y;
        $len2 = $dx * $dx + $dy * $dy;

        if ($len2 > 0.0) {
            $d1 = abs(($p1x - $p0x) * $dy - ($p1y - $p0y) * $dx) / sqrt($len2);
            $d2 = abs(($p2x - $p0x) * $dy - ($p2y - $p0y) * $dx) / sqrt($len2);
        } else {
            $d1 = sqrt(($p1x - $p0x) ** 2 + ($p1y - $p0y) ** 2);
            $d2 = sqrt(($p2x - $p0x) ** 2 + ($p2y - $p0y) ** 2);
        }

        if ($d1 <= $tolerance && $d2 <= $tolerance) {
            $result[] = [$p3x, $p3y];
            return;
        }

        // De Casteljau subdivision at t=0.5
        $q0x = ($p0x + $p1x) / 2;
        $q0y = ($p0y + $p1y) / 2;
        $q1x = ($p1x + $p2x) / 2;
        $q1y = ($p1y + $p2y) / 2;
        $q2x = ($p2x + $p3x) / 2;
        $q2y = ($p2y + $p3y) / 2;
        $r0x = ($q0x + $q1x) / 2;
        $r0y = ($q0y + $q1y) / 2;
        $r1x = ($q1x + $q2x) / 2;
        $r1y = ($q1y + $q2y) / 2;
        $sx = ($r0x + $r1x) / 2;
        $sy = ($r0y + $r1y) / 2;

        $this->flattenRecursive($p0x, $p0y, $q0x, $q0y, $r0x, $r0y, $sx, $sy, $tolerance, $depth + 1, $result);
        $this->flattenRecursive($sx, $sy, $r1x, $r1y, $q2x, $q2y, $p3x, $p3y, $tolerance, $depth + 1, $result);
    }
}
