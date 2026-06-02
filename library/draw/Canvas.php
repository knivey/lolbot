<?php
namespace draw;

class Canvas
{
    /**
     * Indexed by [Y][X]
     *
     * @var Pixel[][]
     */
    public array $data = [];

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
                $new->data[$y][$x] = new Pixel();
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
            for ($row = 0; $row < count($this->data); $row += 2) {
                $fg = null;
                $bg = null;
                $hb = "▀";
                for ($col = 0; $col < $this->w; $col++) {
                    $pixel1 = $this->data[$row][$col];
                    if (isset($this->data[$row + 1])) {
                        $pixel2 = $this->data[$row + 1][$col];
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
            foreach ($this->data as $y) {
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

    public function drawPoint(int $x, int $y, Color $color, string $text = ''): void
    {
        if (isset($this->data[$y][$x])) {
            $this->data[$y][$x]->fg = $color->fg;
            $this->data[$y][$x]->bg = $color->bg;
            if ($text != '') {
                $this->data[$y][$x]->text = $text;
            }
        }
    }

    public function fillColor(int $x, int $y, Color $color, string $text = ''): void
    {
        if (!isset($this->data[$y][$x])) {
            return;
        }
        $replaceColor = new Color($this->data[$y][$x]->fg, $this->data[$y][$x]->bg);
        if ($replaceColor->equals($color)) {
            return;
        }
        $stack = [[$y, $x]];
        while (count($stack) != 0) {
            [$curY, $curX] = array_shift($stack);
            $curColor = new Color($this->data[$curY][$curX]->fg, $this->data[$curY][$curX]->bg);
            if ($curColor->equals($replaceColor)) {
                $this->data[$curY][$curX]->fg = $color->fg;
                $this->data[$curY][$curX]->bg = $color->bg;
                $nexts = [[0,-1],[0,1],[-1,0],[1,0]];
                foreach ($nexts as [$ny, $nx]) {
                    $nx += $curX;
                    $ny += $curY;
                    if (isset($this->data[$ny][$nx])) {
                        $testColor = new Color($this->data[$ny][$nx]->fg, $this->data[$ny][$nx]->bg);
                        if ($replaceColor->equals($testColor)) {
                            $stack[] = [$ny, $nx];
                        }
                    }
                }
            }
        }
    }

    public function drawLine(int $startX, int $startY, int $endX, int $endY, Color $color, string $text = ''): void
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

    public function drawFilledEllipse(int $centerX, int $centerY, int|float $width, int|float $height, Color $color, string $text = ''): void
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
    public function drawEllipse(int $centerX, int $centerY, int|float $width, int|float $height, Color $color, string $text = '', int $segments = 100, float $rotate = 0): void
    {
        //want radius amts
        $width = $width / 2;
        $height = $height / 2;
        $dtheta = pi() * 2 / $segments;
        $theta = 0;
        $rotate = deg2rad($rotate);
        $lx = 0;
        $ly = 0;
        for ($i = 0; $i <= $segments; $i++) {
            $x = $centerX + $width * sin($theta + $rotate);
            $y = $centerY + $height * cos($theta + $rotate);
            $theta += $dtheta;
            if ($i != 0) { // dont want to draw from 0,0
                $this->drawLine((int)round($lx), (int)round($ly), (int)round($x), (int)round($y), $color, $text);
            }
            $lx = $x;
            $ly = $y;
        }
    }

    //for now force to be same size, can add another function for copying rects later
    public function overlay(Canvas $art): void
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
        foreach ($art->data as $col) {
            $x = 0;
            foreach ($col as $p) {
                if ($p->fg != null || $p->bg != null) {
                    $this->data[$y][$x] = $p;
                }
                $x++;
            }
            $y++;
        }
    }

    /**
     * Draw a closed polygon with optional fill and outline.
     *
     * Fill is applied first via scanline conversion using the non-zero winding
     * rule; outline is drawn on top via drawLine so it cleanly covers the fill
     * boundary. The polygon is implicitly closed (last vertex connects to first).
     *
     * @param array<int, array{0: int|float, 1: int|float}> $points [[$x, $y], ...]
     */
    public function drawPolygon(
        array $points,
        ?Color $fillColor,
        ?Color $outlineColor,
        string $text = ''
    ): void {
        if (count($points) < 3) {
            return;
        }
        if ($fillColor === null && $outlineColor === null) {
            return;
        }
        if ($fillColor !== null) {
            $this->fillPolygonScanline($points, $fillColor, $text);
        }

        if ($outlineColor !== null) {
            $firstX = null;
            $firstY = null;
            $prevX = null;
            $prevY = null;
            foreach ($points as $point) {
                $x = (int) round($point[0]);
                $y = (int) round($point[1]);
                if ($firstX === null) {
                    $firstX = $x;
                    $firstY = $y;
                } else {
                    $this->drawLine($prevX, $prevY, $x, $y, $outlineColor, $text);
                }
                $prevX = $x;
                $prevY = $y;
            }
            // Close the polygon: last vertex back to first.
            if ($prevX !== $firstX || $prevY !== $firstY) {
                $this->drawLine($prevX, $prevY, $firstX, $firstY, $outlineColor, $text);
            }
        }
    }

    /**
     * Fill the interior of a polygon using scanline conversion with the
     * non-zero winding rule. Vertices must be in order around the polygon
     * (clockwise or counter-clockwise); the polygon is implicitly closed.
     *
     * Uses the half-open convention `min(y0, y1) <= ySample < max(y0, y1)`
     * (where ySample = Y + 0.5) so that horizontal edges and vertices exactly
     * on a scanline are handled uniformly without double-counting. Pixels are
     * sampled at their centers (pixel-center rasterization).
     *
     * @param array<int, array{0: int|float, 1: int|float}> $points
     */
    private function fillPolygonScanline(array $points, Color $color, string $text): void
    {
        $n = count($points);

        // Compute integer bounding box of scanlines to visit.
        $minY = $points[0][1];
        $maxY = $points[0][1];
        for ($i = 1; $i < $n; $i++) {
            if ($points[$i][1] < $minY) {
                $minY = $points[$i][1];
            }
            if ($points[$i][1] > $maxY) {
                $maxY = $points[$i][1];
            }
        }
        $yStart = (int) floor($minY);
        $yEnd = (int) ceil($maxY);

        for ($Y = $yStart; $Y <= $yEnd; $Y++) {
            $ySample = $Y + 0.5;
            // Collect (xIntersection, windingDirection) for every edge
            // crossing this scanline under the half-open convention.
            $intersections = [];
            for ($i = 0; $i < $n; $i++) {
                $x1 = $points[$i][0];
                $y1 = $points[$i][1];
                $x2 = $points[($i + 1) % $n][0];
                $y2 = $points[($i + 1) % $n][1];

                $yLo = $y1 < $y2 ? $y1 : $y2;
                $yHi = $y1 < $y2 ? $y2 : $y1;

                // Half-open: include edge iff yLo <= ySample < yHi.
                if ($ySample < $yLo || $ySample >= $yHi) {
                    continue;
                }

                // x at scanline ySample by linear interpolation along the edge.
                $xInt = $x1 + ($x2 - $x1) * ($ySample - $y1) / ($y2 - $y1);
                $dir = ($y2 > $y1) ? 1 : -1;
                $intersections[] = [$xInt, $dir];
            }

            // Sort by x so we can walk left-to-right.
            usort($intersections, fn ($a, $b) => $a[0] <=> $b[0]);

            // Walk intersections, tracking running winding count.
            // A fill span opens when winding becomes non-zero and closes
            // when it returns to zero.
            $winding = 0;
            $spanStart = null;
            foreach ($intersections as [$xInt, $dir]) {
                $prevWinding = $winding;
                $winding += $dir;
                if ($prevWinding === 0 && $winding !== 0) {
                    $spanStart = $xInt;
                } elseif ($prevWinding !== 0 && $winding === 0) {
                    $spanEnd = $xInt;
                    if ($spanStart !== null) {
                        $xL = (int) ceil($spanStart - 0.5);
                        $xR = (int) floor($spanEnd - 0.5);
                        for ($xx = $xL; $xx <= $xR; $xx++) {
                            $this->drawPoint($xx, $Y, $color, $text);
                        }
                    }
                    $spanStart = null;
                }
            }
        }
    }
}
