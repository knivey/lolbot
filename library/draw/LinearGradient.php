<?php

namespace draw;

class LinearGradient implements Paint
{
    /** @var ColorStop[] */
    public readonly array $stops;

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
    ) {
        if (count($stops) < 2) {
            throw new \InvalidArgumentException('LinearGradient requires at least 2 stops');
        }
        usort($stops, fn(ColorStop $a, ColorStop $b) => $a->offset <=> $b->offset);
        $this->stops = $stops;
    }

    public function isSolid(): bool
    {
        return false;
    }

    public function getColorAt(float $x, float $y): array
    {
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
