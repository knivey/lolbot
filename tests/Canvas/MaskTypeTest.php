<?php

namespace Tests\Canvas;

use draw\MaskType;
use PHPUnit\Framework\TestCase;

class MaskTypeTest extends TestCase
{
    public function test_luminance_case(): void
    {
        $this->assertSame('luminance', MaskType::Luminance->value);
    }

    public function test_alpha_case(): void
    {
        $this->assertSame('alpha', MaskType::Alpha->value);
    }
}
