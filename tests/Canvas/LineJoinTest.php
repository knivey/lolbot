<?php

namespace Tests\Canvas;

use draw\LineJoin;
use PHPUnit\Framework\TestCase;

class LineJoinTest extends TestCase
{
    public function test_enum_has_three_cases(): void
    {
        $this->assertSame('Miter', LineJoin::Miter->name);
        $this->assertSame('Round', LineJoin::Round->name);
        $this->assertSame('Bevel', LineJoin::Bevel->name);
    }
}
