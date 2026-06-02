<?php

namespace Tests\Canvas;

use draw\FillRule;
use PHPUnit\Framework\TestCase;

class FillRuleTest extends TestCase
{
    public function test_enum_has_nonzero_and_evenodd_cases(): void
    {
        $this->assertSame('NonZero', FillRule::NonZero->name);
        $this->assertSame('EvenOdd', FillRule::EvenOdd->name);
    }
}
