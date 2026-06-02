# Art Demo Command Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `!demo` art command that takes a demo name as argument (e.g. `!demo flowers`, `!demo spiral`) or picks a random demo if no arg given. Each demo showcases a different Path API feature.

**Architecture:** Single `#[Cmd("demo")]` function in `artbot_scripts/drawing.php` that dispatches to private helper functions, one per demo. Five demos: flowers, spiral, mondrian, bubbles, vortex.

**Tech Stack:** PHP 8.1+, existing `draw\Path` and `draw\Canvas` classes, IRC color palette.

---

### Task 1: Create the demo command dispatcher

**Files:**
- Modify: `artbot_scripts/drawing.php` — add `demo` command at end of file

- [ ] **Step 1: Add the demo command and dispatcher**

Append to `artbot_scripts/drawing.php` before the closing `?>` (or at end of file if no closing tag):

```php
$demos = ['flowers', 'spiral', 'mondrian', 'bubbles', 'vortex'];

#[Cmd("demo")]
#[Desc("Draw a Path API demo (flowers, spiral, mondrian, bubbles, vortex). Random if no arg.")]
#[Syntax('[name: string]')]
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
```

- [ ] **Step 2: Add placeholder demo functions**

After the `demo` function, add stub functions so the file parses:

```php
function demoFlowers(Canvas $art): void {}
function demoSpiral(Canvas $art): void {}
function demoMondrian(Canvas $art): void {}
function demoBubbles(Canvas $art): void {}
function demoVortex(Canvas $art): void {}
```

- [ ] **Step 3: Verify**

Run: `composer phpstan 2>&1 | tail -3`
Expected: 666 errors (baseline unchanged, or close)

- [ ] **Step 4: Commit**

```bash
git add artbot_scripts/drawing.php
git commit -m "Add demo command dispatcher with placeholder functions"
```

---

### Task 2: Implement demoFlowers

**Files:**
- Modify: `artbot_scripts/drawing.php` — replace `demoFlowers` placeholder

- [ ] **Step 1: Implement demoFlowers**

Replace the empty `demoFlowers` function with:

```php
function demoFlowers(Canvas $art): void
{
    $fgs = [4, 7, 8, 9, 11, 12, 13];
    $numFlowers = rand(3, 7);
    for ($f = 0; $f < $numFlowers; $f++) {
        $cx = rand(10, 70);
        $cy = rand(10, 38);
        $numPetals = rand(5, 8);
        $petalRx = rand(4, 10);
        $petalRy = rand(8, 18);
        $petalColor = new Color($fgs[array_rand($fgs)], null);
        $centerColor = new Color($fgs[array_rand($fgs)], null);
        for ($p = 0; $p < $numPetals; $p++) {
            $angle = (2 * M_PI * $p / $numPetals);
            $px = $cx + cos($angle) * $petalRy * 0.4;
            $py = $cy + sin($angle) * $petalRy * 0.4;
            $art->drawPath(Path::ellipse($px, $py, $petalRx, $petalRy), $petalColor, null);
        }
        $art->drawPath(Path::circle($cx, $cy, 3), $centerColor, null);
    }
}
```

- [ ] **Step 2: Verify**

Run: `composer test`
Expected: all pass

- [ ] **Step 3: Commit**

```bash
git add artbot_scripts/drawing.php
git commit -m "Implement demoFlowers: rotated ellipse petals with center dots"
```

---

### Task 3: Implement demoSpiral

**Files:**
- Modify: `artbot_scripts/drawing.php` — replace `demoSpiral` placeholder

- [ ] **Step 1: Implement demoSpiral**

Replace the empty `demoSpiral` function with:

```php
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
```

- [ ] **Step 2: Verify**

Run: `composer test`
Expected: all pass

- [ ] **Step 3: Commit**

```bash
git add artbot_scripts/drawing.php
git commit -m "Implement demoSpiral: Archimedean spirals via polyline"
```

---

### Task 4: Implement demoMondrian

**Files:**
- Modify: `artbot_scripts/drawing.php` — replace `demoMondrian` placeholder

- [ ] **Step 1: Implement demoMondrian**

Replace the empty `demoMondrian` function with:

```php
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
            [$x, $y, $w, $h] = $rect;
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
        [$x, $y, $w, $h] = $rect;
        if ((mt_rand() / mt_getrandmax()) < 0.6) {
            $fill = new Color($fgs[array_rand($fgs)], null);
        } else {
            $fill = new Color(0, null);
        }
        $art->drawPath(Path::rect($x, $y, $w, $h), $fill, $black);
    }
}
```

- [ ] **Step 2: Verify**

Run: `composer test`
Expected: all pass

- [ ] **Step 3: Commit**

```bash
git add artbot_scripts/drawing.php
git commit -m "Implement demoMondrian: recursive rect subdivision with primary fills"
```

---

### Task 5: Implement demoBubbles

**Files:**
- Modify: `artbot_scripts/drawing.php` — replace `demoBubbles` placeholder

- [ ] **Step 1: Implement demoBubbles**

Replace the empty `demoBubbles` function with:

```php
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
```

- [ ] **Step 2: Verify**

Run: `composer test`
Expected: all pass

- [ ] **Step 3: Commit**

```bash
git add artbot_scripts/drawing.php
git commit -m "Implement demoBubbles: outlined circles with highlight dots"
```

---

### Task 6: Implement demoVortex

**Files:**
- Modify: `artbot_scripts/drawing.php` — replace `demoVortex` placeholder

- [ ] **Step 1: Implement demoVortex**

Replace the empty `demoVortex` function with:

```php
function demoVortex(Canvas $art): void
{
    $fgs = [4, 7, 8, 9, 11, 12, 13];
    $cx = rand(25, 55);
    $cy = rand(18, 30);
    $numArms = rand(6, 14);
    for ($a = 0; $a < $numArms; $a++) {
        $angle = (2 * M_PI * $a / $numArms);
        $length = rand(15, 30);
        $curvature = ((mt_rand() / mt_getrandmax()) * 2 - 1) * 12;
        $ex = $cx + cos($angle) * $length;
        $ey = $cy + sin($angle) * $length;
        $perpAngle = $angle + M_PI / 2;
        $cpx = ($cx + $ex) / 2 + cos($perpAngle) * $curvature;
        $cpy = ($cy + $ey) / 2 + sin($perpAngle) * $curvature;
        $color = new Color($fgs[array_rand($fgs)], 1);
        $path = new Path();
        $path->moveTo($cx, $cy);
        $path->quadTo($cpx, $cpy, $ex, $ey);
        $art->drawPath($path, null, $color);
    }
}
```

- [ ] **Step 2: Verify**

Run: `composer test`
Expected: all pass

- [ ] **Step 3: Commit**

```bash
git add artbot_scripts/drawing.php
git commit -m "Implement demoVortex: curved arms radiating from center via quadTo"
```

---

### Task 7: Wire up command registration

**Files:**
- Modify: `artbot_scripts/drawing.php` — the `$demos` global and command are already in the file from Task 1

No changes needed — the `#[Cmd("demo")]` attribute is auto-discovered by `Cmdr::loadFuncs()` in `artbots.php`, and `drawing.php` is already `require_once`'d.

- [ ] **Step 1: Final verification**

Run: `composer test && composer phpstan`
Expected: tests pass, PHPStan <= 666

- [ ] **Step 2: Commit (if any cleanup needed)**

Only if there are uncommitted changes.
