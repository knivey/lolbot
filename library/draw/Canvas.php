<?php
namespace draw;

class Canvas
{
    /**
     * Indexed by [Y][X]
     *
     * @var Pixel[][]
     */
    public array $canvas = [];

    public int $w = 0;
    public int $h = 0;

    private function __construct(readonly public bool $halfblocks = false)
    {
    }

    /**
     * New blank art
     *
     * @param  int $w width
     * @param  int $h height
     * @return Canvas
     */
    public static function createBlank(int $w, int $h, bool $halfblocks = false): Canvas
    {
        $new = new self($halfblocks);
        //lol all pixels were same instance
        //$new->canvas = array_fill(0, $h, array_fill(0, $w, new Pixel()));
        for ($y = 0;$y < $h;$y++) {
            for ($x = 0;$x < $w;$x++) {
                $new->canvas[$y][$x] = new Pixel();
            }
        }
        $new->w = $w;
        $new->h = $h;
        return $new;
    }

    /**
     * Create from existing art
     *
     * @param  string $artText contents of an art file
     * @return Canvas
     */
    public static function createFromArt(string $artText): Canvas
    {
        //TODO implement
        $new = new self();
        return $new;
    }

    public function __toString(): string
    {
        $out = '';
        if ($this->halfblocks) {
            for ($row = 0; $row < count($this->canvas); $row += 2) {
                $fg = null;
                $bg = null;
                $hb = "▀";
                for ($col = 0; $col < $this->w; $col++) {
                    $pixel1 = $this->canvas[$row][$col];
                    if (isset($this->canvas[$row + 1])) {
                        $pixel2 = $this->canvas[$row + 1][$col];
                    } else {
                        $pixel2 = new Pixel();
                    }
                    if (($pixel1->fg === null && $fg !== null) || ($pixel2->fg === null && $bg !== null)) {
                        $out .= "\x03";
                        $fg = null;
                        $bg = null;
                    }
                    if ($pixel1->fg === null && $pixel2->fg === null) {
                        $out .= " ";
                        continue;
                    }
                    if ($pixel1->fg !== $fg || $pixel2->fg !== $bg) {
                        if ($pixel1->fg === $pixel2->fg && $pixel2->fg === $bg) {
                            $out .= " ";
                            continue;
                        }

                        if ($bg === $pixel2->fg) {
                            if ($pixel1->fg === null) {
                                $out .= "\x03,$pixel2->fg";
                            } else {
                                $out .= "\x03$pixel1->fg";
                            }
                        } elseif ($pixel2->fg !== null) {
                            $out .= "\x03$pixel1->fg,$pixel2->fg";
                        } else {
                            $out .= "\x03$pixel1->fg";
                        }
                        $fg = $pixel1->fg;
                        $bg = $pixel2->fg;
                    }
                    if ($pixel1->fg !== $pixel2->fg) {
                        $out .= $hb;
                    } else {
                        $out .= " ";
                    }
                }
                $out .= "\n";
            }
        } else {
            foreach ($this->canvas as $y) {
                $fg = null;
                $bg = null;
                foreach ($y as $p) {
                    $code = '';
                    if ($p->fg !== $fg) {
                        $fg = $p->fg;
                        if ($p->fg === null) {
                            $code = "99";
                        } else {
                            if ($fg < 10) {
                                $code = "0$fg";
                            } else {
                                $code = $fg;
                            }
                        }
                    }
                    // some clients dont like empty fg
                    if ($code == '' && $p->bg !== $bg) {
                        if ($fg === null) {
                            $code = "99";
                        } else {
                            $code = $fg;
                        }
                    }

                    if ($p->bg !== $bg) {
                        $bg = $p->bg;
                        if ($p->bg === null) {
                            $code .= ",99";
                        } else {
                            if ($bg < 10) {
                                $code .= ",0$bg";
                            } else {
                                $code .= ",$bg";
                            }
                        }
                    }
                    if ($code != "") {
                        if ($code == "99,99") {
                            $out .= "\x03";
                        } else {
                            $out .= "\x03$code";
                        }
                    }
                    $out .= $p;
                }
                $out .= "\n";
            }
        }
        return $out;
    }

