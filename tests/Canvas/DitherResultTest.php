<?php

namespace Tests\Canvas;

use draw\DitherResult;
use PHPUnit\Framework\TestCase;

class DitherResultTest extends TestCase
{
    public function test_stores_code(): void
    {
        $r = new DitherResult(4);
        $this->assertSame(4, $r->code);
    }

    public function test_defaults_for_non_dithered(): void
    {
        $r = new DitherResult(4);
        $this->assertFalse($r->dithered);
        $this->assertSame(-1, $r->secondBest);
        $this->assertSame(0.0, $r->t);
    }

    public function test_stores_dithering_metadata(): void
    {
        $r = new DitherResult(4, dithered: true, secondBest: 40, t: 0.5);
        $this->assertTrue($r->dithered);
        $this->assertSame(40, $r->secondBest);
        $this->assertSame(0.5, $r->t);
    }

    public function test_readonly(): void
    {
        $r = new DitherResult(4);
        $this->expectException(\Error::class);
        $r->code = 5;
    }
}
