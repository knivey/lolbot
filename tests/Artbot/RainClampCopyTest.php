<?php

namespace Tests\Artbot;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../artbot_scripts/rain.php';

class RainClampCopyTest extends TestCase
{
    public function test_aspect_bomb_copy_clamped(): void
    {
        [$w, $h] = rainClampCopy(60, 100000000, 200, 100);
        $this->assertLessThanOrEqual(400, $w);
        $this->assertLessThanOrEqual(200, $h);
    }

    public function test_normal_copy_unchanged(): void
    {
        [$w, $h] = rainClampCopy(60, 40, 200, 100);
        $this->assertSame(60, $w);
        $this->assertSame(40, $h);
    }

    public function test_rotated_bbox_bound_guaranteed_for_extreme_inputs(): void
    {
        //a 45deg-rotated copy needs a temp canvas of ~(w+h) per side, so the
        //clamp must keep (w+h)^2 <= 1_900_000 or one poisoned copy aborts the
        //whole @rain render with "canvas too large"
        $cases = [
            [100000, 100000, 240, 1080],
            [480, 100000, 240, 1080],
            [100000, 1, 100000, 1],
            [1378, 1378, 2000, 2000],
        ];
        foreach ($cases as [$w, $h, $renderW, $renderH]) {
            [$cw, $ch] = rainClampCopy($w, $h, $renderW, $renderH);
            $this->assertLessThanOrEqual(1_900_000, ($cw + $ch) ** 2, "sum bound violated for w=$cw h=$ch");
            $this->assertGreaterThanOrEqual(1, $cw);
            $this->assertGreaterThanOrEqual(1, $ch);
        }
    }
}
