<?php

namespace draw;

trait GradientMath
{
    private function applySpread(float $t): float
    {
        return match ($this->spreadMethod) {
            SpreadMethod::Pad => max(0.0, min(1.0, $t)),
            SpreadMethod::Repeat => $t - floor($t),
            SpreadMethod::Reflect => $this->reflect($t),
        };
    }

    private function reflect(float $t): float
    {
        $t = abs($t);
        $f = floor($t);
        $frac = $t - $f;
        return ((int) $f) % 2 === 1 ? 1.0 - $frac : $frac;
    }

    /**
     * @return array{int, int, int}
     */
    private function interpolateStops(float $t): array
    {
        if ($t <= $this->stops[0]->offset) {
            return [$this->stops[0]->r, $this->stops[0]->g, $this->stops[0]->b];
        }
        $last = count($this->stops) - 1;
        if ($t >= $this->stops[$last]->offset) {
            return [$this->stops[$last]->r, $this->stops[$last]->g, $this->stops[$last]->b];
        }
        for ($i = 0; $i < $last; $i++) {
            $a = $this->stops[$i];
            $b = $this->stops[$i + 1];
            if ($t >= $a->offset && $t < $b->offset) {
                if ($a->offset == $b->offset) {
                    return [$b->r, $b->g, $b->b];
                }
                $localT = ($t - $a->offset) / ($b->offset - $a->offset);
                return [
                    (int) round(max(0, min(255, $a->r + ($b->r - $a->r) * $localT))),
                    (int) round(max(0, min(255, $a->g + ($b->g - $a->g) * $localT))),
                    (int) round(max(0, min(255, $a->b + ($b->b - $a->b) * $localT))),
                ];
            }
        }
        return [$this->stops[$last]->r, $this->stops[$last]->g, $this->stops[$last]->b];
    }
}