    public function drawPoint(int $x, int $y, Color $color, string $text = '')
    {
        if (isset($this->canvas[$y][$x])) {
            $this->canvas[$y][$x]->fg = $color->fg;
            $this->canvas[$y][$x]->bg = $color->bg;
            if ($text != '') {
                $this->canvas[$y][$x]->text = $text;
            }
        }
    }

    public function fillColor(int $x, int $y, Color $color, string $text = '')
    {
        if (!isset($this->canvas[$y][$x])) {
            return;
        }
        $replaceColor = new Color($this->canvas[$y][$x]->fg, $this->canvas[$y][$x]->bg);
        if ($replaceColor->equals($color)) {
            return;
        }
        $stack = [[$y, $x]];
        while (count($stack) != 0) {
            [$curY, $curX] = array_shift($stack);
            $curColor = new Color($this->canvas[$curY][$curX]->fg, $this->canvas[$curY][$curX]->bg);
            if ($curColor->equals($replaceColor)) {
                $this->canvas[$curY][$curX]->fg = $color->fg;
                $this->canvas[$curY][$curX]->bg = $color->bg;
                $nexts = [[0,-1],[0,1],[-1,0],[1,0]];
                foreach ($nexts as [$ny, $nx]) {
                    $nx += $curX;
                    $ny += $curY;
                    if (isset($this->canvas[$ny][$nx])) {
                        $testColor = new Color($this->canvas[$ny][$nx]->fg, $this->canvas[$ny][$nx]->bg);
                        if ($replaceColor->equals($testColor)) {
                            $stack[] = [$ny, $nx];
                        }
                    }
                }
            }
        }
    }

    public function drawLine(int $startX, int $startY, int $endX, int $endY, Color $color, string $text = '')
    {
        $dx = abs($endX - $startX);
        $dy = abs($endY - $startY);
        $sx = ($startX < $endX ? 1 : -1);
        $sy = ($startY < $endY ? 1 : -1);
        $error = ($dx > $dy ? $dx : - $dy) / 2;
        $e2 = 0;
        $x = $startX;
        $y = $startY;
        $cnt = 0;
        while ($cnt++ < 1000) {
            $this->drawPoint($x, $y, $color, $text);
            if ($x == $endX && $y == $endY) {
                break;
            }
            $e2 = $error;
            if ($e2 > -$dx) {
                $error -= $dy;
                $x += $sx;
            }
            if ($e2 < $dy) {
                $error += $dx;
                $y += $sy;
            }
        }
    }

    public function drawFilledEllipse(int $centerX, int $centerY, int|float $width, int|float $height, Color $color, string $text = '')
    {
        for ($y = -$height; $y <= $height; $y++) {
            for ($x = -$width; $x <= $width; $x++) {
                if ($x * $x * $height * $height + $y * $y * $width * $width <= $height * $height * $width * $width) {
                    $this->drawPoint((int)round($centerX + $x), (int)round($centerY + $y), $color, $text);
                }
            }
        }
    }

    //with a low seg number it draws shapes so maybe just add rotation later for shape drawing
    public function drawEllipse(int $centerX, int $centerY, int|float $width, int|float $height, Color $color, string $text = '', int $segments = 100)
    {
        //want radius amts
        $width = $width / 2;
        $height = $height / 2;
        $dtheta = pi() * 2 / $segments;
        $theta = 0;
        $lx = 0;
        $ly = 0;
        for ($i = 0; $i <= $segments; $i++) {
            $x = $centerX + $width * sin($theta);
            $y = $centerY + $height * cos($theta);
            $theta += $dtheta;
            if ($i != 0) { // dont want to draw from 0,0
                $this->drawLine((int)round($lx), (int)round($ly), (int)round($x), (int)round($y), $color, $text);
            }
            $lx = $x;
            $ly = $y;
        }
    }

    //for now force to be same size, can add another function for copying rects later
    public function overlay(Canvas $art)
    {
        if ($art->w != $this->w) {
            echo "art overlay widths mismatch\n";
            return;
        }
        if ($art->h != $this->h) {
            echo "art overlay heights mismatch\n";
            return;
        }
        $y = 0;
        foreach ($art->canvas as $col) {
            $x = 0;
            foreach ($col as $p) {
                if ($p->fg != null || $p->bg != null) {
                    $this->canvas[$y][$x] = $p;
                }
                $x++;
            }
            $y++;
        }
    }
}
