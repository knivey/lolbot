<?php

namespace Tests\Canvas;

use draw\Dithering;
use PHPUnit\Framework\TestCase;

class DitheringTest extends TestCase
{
    public function test_enum_has_none_case(): void
    {
        $this->assertSame('None', Dithering::None->name);
    }

    public function test_enum_has_ordered4x4_case(): void
    {
        $this->assertSame('Ordered4x4', Dithering::Ordered4x4->name);
    }
}
