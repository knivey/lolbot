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
