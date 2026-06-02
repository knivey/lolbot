<?php
namespace draw;

class Transform
{
    private function __construct(
        private readonly float $a,
        private readonly float $b,
        private readonly float $c,
        private readonly float $d,
        private readonly float $e,
        private readonly float $f,
    ) {
    }

    public static function identity(): self
    {
        return new self(1.0, 0.0, 0.0, 1.0, 0.0, 0.0);
    }

    public static function translate(float $tx, float $ty): self
    {
        return new self(1.0, 0.0, 0.0, 1.0, $tx, $ty);
    }

    public static function scale(float $sx, ?float $sy = null): self
    {
        $sy ??= $sx;
        return new self($sx, 0.0, 0.0, $sy, 0.0, 0.0);
    }

    public static function rotate(float $angle, float $cx = 0.0, float $cy = 0.0): self
    {
        $cos = cos($angle);
        $sin = sin($angle);
        if ($cx == 0.0 && $cy == 0.0) {
            return new self($cos, $sin, -$sin, $cos, 0.0, 0.0);
        }
        $tx = $cx - $cos * $cx + $sin * $cy;
        $ty = $cy - $sin * $cx - $cos * $cy;
        return new self($cos, $sin, -$sin, $cos, $tx, $ty);
    }

    /**
     * @return array{float, float, float, float, float, float}
     */
    public function getElements(): array
    {
        return [$this->a, $this->b, $this->c, $this->d, $this->e, $this->f];
    }

    /**
     * @return array{float, float}
     */
    public function apply(float $x, float $y): array
    {
        return [
            $this->a * $x + $this->c * $y + $this->e,
            $this->b * $x + $this->d * $y + $this->f,
        ];
    }
}
