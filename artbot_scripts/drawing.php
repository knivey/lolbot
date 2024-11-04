<?php
namespace artbot_scripts;

use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Desc;
use knivey\cmdr\attributes\Syntax;

class Pixel {
    public ?int $fg = null;
    public ?int $bg = null;
    public string $text = ' ';

    public function __toString(): string
    {
        //can't do colors here because it doesnt know whats before it
        return $this->text;
    }
}

/**
 * Used for drawing ops, useful for gradients
 * @property-read $fg
 * @property-read $bg
 */
class Color {

    /**
     * Color constructor.
     * @param int|null $fg
     * @param int|null $bg
     */
    public function __construct(
        private ?int $fg = null,
        private ?int $bg = null) {
    }

    public function __get(string $name): mixed
    {
        if($name == 'fg')
            return $this->fg;
        if($name == 'bg')
            return $this->bg;
        $trace = debug_backtrace();
        trigger_error('Undefined property via __get(): ' . $name .
            ' in ' . $trace[0]['file'] .
            ' on line ' . $trace[0]['line'],
            E_USER_NOTICE);
        return false;
    }

    public function equals(Color $color): bool {
        if($this->fg === $color->fg && $this->bg === $color->bg)
            return true;
        return false;
    }

    //thinking this can be like an array of colors with a step size?
    public function setGradiant() {

    }

    public function advanceGradiant() {

    }
}

class Art {
    /**
     * Indexed by [Y][X]
     * @var Pixel[][]
     */
    public array $canvas = [];

    public int $w = 0;
    public int $h = 0;

    private function __construct(readonly public bool $halfblocks = false) {
    }

    /**
     * New blank art
     * @param int $w width
     * @param int $h height
     * @return Art
     */
    public static function createBlank(int $w, int $h, bool $halfblocks = false) : Art {
        $new = new self($halfblocks);
        //lol all pixels were same instance
        //$new->canvas = array_fill(0, $h, array_fill(0, $w, new Pixel()));
        for($y=0;$y<$h;$y++) {
            for($x=0;$x<$w;$x++) {
                $new->canvas[$y][$x] = new Pixel();
            }
        }
        $new->w = $w;
        $new->h = $h;
        return $new;
    }

    /**
     * Create from existing art
     * @param string $artText contents of an art file
     * @return Art
     */
    public static function createFromArt(string $artText) : Art {
        //TODO implement
        $new = new self();
        return $new;
    }

