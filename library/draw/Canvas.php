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

    private Transform $ctm;
    /** @var array<int, Transform> */
    private array $transformStack = [];

    private function __construct(readonly public bool $halfblocks = false)
    {
        $this->ctm = Transform::identity();
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

    public function save(): void
    {
        $this->transformStack[] = $this->ctm;
    }

    public function restore(): void
    {
        if (count($this->transformStack) === 0) {
            throw new \LogicException('Cannot restore: transform stack is empty');
        }
        $this->ctm = array_pop($this->transformStack);
    }

    public function getTransform(): Transform
    {
        return $this->ctm;
    }

    public function setTransform(Transform $t): void
    {
        $this->ctm = $t;
    }

    public function concatTransform(Transform $t): void
    {
        $this->ctm = $this->ctm->multiply($t);
    }

    public function translate(float $tx, float $ty): void
    {
        $this->concatTransform(Transform::translate($tx, $ty));
    }

    public function rotate(float $angle, float $cx = 0.0, float $cy = 0.0): void
    {
        $this->concatTransform(Transform::rotate($angle, $cx, $cy));
    }

    public function scale(float $sx, ?float $sy = null): void
    {
        $this->concatTransform(Transform::scale($sx, $sy));
    }

    public function skewX(float $angle): void
    {
        $this->concatTransform(Transform::skewX($angle));
    }

    public function skewY(float $angle): void
    {
        $this->concatTransform(Transform::skewY($angle));
    }

    public function drawPoint(int|float $x, int|float $y, Color $color, string $text = ''): void
    {
        if (!$this->isIdentity($this->ctm)) {
            [$x, $y] = $this->ctm->apply((float) $x, (float) $y);
        }
        $x = (int) round($x);
        $y = (int) round($y);
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


    private function isIdentity(Transform $t): bool
    {
        $e = $t->getElements();
        return $e[0] === 1.0 && $e[1] === 0.0 && $e[2] === 0.0
            && $e[3] === 1.0 && $e[4] === 0.0 && $e[5] === 0.0;
    }

    private function drawLineInternal(int $startX, int $startY, int $endX, int $endY, Color $color, string $text = ''): void
    {
        $dx = abs($endX - $startX);
        $dy = abs($endY - $startY);
        $sx = ($startX < $endX ? 1 : -1);
        $sy = ($startY < $endY ? 1 : -1);
        $error = ($dx > $dy ? $dx : - $dy) / 2;
        $x = $startX;
        $y = $startY;
        $cnt = 0;
        while ($cnt++ < 1000) {
            if (isset($this->data[$y][$x])) {
                $this->data[$y][$x]->fg = $color->fg;
                $this->data[$y][$x]->bg = $color->bg;
                if ($text != '') {
                    $this->data[$y][$x]->text = $text;
                }
            }
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

    /**
     * Fill the interior of a polygon using scanline conversion with the
     * non-zero winding rule. Vertices must be in order around the polygon
     * (clockwise or counter-clockwise); the polygon is implicitly closed.
     *
     * Uses the half-open convention `min(y0, y1) <= Y < max(y0, y1)` so that
     * horizontal edges and vertices exactly on a scanline are handled
     * uniformly without double-counting. Uses top-left pixel sampling:
    /**
     * @param Path $path The path to render.
     * @param ?Color $fillColor Fill color, or null for no fill.
     * @param ?Color $outlineColor Outline color, or null for no outline.
     * @param string $text Optional text for rendered pixels.
     */
    public function drawPath(
        Path $path,
        ?Color $fillColor,
        ?Color $outlineColor,
        string $text = '',
        FillRule $fillRule = FillRule::NonZero
    ): void {
        $subpaths = $path->flatten();
        if (count($subpaths) === 0) {
            return;
        }
        if ($fillColor === null && $outlineColor === null) {
            return;
        }

        $effective = $this->ctm;
        $pathTransform = $path->getTransform();
        if ($pathTransform !== null) {
            $effective = $effective->multiply($pathTransform);
        }

        $needTransform = !$this->isIdentity($effective);

        $snappedSubpaths = [];
        foreach ($subpaths as $sp) {
            $snapped = [];
            foreach ($sp['vertices'] as $v) {
                if ($needTransform) {
                    $v = $effective->apply($v[0], $v[1]);
                }
                $snapped[] = [(int) round($v[0]), (int) round($v[1])];
            }
            $snappedSubpaths[] = ['vertices' => $snapped, 'closed' => $sp['closed']];
        }

        if ($fillColor !== null) {
            $polygonArrays = [];
            foreach ($snappedSubpaths as $sp) {
                if (count($sp['vertices']) >= 3) {
                    $polygonArrays[] = $sp['vertices'];
                }
            }
            if (count($polygonArrays) > 0) {
                $this->fillPolygonScanlineMulti($polygonArrays, $fillColor, $text, $fillRule);
            }
        }

        if ($outlineColor !== null) {
            foreach ($snappedSubpaths as $sp) {
                $vertices = $sp['vertices'];
                $n = count($vertices);
                if ($sp['closed'] && $n < 3) {
                    continue;
                }
                if ($n < 2) {
                    continue;
                }
                for ($i = 1; $i < $n; $i++) {
                    $this->drawLineInternal(
                        $vertices[$i - 1][0],
                        $vertices[$i - 1][1],
                        $vertices[$i][0],
                        $vertices[$i][1],
                        $outlineColor,
                        $text
                    );
                }
                if ($sp['closed']) {
                    $this->drawLineInternal(
                        $vertices[$n - 1][0],
                        $vertices[$n - 1][1],
                        $vertices[0][0],
                        $vertices[0][1],
                        $outlineColor,
                        $text
                    );
                }
            }
        }
    }

    /**
     * Fill the interior of multiple subpaths using scanline conversion with the
     * non-zero winding rule. All subpaths contribute edges to the intersection
     * list, so overlapping or nested subpaths interact correctly (e.g., a
     * clockwise outer + counter-clockwise inner creates a hole).
     *
     * Uses the same half-open / top-left convention as fillPolygonScanline.
     *
     * @param array<int, array<int, array{int, int}>> $subpaths
     */
    private function fillPolygonScanlineMulti(array $subpaths, Color $color, string $text, FillRule $fillRule): void
    {
        if (count($subpaths) === 0) {
            return;
        }

        // Compute bounding box across all subpaths
        $minY = PHP_INT_MAX;
        $maxY = PHP_INT_MIN;
        foreach ($subpaths as $polygon) {
            $n = count($polygon);
            for ($i = 0; $i < $n; $i++) {
                if ($polygon[$i][1] < $minY) {
                    $minY = $polygon[$i][1];
                }
                if ($polygon[$i][1] > $maxY) {
                    $maxY = $polygon[$i][1];
                }
            }
        }

        for ($Y = $minY; $Y <= $maxY; $Y++) {
            $intersections = [];

            // Collect (xIntersection, windingDirection) for every edge
            // crossing this scanline under the half-open convention.
            foreach ($subpaths as $polygon) {
                $n = count($polygon);
                for ($i = 0; $i < $n; $i++) {
                    $x1 = $polygon[$i][0];
                    $y1 = $polygon[$i][1];
                    $x2 = $polygon[($i + 1) % $n][0];
                    $y2 = $polygon[($i + 1) % $n][1];

                    $yLo = $y1 < $y2 ? $y1 : $y2;
                    $yHi = $y1 < $y2 ? $y2 : $y1;

                    // Half-open: include edge iff yLo <= Y < yHi.
                    if ($Y < $yLo || $Y >= $yHi) {
                        continue;
                    }

                    // x at scanline Y by linear interpolation along the edge.
                    $xInt = $x1 + ($x2 - $x1) * ($Y - $y1) / ($y2 - $y1);
                    $dir = ($y2 > $y1) ? 1 : -1;
                    $intersections[] = [$xInt, $dir];
                }
            }

            // Sort by x so we can walk left-to-right.
            usort($intersections, fn ($a, $b) => $a[0] <=> $b[0]);

            if ($fillRule === FillRule::EvenOdd) {
                $inside = false;
                $spanStart = null;
                foreach ($intersections as [$xInt]) {
                    $inside = !$inside;
                    if ($inside) {
                        $spanStart = $xInt;
                    } else {
                        if ($spanStart !== null) {
                            $xL = (int) ceil($spanStart);
                            $xR = (int) floor($xInt);
                            for ($xx = $xL; $xx <= $xR; $xx++) {
                                if (isset($this->data[$Y][$xx])) {
                                    $this->data[$Y][$xx]->fg = $color->fg;
                                    $this->data[$Y][$xx]->bg = $color->bg;
                                    if ($text != '') {
                                        $this->data[$Y][$xx]->text = $text;
                                    }
                                }
                            }
                        }
                        $spanStart = null;
                    }
                }
            } else {
                $winding = 0;
                $spanStart = null;
                foreach ($intersections as [$xInt, $dir]) {
                    $prevWinding = $winding;
                    $winding += $dir;
                    if ($prevWinding === 0 && $winding !== 0) {
                        $spanStart = $xInt;
                    } elseif ($prevWinding !== 0 && $winding === 0) {
                        if ($spanStart !== null) {
                            $xL = (int) ceil($spanStart);
                            $xR = (int) floor($xInt);
                            for ($xx = $xL; $xx <= $xR; $xx++) {
                                if (isset($this->data[$Y][$xx])) {
                                    $this->data[$Y][$xx]->fg = $color->fg;
                                    $this->data[$Y][$xx]->bg = $color->bg;
                                    if ($text != '') {
                                        $this->data[$Y][$xx]->text = $text;
                                    }
                                }
                            }
                        }
                        $spanStart = null;
                    }
                }
            }
        }
    }
}
