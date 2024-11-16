<?php

namespace artbot_scripts;

use draw\Canvas;
use draw\Color;

use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Desc;
use knivey\cmdr\attributes\Option;
use knivey\cmdr\attributes\Syntax;

#[Cmd("linetest")]
#[Syntax('<sx: uint max=100> <sy: uint max=100> <ex: uint max=100> <ey: uint max=100>')]
function lineTest($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    $art = Canvas::createBlank(30, 14);
    $sx = $cmdArgs['sx'];
    $sy = $cmdArgs['sy'];
    $ex = $cmdArgs['ex'];
    $ey = $cmdArgs['ey'];

    $art->drawLine($sx, $sy, $ex, $ey, new Color(04, 0), "x");

    \pumpToChan($args->chan, explode("\n", trim($art, "\n")));
}


#[Cmd("filledellipsetest")]
#[Syntax('<cx: uint max=100> <cy: uint max=100> <w: uint max=100> <h: uint max=100>')]
function filledEllipseTest($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    $art = Canvas::createBlank(80, 24);
    $cx = $cmdArgs['cx'];
    $cy = $cmdArgs['cy'];
    $w = $cmdArgs['w'];
    $h = $cmdArgs['h'];

    $art->drawFilledEllipse($cx, $cy, $w, $h, new Color(04, 0), "x");

    \pumpToChan($args->chan, explode("\n", trim($art, "\n")));
}

#[Cmd("ellipsetest")]
#[Syntax('<cx: uint max=100> <cy: uint max=100> <w: uint max=100> <h: uint max=100> <segs: uint max=100>')]
function ellipseTest($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    $art = Canvas::createBlank(80, 24);
    $cx = $cmdArgs['cx'];
    $cy = $cmdArgs['cy'];
    $w = $cmdArgs['w'];
    $h = $cmdArgs['h'];
    $segs = $cmdArgs['segs'];

    $art->drawEllipse($cx, $cy, $w, $h, new Color(04, 0), "x", $segs);

    \pumpToChan($args->chan, explode("\n", trim($art, "\n")));
}


#[Cmd("lines")]
#[Desc("Draw some random lines")]
function lines($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    $art = Canvas::createBlank(80, 48, true);
    $numlines = rand(5, 20);
    for ($i = 0; $i < $numlines; $i++) {
        $color = new Color(rand(0, 16), null);
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
    $art = Canvas::createBlank(80, 48, true);
    $numcircles = rand(5, 20);
    for ($i = 0; $i < $numcircles; $i++) {
        $color = new Color(rand(0, 16), null);
        $w = rand(6, 80);
        $h = rand($w / 2 - 3, $w / 2 + 3) + 5;
        $cx = rand(-5, 90);
        $cy = rand(-5, 55);
        $art->drawEllipse($cx, $cy, $w, $h, $color);
    }

    \pumpToChan($args->chan, explode("\n", trim($art, "\n")));
}

#[Cmd("stars")]
#[Desc("Draw some random stars")]
#[Option("--lines")]
function stars($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    $lines = 48;
    if ($cmdArgs->optEnabled("--lines")) {
        $lines = intval($cmdArgs->getOpt("--lines"));
        if ($lines < 48 || $lines > 300) {
            $bot->pm($args->chan, "--lines should be from 48 to 300");
            return;
        }
    }
    $art = Canvas::createBlank(80, $lines, true);
    $bgs = [1,2,3,5,6,10];
    $fgs = [4,7,8,9,11,12,13];
    $art->fillColor(0, 0, new Color($bgs[array_rand($bgs)], 0));
    $numstars = random_int(3 * ($lines / 48), 8 * ($lines / 48));
    for ($i = 0; $i < $numstars; $i++) {
        $tart = Canvas::createBlank(80, $lines, true);
        $color = new Color($fgs[array_rand($fgs)], null);
        $alpha = (2 * M_PI) / 10;
        $radius = random_int(7, 25);
        $x = random_int(0, 80);
        $y = random_int(0, $lines);
        $points = [];
        $rot = deg2rad(random_int(0, intval(360 / 5)));
        $lx = null;
        $ly = null;
        for ($p = 11; $p != 0; $p--) {
            $omega = ($alpha * $p) + $rot;
            $r = $radius * ($p % 2 + 1) / 2;
            $points[] = [$r * sin($omega) + $x, $r * cos($omega) + $y];
        }

        foreach ($points as $point) {
            if ($lx === null) {
                $lx = $point[0];
                $ly = $point[1];
                continue;
            }
            $tart->drawLine((int)round($lx), (int)round($ly), (int)round($point[0]), (int)round($point[1]), $color);
            $lx = $point[0];
            $ly = $point[1];
        }
        if (random_int(0, 4) > 1) {
            $tart->fillColor($x, $y, new Color($fgs[array_rand($fgs)], null));
        }
        $art->overlay($tart);
    }

    \pumpToChan($args->chan, explode("\n", trim($art, "\n")));
}
