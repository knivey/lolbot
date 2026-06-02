<?php
namespace draw;

class QuadraticBezier implements PathSegment
{
    public function __construct(
        private float $cpx,
        private float $cpy,
        private float $x,
        private float $y
    ) {
    }

    public function flatten(float $startX, float $startY, float $tolerance): array
    {
        $result = [];
        $this->flattenRecursive(
            $startX, $startY,
            $this->cpx, $this->cpy,
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
        float $tolerance,
        int $depth,
        array &$result
    ): void {
        if ($depth > 20) {
            $result[] = [$p2x, $p2y];
            return;
        }

        // Flatness: perpendicular distance from P1 to line P0→P2
        $dx = $p2x - $p0x;
        $dy = $p2y - $p0y;
        $len2 = $dx * $dx + $dy * $dy;

        if ($len2 > 0.0) {
            $d = abs(($p1x - $p0x) * $dy - ($p1y - $p0y) * $dx) / sqrt($len2);
        } else {
            $d = sqrt(($p1x - $p0x) ** 2 + ($p1y - $p0y) ** 2);
        }

        if ($d <= $tolerance) {
            $result[] = [$p2x, $p2y];
            return;
        }

        // De Casteljau subdivision at t=0.5
        $q0x = ($p0x + $p1x) / 2;
        $q0y = ($p0y + $p1y) / 2;
        $q1x = ($p1x + $p2x) / 2;
        $q1y = ($p1y + $p2y) / 2;
        $sx = ($q0x + $q1x) / 2;
        $sy = ($q0y + $q1y) / 2;

        $this->flattenRecursive($p0x, $p0y, $q0x, $q0y, $sx, $sy, $tolerance, $depth + 1, $result);
        $this->flattenRecursive($sx, $sy, $q1x, $q1y, $p2x, $p2y, $tolerance, $depth + 1, $result);
    }
}
