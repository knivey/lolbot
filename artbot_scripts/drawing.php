<?php
namespace artbot_scripts;

use knivey\cmdr\attributes\Cmd;
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

    private function __construct() {
    }

    /**
     * New blank art
     * @param int $w width
     * @param int $h height
     * @return Art
     */
    public static function createBlank(int $w, int $h) : Art {
        $new = new self();
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
        foreach($this->canvas as $y) {
            $fg = null;
            $bg = null;
            foreach ($y as $p) {
                $code = '';
                if($p->fg !== $fg) {
                    $fg = $p->fg;
                    if($p->fg === null) {
                        $code = "99";
                    } else {
                        if ($fg < 10)
                            $code = "0$fg";
                        else
                            $code = $fg;
                    }
                }
                // some clients dont like empty fg
                if($code == '' && $p->bg !== $bg) {
                    if($fg === null)
                        $code = "99";
                    else
                        $code = $fg;
                }

                if($p->bg !== $bg) {
                    $bg = $p->bg;
                    if($p->bg === null) {
                        $code .= ",99";
                    } else {
                        if ($bg < 10)
                            $code .= ",0$bg";
                        else
                            $code .= ",$bg";
                    }
                }
                if($code != "") {
                    if($code == "99,99")
                        $out .= "\x03";
                    else
                        $out .= "\x03$code";
                }
                $out .= $p;
            }
            $out .= "\n";
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
#[Syntax('<sx> <sy> <ex> <ey>')]
function lineTest($args, \Irc\Client $bot, \knivey\cmdr\Request $req)
{
    $art = Art::createBlank(30, 14);
    $sx = $req->args['sx'];
    $sy = $req->args['sy'];
    $ex = $req->args['ex'];
    $ey = $req->args['ey'];
    foreach(compact(['sx', 'sy', 'ex', 'ey']) as $k => $v) if(!is_numeric($v)) {
        $bot->msg($args->chan, "argument $k must be numeric");
        return;
    }

    $art->drawLine($sx, $sy, $ex, $ey, new Color(04,0), "x");

    \pumpToChan($args->chan, explode("\n", trim($art, "\n")));
}


#[Cmd("filledellipsetest")]
#[Syntax('<cx> <cy> <w> <h>')]
function filledEllipseTest($args, \Irc\Client $bot, \knivey\cmdr\Request $req)
{
    $art = Art::createBlank(80, 24);
    $cx = $req->args['cx'];
    $cy = $req->args['cy'];
    $w = $req->args['w'];
    $h = $req->args['h'];
    foreach(compact(['cx', 'cy', 'w', 'h']) as $k => $v) if(!is_numeric($v)) {
        $bot->msg($args->chan, "argument $k must be numeric");
        return;
    }
    $art->drawFilledEllipse($cx, $cy, $w, $h, new Color(04,0), "x");

    \pumpToChan($args->chan, explode("\n", trim($art, "\n")));
}

#[Cmd("ellipsetest")]
#[Syntax('<cx> <cy> <w> <h> <segs>')]
function ellipseTest($args, \Irc\Client $bot, \knivey\cmdr\Request $req)
{
    $art = Art::createBlank(80, 24);
    $cx = $req->args['cx'];
    $cy = $req->args['cy'];
    $w = $req->args['w'];
    $h = $req->args['h'];
    $segs = $req->args['segs'];
    foreach(compact(['cx', 'cy', 'w', 'h', 'segs']) as $k => $v) if(!is_numeric($v)) {
        $bot->msg($args->chan, "argument $k must be numeric");
        return;
    }
    $art->drawEllipse($cx, $cy, $w, $h, new Color(04,0), "x", $segs);

    \pumpToChan($args->chan, explode("\n", trim($art, "\n")));
}


#[Cmd("lines")]
function lines($args, \Irc\Client $bot, \knivey\cmdr\Request $req)
{
    $art = Art::createBlank(80, 24);
    $numlines = rand(5,20);
    for($i=0; $i<$numlines; $i++) {
        $color = new Color(null, rand(0,16));
        $sx = rand(0, 80);
        $sy = rand(0, 24);
        $ex = rand(0, 80);
        $ey = rand(0, 24);
        $art->drawLine($sx, $sy, $ex, $ey, $color);
    }

    \pumpToChan($args->chan, explode("\n", trim($art, "\n")));
}