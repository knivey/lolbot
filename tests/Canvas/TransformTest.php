<?php
namespace Tests\Canvas;

use draw\Transform;
use PHPUnit\Framework\TestCase;

class TransformTest extends TestCase
{
    public function test_identity_returns_identity_matrix(): void
    {
        $t = Transform::identity();
        $this->assertSame([1.0, 0.0, 0.0, 1.0, 0.0, 0.0], $t->getElements());
    }

    public function test_identity_apply_is_noop(): void
    {
        $t = Transform::identity();
        [$x, $y] = $t->apply(5.0, 10.0);
        $this->assertEqualsWithDelta(5.0, $x, 0.0001);
        $this->assertEqualsWithDelta(10.0, $y, 0.0001);
    }

    public function test_identity_is_immutable(): void
    {
        $t = Transform::identity();
        $elements = $t->getElements();
        $elements[0] = 99.0;
        $this->assertSame([1.0, 0.0, 0.0, 1.0, 0.0, 0.0], $t->getElements());
    }
}
