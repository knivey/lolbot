<?php

namespace Tests\Canvas;

use draw\LineCap;
use PHPUnit\Framework\TestCase;

class LineCapTest extends TestCase
{
    public function test_enum_has_three_cases(): void
    {
        $this->assertSame('Butt', LineCap::Butt->name);
        $this->assertSame('Round', LineCap::Round->name);
        $this->assertSame('Square', LineCap::Square->name);
    }
}
