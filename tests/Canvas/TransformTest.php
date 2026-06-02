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

    public function test_skew_x(): void
    {
        $t = Transform::skewX(deg2rad(45.0));
        [$x, $y] = $t->apply(0.0, 1.0);
        $this->assertEqualsWithDelta(1.0, $x, 0.0001);
        $this->assertEqualsWithDelta(1.0, $y, 0.0001);
    }

    public function test_skew_y(): void
    {
        $t = Transform::skewY(deg2rad(45.0));
        [$x, $y] = $t->apply(1.0, 0.0);
        $this->assertEqualsWithDelta(1.0, $x, 0.0001);
        $this->assertEqualsWithDelta(1.0, $y, 0.0001);
    }

    public function test_matrix_raw(): void
    {
        $t = Transform::matrix(2.0, 0.0, 0.0, 3.0, 10.0, 20.0);
        [$x, $y] = $t->apply(1.0, 1.0);
        $this->assertEqualsWithDelta(12.0, $x, 0.0001);
        $this->assertEqualsWithDelta(23.0, $y, 0.0001);
    }

    public function test_multiply_identity_is_noop(): void
    {
        $id = Transform::identity();
        $t = Transform::translate(5.0, 10.0);
        $result = $t->multiply($id);
        [$x, $y] = $result->apply(0.0, 0.0);
        $this->assertEqualsWithDelta(5.0, $x, 0.0001);
        $this->assertEqualsWithDelta(10.0, $y, 0.0001);
    }

    public function test_multiply_translate_then_scale(): void
    {
        $translate = Transform::translate(10.0, 0.0);
        $scale = Transform::scale(2.0);
        $result = $translate->multiply($scale);
        [$x, $y] = $result->apply(5.0, 0.0);
        $this->assertEqualsWithDelta(20.0, $x, 0.0001);
    }

    public function test_multiply_scale_then_translate(): void
    {
        $scale = Transform::scale(2.0);
        $translate = Transform::translate(10.0, 0.0);
        $result = $scale->multiply($translate);
        [$x, $y] = $result->apply(5.0, 0.0);
        $this->assertEqualsWithDelta(30.0, $x, 0.0001);
    }

    public function test_multiply_is_immutable(): void
    {
        $a = Transform::translate(1.0, 2.0);
        $b = Transform::scale(3.0);
        $a->multiply($b);
        $this->assertSame([1.0, 0.0, 0.0, 1.0, 1.0, 2.0], $a->getElements());
    }

    public function test_multiply_rotate_then_translate(): void
    {
        $rot = Transform::rotate(M_PI / 2.0);
        $trans = Transform::translate(5.0, 0.0);
        $result = $rot->multiply($trans);
        [$x, $y] = $result->apply(1.0, 0.0);
        $this->assertEqualsWithDelta(0.0, $x, 0.0001);
        $this->assertEqualsWithDelta(6.0, $y, 0.0001);
    }
}
