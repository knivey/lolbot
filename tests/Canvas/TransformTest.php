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

    public function test_translate_shifts_point(): void
    {
        $t = Transform::translate(10.0, 20.0);
        [$x, $y] = $t->apply(5.0, 5.0);
        $this->assertEqualsWithDelta(15.0, $x, 0.0001);
        $this->assertEqualsWithDelta(25.0, $y, 0.0001);
    }

    public function test_scale_uniform(): void
    {
        $t = Transform::scale(2.0);
        [$x, $y] = $t->apply(3.0, 4.0);
        $this->assertEqualsWithDelta(6.0, $x, 0.0001);
        $this->assertEqualsWithDelta(8.0, $y, 0.0001);
    }

    public function test_scale_non_uniform(): void
    {
        $t = Transform::scale(2.0, 3.0);
        [$x, $y] = $t->apply(3.0, 4.0);
        $this->assertEqualsWithDelta(6.0, $x, 0.0001);
        $this->assertEqualsWithDelta(12.0, $y, 0.0001);
    }

    public function test_rotate_90_degrees(): void
    {
        $t = Transform::rotate(M_PI / 2.0);
        [$x, $y] = $t->apply(1.0, 0.0);
        $this->assertEqualsWithDelta(0.0, $x, 0.0001);
        $this->assertEqualsWithDelta(1.0, $y, 0.0001);
    }

    public function test_rotate_with_center(): void
    {
        $t = Transform::rotate(M_PI / 2.0, 10.0, 10.0);
        [$x, $y] = $t->apply(11.0, 10.0);
        $this->assertEqualsWithDelta(10.0, $x, 0.0001);
        $this->assertEqualsWithDelta(11.0, $y, 0.0001);
    }
}