    public function __toString(): string
    {
        $out = '';
        if($this->halfblocks) {
            for($row = 0; $row < count($this->canvas); $row+=2) {
                $fg = null;
                $bg = null;
                $hb = "▀";
                for($col = 0; $col < $this->w; $col++) {
                    $pixel1 = $this->canvas[$row][$col];
                    if (isset($this->canvas[$row+1]))
                        $pixel2 = $this->canvas[$row+1][$col];
                    else
                        $pixel2 = new Pixel();
                    if(($pixel1->fg === null && $fg !== null) || ($pixel2->fg === null && $bg !== null)) {
                        $out .= "\x03";
                        $fg = null;
                        $bg = null;
                    }
                    if($pixel1->fg === null && $pixel2->fg === null) {
                        $out .= " ";
                        continue;
                    }
                    if($pixel1->fg !== $fg || $pixel2->fg !== $bg) {
                        if($pixel1->fg === $pixel2->fg && $pixel2->fg === $bg) {
                            $out .= " ";
                            continue;
                        }

                        if($bg === $pixel2->fg)
                            if($pixel1->fg === null)
                                $out .= "\x03,$pixel2->fg";
                            else
                                $out .= "\x03$pixel1->fg";
                        else
                            if($pixel2->fg !== null)
                                $out .= "\x03$pixel1->fg,$pixel2->fg";
                            else
                                $out .= "\x03$pixel1->fg";
                        $fg = $pixel1->fg;
                        $bg = $pixel2->fg;
                    }
                    if($pixel1->fg !== $pixel2->fg)
                        $out .= $hb;
                    else
                        $out .= " ";
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
                            if ($fg < 10)
                                $code = "0$fg";
                            else
                                $code = $fg;
                        }
                    }
                    // some clients dont like empty fg
                    if ($code == '' && $p->bg !== $bg) {
                        if ($fg === null)
                            $code = "99";
                        else
                            $code = $fg;
                    }

                    if ($p->bg !== $bg) {
                        $bg = $p->bg;
                        if ($p->bg === null) {
                            $code .= ",99";
                        } else {
                            if ($bg < 10)
                                $code .= ",0$bg";
                            else
                                $code .= ",$bg";
                        }
                    }
                    if ($code != "") {
                        if ($code == "99,99")
                            $out .= "\x03";
                        else
                            $out .= "\x03$code";
                    }
                    $out .= $p;
                }
                $out .= "\n";
            }
        }
        return $out;
    }

    public function drawPoint(int $x, int $y, Color $color, string $text = '') {
        if(isset($this->canvas[$y][$x])) {
            $this->canvas[$y][$x]->fg = $color->fg;
            $this->canvas[$y][$x]->bg = $color->bg;
            if ($text != '')
                $this->canvas[$y][$x]->text = $text;
        }
    }

    public function fillColor(int $x, int $y, Color $color, string $text = '') {
        if(!isset($this->canvas[$y][$x]))
            return;
        $replaceColor = new Color($this->canvas[$y][$x]->fg, $this->canvas[$y][$x]->bg);
        if($replaceColor->equals($color))
            return;
        $stack = [[$y, $x]];
        while(count($stack) != 0) {
            [$curY, $curX] = array_shift($stack);
            $curColor = new Color($this->canvas[$curY][$curX]->fg, $this->canvas[$curY][$curX]->bg);
            if($curColor->equals($replaceColor)) {
                $this->canvas[$curY][$curX]->fg = $color->fg;
                $this->canvas[$curY][$curX]->bg = $color->bg;
                $nexts = [[0,-1],[0,1],[-1,0],[1,0]];
                foreach($nexts as [$ny, $nx]) {
                    $nx += $curX;
                    $ny += $curY;
                    if(isset($this->canvas[$ny][$nx])) {
                        $testColor = new Color($this->canvas[$ny][$nx]->fg, $this->canvas[$ny][$nx]->bg);
                        if($replaceColor->equals($testColor))
                            $stack[] = [$ny, $nx];
                    }
                }
            }
        }
    }

    public function drawLine(int $startX, int $startY, int $endX, int $endY, Color $color, string $text = '') {
        $dx = abs($endX - $startX);
        $dy = abs($endY - $startY);
        $sx = ($startX < $endX ? 1 : -1);
        $sy = ($startY < $endY ? 1 : -1);
        $error = ($dx > $dy ? $dx : - $dy) / 2;
        $e2 = 0;
        $x = $startX;
        $y = $startY;
        $cnt = 0;
        while($cnt++ < 1000) {
            $this->drawPoint($x, $y, $color, $text);
            if ($x == $endX && $y == $endY) break;
            $e2 = $error;
            if($e2 >-$dx) { $error -= $dy; $x += $sx; }
            if($e2 < $dy) { $error += $dx; $y += $sy; }
        }
    }

    public function drawFilledEllipse(int $centerX, int $centerY, int|float $width, int|float $height, Color $color, string $text = '') {
        for($y=-$height; $y<=$height; $y++) {
            for ($x = -$width; $x <= $width; $x++) {
                if ($x * $x * $height * $height + $y * $y * $width * $width <= $height * $height * $width * $width)
                    $this->drawPoint($centerX + $x, $centerY + $y, $color, $text);
            }
        }
    }

    //with a low seg number it draws shapes so maybe just add rotation later for shape drawing
    public function drawEllipse(int $centerX, int $centerY, int|float $width, int|float $height, Color $color, string $text = '', int $segments = 100) {
        //want radius amts
        $width = $width / 2;
        $height = $height / 2;
        $dtheta = pi()*2 / $segments;
        $theta = 0;
        $lx = 0;
        $ly = 0;
        for($i = 0; $i <= $segments; $i++) {
            $x = $centerX + $width * sin($theta);
            $y = $centerY + $height * cos($theta);
            $theta += $dtheta;
            if($i != 0) { // dont want to draw from 0,0
                $this->drawLine($lx, $ly, $x, $y, $color, $text);
            }
            $lx = $x;
            $ly = $y;
        }
    }
}

