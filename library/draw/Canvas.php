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
    private Dithering $dithering = Dithering::None;

    public function setDithering(Dithering $mode): void
    {
        $this->dithering = $mode;
    }

    public function getDithering(): Dithering
    {
        return $this->dithering;
    }

    public function getPixel(int $x, int $y): Pixel
    {
        return $this->data[$y][$x] ?? new Pixel();
    }

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
                        if ($pixel1->dithered && $pixel2->dithered) {
                            $avgT = ($pixel1->t + $pixel2->t) / 2.0;
                            if ($avgT < 0.33) {
                                $out .= "░";
                            } elseif ($avgT < 0.66) {
                                $out .= "▒";
                            } else {
                                $out .= "▓";
                            }
                        } else {
                            $out .= $hb;
                        }
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

    public function drawPoint(int|float $x, int|float $y, Paint $paint, string $text = ''): void
    {
        if (!$this->isIdentity($this->ctm)) {
            [$x, $y] = $this->ctm->apply((float) $x, (float) $y);
        }
        $x = (int) round($x);
        $y = (int) round($y);
        if (isset($this->data[$y][$x])) {
            if ($paint->isSolid() && $paint instanceof Color) {
                $this->data[$y][$x]->fg = $paint->fg;
                $this->data[$y][$x]->bg = $paint->bg;
            } else {
                $effectiveDithering = $paint->getDithering() ?? $this->dithering;
                [$r, $g, $b] = $paint->getColorAt((float) $x, (float) $y);
                if ($effectiveDithering !== Dithering::None) {
                    $result = IrcPalette::nearestColorWithMeta($r, $g, $b, $effectiveDithering, $x, $y);
                    $this->data[$y][$x]->fg = $result->code;
                    $this->data[$y][$x]->dithered = $result->dithered;
                    $this->data[$y][$x]->secondBest = $result->secondBest;
                    $this->data[$y][$x]->t = $result->t;
                } else {
                    $this->data[$y][$x]->fg = IrcPalette::nearestColor($r, $g, $b);
                }
                $this->data[$y][$x]->bg = null;
            }
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
            throw new \InvalidArgumentException("art overlay widths mismatch: {$this->w} vs {$art->w}");
        }
        if ($art->h != $this->h) {
            throw new \InvalidArgumentException("art overlay heights mismatch: {$this->h} vs {$art->h}");
        }
        $y = 0;
        foreach ($art->data as $col) {
            $x = 0;
            foreach ($col as $p) {
                if ($p->fg != null || $p->bg != null) {
                    $this->data[$y][$x] = clone $p;
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

    private function drawLineInternal(int $startX, int $startY, int $endX, int $endY, Paint $paint, string $text = ''): void
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
                if ($paint->isSolid() && $paint instanceof Color) {
                    $this->data[$y][$x]->fg = $paint->fg;
                    $this->data[$y][$x]->bg = $paint->bg;
                } else {
                    $effectiveDithering = $paint->getDithering() ?? $this->dithering;
                    [$r, $g, $b] = $paint->getColorAt((float) $x, (float) $y);
                    if ($effectiveDithering !== Dithering::None) {
                        $result = IrcPalette::nearestColorWithMeta($r, $g, $b, $effectiveDithering, $x, $y);
                        $this->data[$y][$x]->fg = $result->code;
                        $this->data[$y][$x]->dithered = $result->dithered;
                        $this->data[$y][$x]->secondBest = $result->secondBest;
                        $this->data[$y][$x]->t = $result->t;
                    } else {
                        $this->data[$y][$x]->fg = IrcPalette::nearestColor($r, $g, $b);
                    }
                    $this->data[$y][$x]->bg = null;
                }
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
     * @param Path $path The path to render.
     * @param ?Paint $fill Fill paint, or null for no fill.
     * @param ?StrokeStyle $stroke Stroke style, or null for no outline.
     * @param string $text Optional text for rendered pixels.
     * @param FillRule $fillRule Fill rule for scanline conversion.
     * @param float $fillOpacity Opacity for the fill (0.0–1.0).
     * @param float $opacity Element-level opacity applied to everything (0.0–1.0).
     */
    public function drawPath(
        Path $path,
        ?Paint $fill,
        ?StrokeStyle $stroke,
        string $text = '',
        FillRule $fillRule = FillRule::NonZero,
        float $fillOpacity = 1.0,
        float $opacity = 1.0,
    ): void {
        $subpaths = $path->flatten();
        if (count($subpaths) === 0) {
            return;
        }
        if ($fill === null && $stroke === null) {
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

        $fillOpacity = max(0.0, min(1.0, $fillOpacity));
        $opacity = max(0.0, min(1.0, $opacity));
        $strokeOpacity = $stroke !== null ? max(0.0, min(1.0, $stroke->opacity)) : 1.0;

        if ($opacity < 1.0) {
            $temp = Canvas::createBlank($this->w, $this->h, $this->halfblocks);
            $temp->setDithering($this->dithering);
            $this->renderFill($temp, $snappedSubpaths, $fill, $text, $fillRule, $fillOpacity);
            $this->renderStroke($temp, $snappedSubpaths, $stroke, $text, $strokeOpacity);
            Compositor::blend($this, $temp, $opacity);
        } else {
            $this->renderFill($this, $snappedSubpaths, $fill, $text, $fillRule, $fillOpacity);
            $this->renderStroke($this, $snappedSubpaths, $stroke, $text, $strokeOpacity);
        }
    }

    /**
     * @param array<int, array{vertices: array<int, array{int, int}>, closed: bool}> $snappedSubpaths
     */
    private function renderFill(Canvas $target, array $snappedSubpaths, ?Paint $fill, string $text, FillRule $fillRule, float $fillOpacity): void
    {
        if ($fill === null) {
            return;
        }
        $polygonArrays = [];
        foreach ($snappedSubpaths as $sp) {
            if (count($sp['vertices']) >= 3) {
                $polygonArrays[] = $sp['vertices'];
            }
        }
        if (count($polygonArrays) > 0) {
            if ($fillOpacity < 1.0) {
                $temp = Canvas::createBlank($target->w, $target->h, $target->halfblocks);
                $temp->setDithering($this->dithering);
                $temp->fillPolygonScanlineMulti($polygonArrays, $fill, $text, $fillRule);
                Compositor::blend($target, $temp, $fillOpacity);
            } else {
                $target->fillPolygonScanlineMulti($polygonArrays, $fill, $text, $fillRule);
            }
        }
    }

    /**
     * @param array<int, array{vertices: array<int, array{int, int}>, closed: bool}> $snappedSubpaths
     */
    private function renderStroke(Canvas $target, array $snappedSubpaths, ?StrokeStyle $stroke, string $text, float $strokeOpacity): void
    {
        if ($stroke === null) {
            return;
        }
        if ($strokeOpacity < 1.0) {
            $temp = Canvas::createBlank($target->w, $target->h, $target->halfblocks);
            $temp->setDithering($this->dithering);
            foreach ($snappedSubpaths as $sp) {
                $temp->strokeSubpath($sp, $stroke, $text);
            }
            Compositor::blend($target, $temp, $strokeOpacity);
        } else {
            foreach ($snappedSubpaths as $sp) {
                $target->strokeSubpath($sp, $stroke, $text);
            }
        }
    }

    /**
     * @param array{vertices: array<int, array{int, int}>, closed: bool} $sp
     */
    private function strokeSubpath(array $sp, StrokeStyle $stroke, string $text): void
    {
        $vertices = $sp['vertices'];
        $n = count($vertices);
        if ($sp['closed'] && $n < 3) {
            return;
        }
        if ($n < 2) {
            return;
        }

        $dashSegments = $this->applyDashPattern($vertices, $sp['closed'], $stroke);

        if ($stroke->width <= 1.0) {
            foreach ($dashSegments as $seg) {
                $segVerts = $seg['vertices'];
                $segN = count($segVerts);
                if ($segN < 2) {
                    continue;
                }
                for ($i = 1; $i < $segN; $i++) {
                    $this->drawLineInternal(
                        (int) $segVerts[$i - 1][0],
                        (int) $segVerts[$i - 1][1],
                        (int) $segVerts[$i][0],
                        (int) $segVerts[$i][1],
                        $stroke->paint,
                        $text
                    );
                }
                if ($seg['closed']) {
                    $this->drawLineInternal(
                        (int) $segVerts[$segN - 1][0],
                        (int) $segVerts[$segN - 1][1],
                        (int) $segVerts[0][0],
                        (int) $segVerts[0][1],
                        $stroke->paint,
                        $text
                    );
                }
            }
            return;
        }

        $halfW = $stroke->width / 2.0;

        foreach ($dashSegments as $seg) {
            $segVerts = $seg['vertices'];
            $segClosed = $seg['closed'];
            $segN = count($segVerts);
            if ($segClosed && $segN < 3) {
                continue;
            }
            if ($segN < 2) {
                continue;
            }

            $polygon = $this->expandStrokePolygon($segVerts, $segClosed, $halfW, $stroke);
            if (count($polygon) >= 3) {
                $this->fillPolygonScanlineMulti([$polygon], $stroke->paint, $text, FillRule::NonZero);
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
    private function fillPolygonScanlineMulti(array $subpaths, Paint $paint, string $text, FillRule $fillRule): void
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

        for ($Y = (int) ceil($minY); $Y <= (int) floor($maxY); $Y++) {
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

            $fillSpan = function (float $x0, float $x1) use ($Y, $paint, $text): void {
                $xL = (int) ceil($x0);
                $xR = (int) floor($x1);
                for ($xx = $xL; $xx <= $xR; $xx++) {
                    if (isset($this->data[$Y][$xx])) {
                        if ($paint->isSolid() && $paint instanceof Color) {
                            $this->data[$Y][$xx]->fg = $paint->fg;
                            $this->data[$Y][$xx]->bg = $paint->bg;
                        } else {
                            $effectiveDithering = $paint->getDithering() ?? $this->dithering;
                            [$r, $g, $b] = $paint->getColorAt((float) $xx, (float) $Y);
                            if ($effectiveDithering !== Dithering::None) {
                                $result = IrcPalette::nearestColorWithMeta($r, $g, $b, $effectiveDithering, $xx, $Y);
                                $this->data[$Y][$xx]->fg = $result->code;
                                $this->data[$Y][$xx]->dithered = $result->dithered;
                                $this->data[$Y][$xx]->secondBest = $result->secondBest;
                                $this->data[$Y][$xx]->t = $result->t;
                            } else {
                                $this->data[$Y][$xx]->fg = IrcPalette::nearestColor($r, $g, $b);
                            }
                            $this->data[$Y][$xx]->bg = null;
                        }
                        if ($text != '') {
                            $this->data[$Y][$xx]->text = $text;
                        }
                    }
                }
            };

            if ($fillRule === FillRule::EvenOdd) {
                $inside = false;
                $spanStart = null;
                foreach ($intersections as [$xInt]) {
                    $inside = !$inside;
                    if ($inside) {
                        $spanStart = $xInt;
                    } else {
                        if ($spanStart !== null) {
                            $fillSpan($spanStart, $xInt);
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
                            $fillSpan($spanStart, $xInt);
                        }
                        $spanStart = null;
                    }
                }
            }
        }
    }

    private function expandStrokePolygon(array $vertices, bool $closed, float $halfW, StrokeStyle $stroke): array
    {
        $n = count($vertices);
        $count = $closed ? $n : $n - 1;

        $segments = [];

        for ($i = 0; $i < $count; $i++) {
            $curr = $vertices[$i];
            $next = $vertices[($i + 1) % $n];

            $dx = (float) ($next[0] - $curr[0]);
            $dy = (float) ($next[1] - $curr[1]);
            $len = sqrt($dx * $dx + $dy * $dy);
            if ($len < 0.0001) {
                continue;
            }
            $nx = -$dy / $len;
            $ny = $dx / $len;

            $segments[] = [
                'left' => [
                    [$curr[0] + $nx * $halfW, $curr[1] + $ny * $halfW],
                    [$next[0] + $nx * $halfW, $next[1] + $ny * $halfW]
                ],
                'right' => [
                    [$curr[0] - $nx * $halfW, $curr[1] - $ny * $halfW],
                    [$next[0] - $nx * $halfW, $next[1] - $ny * $halfW]
                ],
                'normal' => [$nx, $ny]
            ];
        }

        if (empty($segments)) {
            return [];
        }

        $leftPts = [];
        $rightPts = [];
        $segCount = count($segments);

        for ($i = 0; $i < $segCount; $i++) {
            if ($i === 0) {
                $leftPts[] = $segments[$i]['left'][0];
                $rightPts[] = $segments[$i]['right'][0];
            }

            if ($i < $segCount - 1 || $closed) {
                $nextIdx = ($i + 1) % $segCount;
                $currLeftEnd = $segments[$i]['left'][1];
                $nextLeftStart = $segments[$nextIdx]['left'][0];
                $nextLeftEnd = $segments[$nextIdx]['left'][1];
                $currRightEnd = $segments[$i]['right'][1];
                $nextRightStart = $segments[$nextIdx]['right'][0];
                $nextRightEnd = $segments[$nextIdx]['right'][1];
                $vertex = $vertices[($i + 1) % $n];

                $this->applyJoin($leftPts, $currLeftEnd, $nextLeftStart, $nextLeftEnd, $vertex, $halfW, $stroke);
                $this->applyJoin($rightPts, $currRightEnd, $nextRightStart, $nextRightEnd, $vertex, $halfW, $stroke);
            } else {
                $leftPts[] = $segments[$i]['left'][1];
                $rightPts[] = $segments[$i]['right'][1];
            }
        }

        $leftClean = $this->deduplicateVertices($leftPts);
        $rightClean = $this->deduplicateVertices($rightPts);

        if ($closed) {
            $rightReversed = array_reverse($rightClean);
            return array_merge($leftClean, $rightReversed);
        }

        $startCap = $this->makeCap($leftClean[0], $rightClean[0], $vertices[0], $vertices[1] ?? $vertices[0], $halfW, $stroke->lineCap, true);
        $endCap = $this->makeCap($leftClean[count($leftClean) - 1], $rightClean[count($rightClean) - 1], $vertices[$n - 1], $vertices[$n - 2], $halfW, $stroke->lineCap, false);

        $rightReversed = array_reverse($rightClean);
        return array_merge($leftClean, $endCap, $rightReversed, array_reverse($startCap));
    }

    private function applyJoin(array &$pts, array $segEnd, array $nextSegStart, array $nextSegEnd, array $vertex, float $halfW, StrokeStyle $stroke): void
    {
        if ($stroke->lineJoin === LineJoin::Miter) {
            $prev = $pts[count($pts) - 1];
            $intersection = $this->lineIntersection($prev, $segEnd, $nextSegStart, $nextSegEnd);
            if ($intersection !== null) {
                $dx = $intersection[0] - $vertex[0];
                $dy = $intersection[1] - $vertex[1];
                $dist = sqrt($dx * $dx + $dy * $dy);
                if ($dist <= $stroke->miterLimit * $halfW) {
                    $pts[] = $intersection;
                    return;
                }
            }
            $pts[] = $segEnd;
            $pts[] = $nextSegStart;
        } elseif ($stroke->lineJoin === LineJoin::Round) {
            $pts[] = $segEnd;
            $arcPts = $this->arcPoints($vertex, $segEnd, $nextSegStart, $halfW);
            for ($k = 1; $k < count($arcPts) - 1; $k++) {
                $pts[] = $arcPts[$k];
            }
            $pts[] = $nextSegStart;
        } else {
            $pts[] = $segEnd;
            $pts[] = $nextSegStart;
        }
    }

    private function deduplicateVertices(array $vertices): array
    {
        $result = [$vertices[0]];
        for ($i = 1; $i < count($vertices); $i++) {
            $prev = $result[count($result) - 1];
            $dx = $vertices[$i][0] - $prev[0];
            $dy = $vertices[$i][1] - $prev[1];
            if ($dx * $dx + $dy * $dy > 0.0001) {
                $result[] = $vertices[$i];
            }
        }
        return $result;
    }

    private function lineIntersection(array $p1, array $p2, array $p3, array $p4): ?array
    {
        $x1 = $p1[0]; $y1 = $p1[1];
        $x2 = $p2[0]; $y2 = $p2[1];
        $x3 = $p3[0]; $y3 = $p3[1];
        $x4 = $p4[0]; $y4 = $p4[1];

        $denom = ($x1 - $x2) * ($y3 - $y4) - ($y1 - $y2) * ($x3 - $x4);
        if (abs($denom) < 0.0001) {
            return null;
        }

        $t = (($x1 - $x3) * ($y3 - $y4) - ($y1 - $y3) * ($x3 - $x4)) / $denom;

        $x = $x1 + $t * ($x2 - $x1);
        $y = $y1 + $t * ($y2 - $y1);
        return [$x, $y];
    }

    private function arcPoints(array $center, array $from, array $to, float $radius): array
    {
        $a1 = atan2($from[1] - $center[1], $from[0] - $center[0]);
        $a2 = atan2($to[1] - $center[1], $to[0] - $center[0]);

        $diff = $a2 - $a1;
        while ($diff > M_PI) $diff -= 2 * M_PI;
        while ($diff < -M_PI) $diff += 2 * M_PI;

        $steps = max(3, (int) ceil(abs($diff) * $radius / 2.0));
        $pts = [];
        for ($i = 0; $i <= $steps; $i++) {
            $angle = $a1 + $diff * $i / $steps;
            $pts[] = [$center[0] + $radius * cos($angle), $center[1] + $radius * sin($angle)];
        }
        return $pts;
    }

    private function makeCap(array $leftPt, array $rightPt, array $endpoint, array $direction, float $halfW, LineCap $cap, bool $isStart): array
    {
        if ($cap === LineCap::Butt) {
            return [];
        }

        $dx = (float) ($direction[0] - $endpoint[0]);
        $dy = (float) ($direction[1] - $endpoint[1]);
        $len = sqrt($dx * $dx + $dy * $dy);
        if ($len < 0.0001) {
            return [];
        }
        $dirX = -$dx / $len;
        $dirY = -$dy / $len;

        if ($cap === LineCap::Square) {
            $extX = $dirX * $halfW;
            $extY = $dirY * $halfW;
            $sq1 = [$leftPt[0] + $extX, $leftPt[1] + $extY];
            $sq2 = [$rightPt[0] + $extX, $rightPt[1] + $extY];
            return [$sq1, $sq2];
        }

        $a1 = atan2($leftPt[1] - $endpoint[1], $leftPt[0] - $endpoint[0]);
        $a2 = atan2($rightPt[1] - $endpoint[1], $rightPt[0] - $endpoint[0]);
        $diff = $a2 - $a1;
        while ($diff > M_PI) $diff -= 2 * M_PI;
        while ($diff < -M_PI) $diff += 2 * M_PI;
        $midAngle = $a1 + $diff / 2;
        $dot = cos($midAngle) * $dirX + sin($midAngle) * $dirY;
        if ($dot < 0) {
            if ($diff > 0) {
                $diff -= 2 * M_PI;
            } else {
                $diff += 2 * M_PI;
            }
        }
        $steps = max(3, (int) ceil(abs($diff) * $halfW / 2.0));
        $pts = [];
        for ($i = 0; $i <= $steps; $i++) {
            $angle = $a1 + $diff * $i / $steps;
            $pts[] = [$endpoint[0] + $halfW * cos($angle), $endpoint[1] + $halfW * sin($angle)];
        }
        return $pts;
    }

    private function applyDashPattern(array $vertices, bool $closed, StrokeStyle $stroke): array
    {
        if ($stroke->dashArray === null || count($stroke->dashArray) === 0) {
            return [['vertices' => $vertices, 'closed' => $closed]];
        }

        $totalLen = 0.0;
        $segments = [];
        for ($i = 0; $i < count($vertices) - 1; $i++) {
            $dx = (float) ($vertices[$i + 1][0] - $vertices[$i][0]);
            $dy = (float) ($vertices[$i + 1][1] - $vertices[$i][1]);
            $segLen = sqrt($dx * $dx + $dy * $dy);
            $segments[] = ['start' => $vertices[$i], 'end' => $vertices[$i + 1], 'len' => $segLen, 'offset' => $totalLen];
            $totalLen += $segLen;
        }
        if ($closed && count($vertices) >= 2) {
            $lastIdx = count($vertices) - 1;
            $dx = (float) ($vertices[0][0] - $vertices[$lastIdx][0]);
            $dy = (float) ($vertices[0][1] - $vertices[$lastIdx][1]);
            $segLen = sqrt($dx * $dx + $dy * $dy);
            $segments[] = [
                'start' => $vertices[$lastIdx],
                'end' => $vertices[0],
                'len' => $segLen,
                'offset' => $totalLen
            ];
            $totalLen += $segLen;
        }

        $dashLen = array_sum($stroke->dashArray);
        if ($dashLen <= 0) {
            return [['vertices' => $vertices, 'closed' => $closed]];
        }

        $result = [];
        $currentDashVerts = [];
        $pos = -$stroke->dashOffset;
        $patternIdx = 0;
        $patternPos = 0.0;
        $drawing = true;

        while ($pos < $totalLen) {
            if ($pos < 0) {
                $currentDash = $stroke->dashArray[$patternIdx % count($stroke->dashArray)];
                $advance = min(-$pos, $currentDash - $patternPos);
                $patternPos += $advance;
                $pos += $advance;
                if ($patternPos >= $currentDash - 0.0001) {
                    $patternPos = 0.0;
                    $patternIdx++;
                    $drawing = !$drawing;
                }
                continue;
            }

            $currentDash = $stroke->dashArray[$patternIdx % count($stroke->dashArray)];
            $remainingInDash = $currentDash - $patternPos;
            $remainingInPath = $totalLen - $pos;
            $advance = min($remainingInDash, $remainingInPath);

            $fromPos = $pos;
            $toPos = $pos + $advance;

            if ($drawing) {
                $fromPt = $this->pointAtLength($segments, $fromPos);
                $toPt = $this->pointAtLength($segments, min($toPos, $totalLen) - 0.0001);
                if ($fromPt !== null && $toPt !== null) {
                    if (empty($currentDashVerts)) {
                        $currentDashVerts[] = $fromPt;
                    }
                    $currentDashVerts[] = $toPt;
                }
            }

            $pos = $toPos;
            $patternPos += $advance;

            if ($patternPos >= $currentDash - 0.0001) {
                $patternPos = 0.0;
                $patternIdx++;
                $drawing = !$drawing;
                if (!empty($currentDashVerts) && count($currentDashVerts) >= 2) {
                    $result[] = ['vertices' => $currentDashVerts, 'closed' => false];
                    $currentDashVerts = [];
                }
            }
        }

        if (!empty($currentDashVerts) && count($currentDashVerts) >= 2) {
            $result[] = ['vertices' => $currentDashVerts, 'closed' => false];
        }

        if (empty($result)) {
            return [];
        }

        return $result;
    }

    private function pointAtLength(array $segments, float $length): ?array
    {
        foreach ($segments as $seg) {
            if ($length <= $seg['offset'] + $seg['len'] + 0.0001) {
                $t = $seg['len'] > 0.0001 ? ($length - $seg['offset']) / $seg['len'] : 0.0;
                $t = max(0.0, min(1.0, $t));
                return [
                    $seg['start'][0] + $t * ($seg['end'][0] - $seg['start'][0]),
                    $seg['start'][1] + $t * ($seg['end'][1] - $seg['start'][1])
                ];
            }
        }
        if (!empty($segments)) {
            $last = $segments[count($segments) - 1];
            return [$last['end'][0], $last['end'][1]];
        }
        return null;
    }
}
