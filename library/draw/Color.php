<?php
namespace draw;

/**
 * Used for drawing ops, useful for gradients
 *
 * @property-read ?int $fg
 * @property-read ?int $bg
 */
class Color
{
    public const White = 0;
    public const Black = 2;
    public const Blue = 3;
    public const Red = 4;
    // Really more a dark red than brown
    public const Brown = 5;
    public const Magenta = 6;
    public const Orange = 7;
    public const Yellow = 8;
    public const LightGreen = 9;
    public const Cyan = 10;
    public const LightCyan = 11;
    public const LightBlue = 12;
    public const Pink = 13;
    public const Grey = 14;
    public const LightGrey = 15;
    

    /**
     * Color constructor.
     *
     * @param int|null $fg
     * @param int|null $bg
     */
    public function __construct(
        private ?int $fg = null,
        private ?int $bg = null
    ) {
    }

    public function __get(string $name): mixed
    {
        if ($name == 'fg') {
            return $this->fg;
        }
        if ($name == 'bg') {
            return $this->bg;
        }
        $trace = debug_backtrace();
        trigger_error(
            'Undefined property via __get(): ' . $name .
            ' in ' . $trace[0]['file'] .
            ' on line ' . $trace[0]['line'],
            E_USER_NOTICE
        );
        return false;
    }

    public function equals(Color $color): bool
    {
        if ($this->fg === $color->fg && $this->bg === $color->bg) {
            return true;
        }
        return false;
    }

    //thinking this can be like an array of colors with a step size?
    public function setGradiant(array $colors)
    {

    }

    public function advanceGradiant()
    {

    }
}