#[Cmd("linetest")]
#[Syntax('<sx: uint max=100> <sy: uint max=100> <ex: uint max=100> <ey: uint max=100>')]
function lineTest($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    $art = Art::createBlank(30, 14);
    $sx = $cmdArgs['sx'];
    $sy = $cmdArgs['sy'];
    $ex = $cmdArgs['ex'];
    $ey = $cmdArgs['ey'];

    $art->drawLine($sx, $sy, $ex, $ey, new Color(04,0), "x");

    \pumpToChan($args->chan, explode("\n", trim($art, "\n")));
}


#[Cmd("filledellipsetest")]
#[Syntax('<cx: uint max=100> <cy: uint max=100> <w: uint max=100> <h: uint max=100>')]
function filledEllipseTest($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    $art = Art::createBlank(80, 24);
    $cx = $cmdArgs['cx'];
    $cy = $cmdArgs['cy'];
    $w = $cmdArgs['w'];
    $h = $cmdArgs['h'];

    $art->drawFilledEllipse($cx, $cy, $w, $h, new Color(04,0), "x");

    \pumpToChan($args->chan, explode("\n", trim($art, "\n")));
}

#[Cmd("ellipsetest")]
#[Syntax('<cx: uint max=100> <cy: uint max=100> <w: uint max=100> <h: uint max=100> <segs: uint max=100>')]
function ellipseTest($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    $art = Art::createBlank(80, 24);
    $cx = $cmdArgs['cx'];
    $cy = $cmdArgs['cy'];
    $w = $cmdArgs['w'];
    $h = $cmdArgs['h'];
    $segs = $cmdArgs['segs'];

    $art->drawEllipse($cx, $cy, $w, $h, new Color(04,0), "x", $segs);

    \pumpToChan($args->chan, explode("\n", trim($art, "\n")));
}


#[Cmd("lines")]
#[Desc("Draw some random lines")]
function lines($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    $art = Art::createBlank(80, 48, true);
    $numlines = rand(5,20);
    for($i=0; $i<$numlines; $i++) {
        $color = new Color(rand(0,16), null);
        $sx = rand(0, 80);
        $sy = rand(0, 48);
        $ex = rand(0, 80);
        $ey = rand(0, 48);
        $art->drawLine($sx, $sy, $ex, $ey, $color);
    }

    \pumpToChan($args->chan, explode("\n", trim($art, "\n")));
}


#[Cmd("circles")]
#[Desc("Draw some random circles")]
function circles($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    $art = Art::createBlank(80, 48, true);
    $numcircles = rand(5,20);
    for($i=0; $i<$numcircles; $i++) {
        $color = new Color( rand(0,16), null);
        $w = rand(6, 80);
        $h = rand($w/2-3, $w/2+3) +5;
        $cx = rand(-5, 90);
        $cy = rand(-5, 55);
        $art->drawEllipse($cx, $cy, $w, $h, $color);
    }

    \pumpToChan($args->chan, explode("\n", trim($art, "\n")));
}

#[Cmd("stars")]
#[Desc("Draw some random stars")]
function stars($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    $art = Art::createBlank(80, 48, true);
    $bgs = [1,2,3,5,6,10];
    $art->fillColor(0,0, new Color($bgs[array_rand($bgs)], 0));
    $numstars = rand(2,8);
    for($i=0; $i<$numstars; $i++) {
        $color = new Color( rand(0,16), null);
        $alpha = (2*3.1415926)/10;
        $radius = rand(7,35);
        $x = rand(0, 80);
        $y = rand(0, 48);
        $points = [];
        $rot = rand(0,100);
        for($p = 11; $p != 0; $p--) {
            $r = $radius*($p % 2 + 1)/2;
            $omega = ($alpha * $p) + $rot;
            $points[] = [$r * sin($omega) + $x, $r * cos($omega) + $y];
        }
        $lx = null;
        $ly = null;
        foreach($points as $point) {
            if($lx === null) {
                $lx = $point[0];
                $ly = $point[1];
                continue;
            }
            $art->drawLine($lx, $ly, $point[0], $point[1], $color);
            $lx = $point[0];
            $ly = $point[1];
        }
    }

    \pumpToChan($args->chan, explode("\n", trim($art, "\n")));
}