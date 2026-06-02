<?php

namespace artbot_scripts;

use draw\Canvas;
use draw\Color;
use draw\Path;

use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Desc;
use knivey\cmdr\attributes\Option;
use knivey\cmdr\attributes\Syntax;

#[Cmd("linetest")]
#[Syntax('<sx: uint max=100> <sy: uint max=100> <ex: uint max=100> <ey: uint max=100>')]
function lineTest(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
{
    $art = Canvas::createBlank(30, 14);
    $sx = $cmdArgs['sx'];
    $sy = $cmdArgs['sy'];
    $ex = $cmdArgs['ex'];
    $ey = $cmdArgs['ey'];

    $art->drawPath(Path::line($sx, $sy, $ex, $ey), null, new Color(04, 0), "x");

    \pumpToChan($bot, $args->chan, explode("\n", trim($art, "\n")));
}


#[Cmd("lines")]
#[Desc("Draw some random lines")]
function lines(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
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

    \pumpToChan($bot, $args->chan, explode("\n", trim($art, "\n")));
}


#[Cmd("circles")]
#[Desc("Draw some random circles")]
function circles(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
{
    $art = Canvas::createBlank(80, 48, true);
    $numcircles = rand(5, 20);
    for ($i = 0; $i < $numcircles; $i++) {
        $color = new Color(rand(0, 16), null);
        $w = rand(6, 80);
        $h = rand($w - 3, $w + 3) + 5;
        $cx = rand(-5, 90);
        $cy = rand(-5, 55);
        $art->drawPath(Path::ellipse($cx, $cy, $w / 2, $h / 2), null, $color);
    }

    \pumpToChan($bot, $args->chan, explode("\n", trim($art, "\n")));
}


#[Cmd("pentagons")]
#[Desc("Draw some random pentagons")]
function pentagons(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
{
    $art = Canvas::createBlank(80, 48, true);
    $numpents = rand(5, 20);
    for ($i = 0; $i < $numpents; $i++) {
        $color = new Color(rand(0, 16), null);
        $radius = random_int(10, 25);
        $cx = random_int(5, 70);
        $cy = random_int(5, 45);
        $rot = deg2rad(random_int(0, 72));
        $points = [];
        for ($p = 0; $p < 5; $p++) {
            $angle = (2 * M_PI * $p / 5) + $rot;
            $points[] = [$cx + $radius * cos($angle), $cy + $radius * sin($angle)];
        }
        $art->drawPath(Path::polygon($points), null, $color);
    }

    \pumpToChan($bot, $args->chan, explode("\n", trim($art, "\n")));
}


#[Cmd("stars")]
#[Desc("Draw some random stars")]
#[Option("--lines")]
function stars(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
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
        $fillColor = new Color($fgs[array_rand($fgs)], null);
        $outlineColor = new Color($fgs[array_rand($fgs)], null);
        $alpha = (2 * M_PI) / 10;
        $radius = random_int(7, 25);
        $x = random_int(0, 80);
        $y = random_int(0, $lines);
        $points = [];
        $rot = deg2rad(random_int(0, intval(360 / 5)));
        for ($p = 11; $p != 0; $p--) {
            $omega = ($alpha * $p) + $rot;
            $r = $radius * ($p % 2 + 1) / 2;
            $points[] = [$r * sin($omega) + $x, $r * cos($omega) + $y];
        }

        $willFill = random_int(0, 4) > 1;
        $tart->drawPath(
            Path::polygon($points),
            $willFill ? $fillColor : null,
            $outlineColor,
        );
        $art->overlay($tart);
    }

    \pumpToChan($bot, $args->chan, explode("\n", trim($art, "\n")));
}


#[Cmd("hearts")]
#[Desc("Draw some random hearts")]
#[Option("--lines")]
function hearts(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
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
    $bgs = [1, 2, 3, 5, 6, 10];
    $fgs = [4, 7, 8, 9, 11, 12, 13];
    $art->fillColor(0, 0, new Color($bgs[array_rand($bgs)], 0));

    // Generate all hearts with size, then sort smallest-first so big ones overlay
    $hearts = [];
    $baseCount = intval(20 * ($lines / 48));
    for ($i = 0; $i < $baseCount; $i++) {
        $radius = random_int(3, 18);
        // Power curve: most hearts are small, few are large
        $radius = 3 + intval(pow((mt_rand() / mt_getrandmax()), 2.5) * 15);
        $hearts[] = $radius;
    }
    sort($hearts);

    foreach ($hearts as $radius) {
        $tart = Canvas::createBlank(80, $lines, true);
        $fillColor = new Color($fgs[array_rand($fgs)], null);
        $outlineColor = new Color($fgs[array_rand($fgs)], null);
        $x = random_int(0, 80);
        $y = random_int(0, $lines);
        $scale = $radius / 16;
        $rot = deg2rad(random_int(-30, 30));
        $cosR = cos($rot);
        $sinR = sin($rot);
        $points = [];
        $segs = 32;
        for ($p = 0; $p < $segs; $p++) {
            $t = ($p / $segs) * 2 * M_PI;
            $hx = 16 * pow(sin($t), 3);
            $hy = -(13 * cos($t) - 5 * cos(2 * $t) - 2 * cos(3 * $t) - cos(4 * $t));
            $px = $hx * $scale;
            $py = $hy * $scale;
            $points[] = [$px * $cosR - $py * $sinR + $x, $px * $sinR + $py * $cosR + $y];
        }

        $willFill = random_int(0, 4) > 1;
        $tart->drawPath(
            Path::polygon($points),
            $willFill ? $fillColor : null,
            $outlineColor,
        );
        $art->overlay($tart);
    }

    \pumpToChan($bot, $args->chan, explode("\n", trim($art, "\n")));
}


#[Cmd("curves")]
#[Desc("Draw random flowing Bézier curves (screensaver style)")]
function curves(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
{
    $art = Canvas::createBlank(80, 48, true);
    $bgs = [1, 2, 3, 5, 6, 10];
    $fgs = [4, 7, 8, 9, 11, 12, 13];
    $art->fillColor(0, 0, new Color($bgs[array_rand($bgs)], 0));

    $numBlobs = rand(3, 7);
    for ($i = 0; $i < $numBlobs; $i++) {
        $cx = rand(10, 70);
        $cy = rand(8, 40);
        $numVerts = rand(4, 8);
        $radius = rand(8, 22);
        $tension = 0.25 + (mt_rand() / mt_getrandmax()) * 0.25;

        $verts = [];
        for ($v = 0; $v < $numVerts; $v++) {
            $angle = (2 * M_PI * $v / $numVerts) + (mt_rand() / mt_getrandmax()) * 0.6;
            $r = $radius * (0.5 + (mt_rand() / mt_getrandmax()) * 0.9);
            $verts[] = [$cx + $r * cos($angle), $cy + $r * sin($angle)];
        }

        $tangents = [];
        for ($v = 0; $v < $numVerts; $v++) {
            $prev = $verts[($v - 1 + $numVerts) % $numVerts];
            $next = $verts[($v + 1) % $numVerts];
            $dx = $next[0] - $prev[0];
            $dy = $next[1] - $prev[1];
            $len = sqrt($dx * $dx + $dy * $dy);
            $tangents[] = $len > 0 ? [$dx / $len, $dy / $len] : [0.0, 0.0];
        }

        $path = new Path();
        $path->moveTo($verts[0][0], $verts[0][1]);
        for ($v = 0; $v < $numVerts; $v++) {
            $v1 = $verts[$v];
            $v2 = $verts[($v + 1) % $numVerts];
            $dist = sqrt(($v2[0] - $v1[0]) ** 2 + ($v2[1] - $v1[1]) ** 2);
            $t1 = $tangents[$v];
            $t2 = $tangents[($v + 1) % $numVerts];
            $c1x = $v1[0] + $t1[0] * $dist * $tension;
            $c1y = $v1[1] + $t1[1] * $dist * $tension;
            $c2x = $v2[0] - $t2[0] * $dist * $tension;
            $c2y = $v2[1] - $t2[1] * $dist * $tension;
            $path->cubicTo($c1x, $c1y, $c2x, $c2y, $v2[0], $v2[1]);
        }
        $path->closePath();

        $outlineColor = new Color($fgs[array_rand($fgs)], null);
        $willFill = rand(0, 3) > 1;
        $fillColor = $willFill ? new Color($fgs[array_rand($fgs)], null) : null;
        $art->drawPath($path, $fillColor, $outlineColor);
    }

    $numRibbons = rand(2, 5);
    for ($i = 0; $i < $numRibbons; $i++) {
        $sx = rand(0, 80);
        $sy = rand(0, 48);
        $ex = rand(0, 80);
        $ey = rand(0, 48);
        $c1x = rand(-20, 100);
        $c1y = rand(-20, 70);
        $c2x = rand(-20, 100);
        $c2y = rand(-20, 70);

        $path = new Path();
        $path->moveTo($sx, $sy);
        $path->cubicTo($c1x, $c1y, $c2x, $c2y, $ex, $ey);
        $color = new Color($fgs[array_rand($fgs)], null);
        $art->drawPath($path, null, $color);
    }

    \pumpToChan($bot, $args->chan, explode("\n", trim($art, "\n")));
}


#[Cmd("mystify")]
#[Desc("Classic Mystify-style screensaver with trailing wavy paths")]
function mystify(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
{
    $w = 80;
    $h = 48;
    $art = Canvas::createBlank($w, $h, true);
    $art->fillColor(0, 0, new Color(1, 1));

    $palettes = [
        [4, 7, 8, 7],
        [9, 11, 10, 11],
        [6, 13, 7, 13],
        [12, 11, 9, 11],
        [13, 6, 13, 8],
        [11, 9, 10, 12],
        [8, 7, 4, 5],
        [12, 6, 13, 11],
        [9, 8, 7, 4],
        [10, 11, 12, 6],
    ];

    $numPaths = rand(1, 2);
    $trailLen = rand(6, 10);
    $numVerts = rand(8, 14);

    // Each path is a wavy line: vertices distributed along a base line
    // with sinusoidal perpendicular displacement that averages to straight
    $simPaths = [];
    for ($p = 0; $p < $numPaths; $p++) {
        $cx = floatval(rand(20, $w - 20));
        $cy = floatval(rand(15, $h - 15));
        $lineAngle = (mt_rand() / mt_getrandmax()) * 2 * M_PI;
        $halfLen = floatval(rand(25, 38));
        $amp1 = floatval(rand(3, 6));
        $amp2 = floatval(rand(1, 3));
        $freq1 = 1.0 + (mt_rand() / mt_getrandmax()) * 2.0;
        $freq2 = 2.5 + (mt_rand() / mt_getrandmax()) * 3.5;
        $phase1 = (mt_rand() / mt_getrandmax()) * 2 * M_PI;
        $phase2 = (mt_rand() / mt_getrandmax()) * 2 * M_PI;
        $phaseVel = 0.6 + (mt_rand() / mt_getrandmax()) * 0.5;
        $driftSpeed = 2.0 + (mt_rand() / mt_getrandmax()) * 2.0;
        $driftAngle = (mt_rand() / mt_getrandmax()) * 2 * M_PI;
        $driftVx = cos($driftAngle) * $driftSpeed;
        $driftVy = sin($driftAngle) * $driftSpeed;

        $simPaths[] = [
            'cx' => $cx,
            'cy' => $cy,
            'dx' => cos($lineAngle),
            'dy' => sin($lineAngle),
            'px' => -sin($lineAngle),
            'py' => cos($lineAngle),
            'halfLen' => $halfLen,
            'amp1' => $amp1,
            'amp2' => $amp2,
            'freq1' => $freq1,
            'freq2' => $freq2,
            'phase1' => $phase1,
            'phase2' => $phase2,
            'phaseVel' => $phaseVel,
            'driftVx' => $driftVx,
            'driftVy' => $driftVy,
            'palette' => $palettes[array_rand($palettes)],
        ];
    }

    // Simulate and collect trail frames
    $frames = [];
    for ($step = 0; $step < $trailLen; $step++) {
        foreach ($simPaths as &$sim) {
            $p1 = $sim['phase1'];
            $p2 = $sim['phase2'];
            $offsetX = $sim['cx'] + $step * $sim['driftVx'];
            $offsetY = $sim['cy'] + $step * $sim['driftVy'];

            $verts = [];
            for ($v = 0; $v < $numVerts; $v++) {
                $t = ($v / ($numVerts - 1)) * 2 - 1;
                $baseX = $offsetX + $sim['dx'] * $sim['halfLen'] * $t;
                $baseY = $offsetY + $sim['dy'] * $sim['halfLen'] * $t;
                $wave = sin($t * $sim['freq1'] * M_PI + $p1) * $sim['amp1']
                      + sin($t * $sim['freq2'] * M_PI + $p2) * $sim['amp2'];
                $verts[] = [$baseX + $sim['px'] * $wave, $baseY + $sim['py'] * $wave];
            }

            $colorIdx = $step % count($sim['palette']);
            $frames[] = [
                'verts' => $verts,
                'color' => $sim['palette'][$colorIdx],
            ];
        }
        unset($sim);
    }

    // Draw trail from oldest to newest
    foreach ($frames as $frame) {
        $color = new Color($frame['color'], 1);
        $verts = $frame['verts'];
        $nv = count($verts);

        $tangents = [];
        for ($v = 0; $v < $nv; $v++) {
            $prev = $verts[max(0, $v - 1)];
            $next = $verts[min($nv - 1, $v + 1)];
            $dx = $next[0] - $prev[0];
            $dy = $next[1] - $prev[1];
            $len = sqrt($dx * $dx + $dy * $dy);
            $tangents[] = $len > 0 ? [$dx / $len, $dy / $len] : [0.0, 0.0];
        }

        $path = new Path();
        $path->moveTo($verts[0][0], $verts[0][1]);
        $tension = 0.15;
        for ($v = 0; $v < $nv - 1; $v++) {
            $v1 = $verts[$v];
            $v2 = $verts[$v + 1];
            $dist = sqrt(($v2[0] - $v1[0]) ** 2 + ($v2[1] - $v1[1]) ** 2);
            $c1x = $v1[0] + $tangents[$v][0] * $dist * $tension;
            $c1y = $v1[1] + $tangents[$v][1] * $dist * $tension;
            $c2x = $v2[0] - $tangents[$v + 1][0] * $dist * $tension;
            $c2y = $v2[1] - $tangents[$v + 1][1] * $dist * $tension;
            $path->cubicTo($c1x, $c1y, $c2x, $c2y, $v2[0], $v2[1]);
        }
        $art->drawPath($path, null, $color);
    }

    \pumpToChan($bot, $args->chan, explode("\n", trim($art, "\n")));
}


$demos = ['flowers', 'spiral', 'mondrian', 'bubbles', 'vortex'];

#[Cmd("demo")]
#[Desc("Draw a Path API demo (flowers, spiral, mondrian, bubbles, vortex). Random if no arg.")]
#[Syntax('[name]')]
function demo(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
{
    global $demos;
    $name = $cmdArgs['name'] ?? $demos[array_rand($demos)];

    $art = Canvas::createBlank(80, 48, true);
    $art->fillColor(0, 0, new Color(1, 1));

    match ($name) {
        'flowers' => demoFlowers($art),
        'spiral' => demoSpiral($art),
        'mondrian' => demoMondrian($art),
        'bubbles' => demoBubbles($art),
        'vortex' => demoVortex($art),
        default => $bot->pm($args->chan, "unknown demo: $name  try: " . implode(', ', $demos)),
    };

    \pumpToChan($bot, $args->chan, explode("\n", trim($art, "\n")));
}

function demoFlowers(Canvas $art): void
{
    $fgs = [4, 7, 8, 9, 11, 12, 13];
    $numFlowers = rand(3, 7);
    for ($f = 0; $f < $numFlowers; $f++) {
        $cx = rand(15, 65);
        $cy = rand(12, 36);
        $numPetals = rand(5, 8);
        $petalLen = rand(8, 16);
        $petalWidth = rand(2, 5);
        $fillColor = new Color($fgs[array_rand($fgs)], null);
        $outlineColor = new Color($fgs[array_rand($fgs)], null);
        $centerColor = new Color($fgs[array_rand($fgs)], null);
        for ($p = 0; $p < $numPetals; $p++) {
            $angle = (2 * M_PI * $p / $numPetals);
            $px = $cx + cos($angle) * $petalLen * 0.5;
            $py = $cy + sin($angle) * $petalLen * 0.5;
            $art->drawPath(Path::ellipse($px, $py, $petalWidth, $petalLen), $fillColor, $outlineColor);
        }
        $art->drawPath(Path::circle($cx, $cy, 2), $centerColor, null);
    }
}

function demoSpiral(Canvas $art): void
{
    $fgs = [4, 7, 8, 9, 11, 12, 13];
    $numSpirals = rand(1, 3);
    for ($s = 0; $s < $numSpirals; $s++) {
        $cx = rand(15, 65);
        $cy = rand(15, 33);
        $maxRadius = rand(12, 22);
        $turns = 2.5 + (mt_rand() / mt_getrandmax()) * 2.5;
        $points = [];
        $segs = 120;
        for ($i = 0; $i <= $segs; $i++) {
            $t = $i / $segs;
            $angle = $t * $turns * 2 * M_PI;
            $r = $t * $maxRadius;
            $points[] = [$cx + cos($angle) * $r, $cy + sin($angle) * $r];
        }
        $color = new Color($fgs[array_rand($fgs)], 1);
        $art->drawPath(Path::polyline($points), null, $color);
    }
}

function demoMondrian(Canvas $art): void
{
    $fgs = [4, 8, 9, 6];
    $art->fillColor(0, 0, new Color(0, 0));

    $black = new Color(1, 1);

    $stack = [[0, 0, 80, 48]];
    $minSize = 8;
    for ($depth = 0; $depth < 5; $depth++) {
        $next = [];
        foreach ($stack as $rect) {
            $x = $rect[0]; $y = $rect[1]; $w = $rect[2]; $h = $rect[3];
            if ($w < $minSize * 2 || $h < $minSize * 2) {
                $next[] = $rect;
                continue;
            }
            if ((mt_rand() / mt_getrandmax()) < 0.3) {
                $next[] = $rect;
                continue;
            }
            if ($w > $h) {
                $split = rand($minSize, $w - $minSize);
                $next[] = [$x, $y, $split, $h];
                $next[] = [$x + $split, $y, $w - $split, $h];
            } else {
                $split = rand($minSize, $h - $minSize);
                $next[] = [$x, $y, $w, $split];
                $next[] = [$x, $y + $split, $w, $h - $split];
            }
        }
        $stack = $next;
    }

    foreach ($stack as $rect) {
        $x = $rect[0]; $y = $rect[1]; $w = $rect[2]; $h = $rect[3];
        if ((mt_rand() / mt_getrandmax()) < 0.6) {
            $fill = new Color($fgs[array_rand($fgs)], null);
        } else {
            $fill = new Color(0, null);
        }
        $art->drawPath(Path::rect($x, $y, $w, $h), $fill, $black);
    }
}

function demoBubbles(Canvas $art): void
{
    $fgs = [4, 7, 8, 9, 11, 12, 13];
    $numBubbles = rand(8, 18);
    for ($i = 0; $i < $numBubbles; $i++) {
        $cx = rand(5, 75);
        $cy = rand(5, 43);
        $r = rand(3, 12);
        $color = new Color($fgs[array_rand($fgs)], 1);
        $art->drawPath(Path::circle($cx, $cy, $r), null, $color);
        $highlight = new Color(0, null);
        $art->drawPath(Path::circle($cx - $r * 0.3, $cy - $r * 0.3, $r * 0.25), $highlight, null);
    }
}

function demoVortex(Canvas $art): void
{
    $fgs = [4, 7, 8, 9, 11, 12, 13];
    $cx = rand(25, 55);
    $cy = rand(18, 30);
    $numArms = rand(3, 6);
    $direction = rand(0, 1) ? 1 : -1;
    $color = new Color($fgs[array_rand($fgs)], 1);
    for ($a = 0; $a < $numArms; $a++) {
        $startAngle = (2 * M_PI * $a / $numArms);
        $points = [];
        $segs = 60;
        $maxR = rand(18, 30);
        $turns = 1.5 + (mt_rand() / mt_getrandmax()) * 1.5;
        for ($i = 0; $i <= $segs; $i++) {
            $t = $i / $segs;
            $angle = $startAngle + $t * $turns * 2 * M_PI * $direction;
            $r = $t * $maxR;
            $points[] = [$cx + cos($angle) * $r, $cy + sin($angle) * $r];
        }
        $art->drawPath(Path::polyline($points), null, $color);
    }
}
