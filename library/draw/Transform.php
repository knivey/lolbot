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

    public static function skewX(float $angle): self
    {
        return new self(1.0, 0.0, tan($angle), 1.0, 0.0, 0.0);
    }

    public static function skewY(float $angle): self
    {
        return new self(1.0, tan($angle), 0.0, 1.0, 0.0, 0.0);
    }

    public static function matrix(float $a, float $b, float $c, float $d, float $e, float $f): self
    {
        return new self($a, $b, $c, $d, $e, $f);
    }

    public function equals(Transform $other): bool
    {
        return abs($this->a - $other->a) < 1e-10
            && abs($this->b - $other->b) < 1e-10
            && abs($this->c - $other->c) < 1e-10
            && abs($this->d - $other->d) < 1e-10
            && abs($this->e - $other->e) < 1e-10
            && abs($this->f - $other->f) < 1e-10;
    }

    public function multiply(Transform $other): self
    {
        return new self(
            $this->a * $other->a + $this->c * $other->b,
            $this->b * $other->a + $this->d * $other->b,
            $this->a * $other->c + $this->c * $other->d,
            $this->b * $other->c + $this->d * $other->d,
            $this->a * $other->e + $this->c * $other->f + $this->e,
            $this->b * $other->e + $this->d * $other->f + $this->f,
        );
    }

    /**
     * @return array{float, float, float, float, float, float}
     */
    public function getElements(): array
    {
        return [$this->a, $this->b, $this->c, $this->d, $this->e, $this->f];
    }

    public function inverse(): self
    {
        $det = $this->a * $this->d - $this->b * $this->c;
        if (abs($det) < 1e-15) {
            throw new \LogicException('Cannot invert singular transform matrix');
        }
        return new self(
            $this->d / $det,
            -$this->b / $det,
            -$this->c / $det,
            $this->a / $det,
            ($this->c * $this->f - $this->d * $this->e) / $det,
            ($this->b * $this->e - $this->a * $this->f) / $det,
        );
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
