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

    public function test_inverse_of_identity_is_identity(): void
    {
        $t = Transform::identity();
        $inv = $t->inverse();
        $this->assertTrue($inv->equals(Transform::identity()));
    }

    public function test_inverse_of_translate(): void
    {
        $t = Transform::translate(10.0, 20.0);
        $inv = $t->inverse();
        [$x, $y] = $inv->apply(15.0, 25.0);
        $this->assertEqualsWithDelta(5.0, $x, 0.0001);
        $this->assertEqualsWithDelta(5.0, $y, 0.0001);
    }

    public function test_inverse_of_scale(): void
    {
        $t = Transform::scale(2.0, 4.0);
        $inv = $t->inverse();
        [$x, $y] = $inv->apply(6.0, 12.0);
        $this->assertEqualsWithDelta(3.0, $x, 0.0001);
        $this->assertEqualsWithDelta(3.0, $y, 0.0001);
    }

    public function test_inverse_of_rotate(): void
    {
        $t = Transform::rotate(M_PI / 4.0);
        $inv = $t->inverse();
        [$x, $y] = $t->apply(3.0, 7.0);
        [$rx, $ry] = $inv->apply($x, $y);
        $this->assertEqualsWithDelta(3.0, $rx, 0.0001);
        $this->assertEqualsWithDelta(7.0, $ry, 0.0001);
    }

    public function test_inverse_of_composed_transform(): void
    {
        $t = Transform::translate(100.0, 200.0)
            ->multiply(Transform::rotate(deg2rad(86.5167)))
            ->multiply(Transform::scale(230.426));
        $inv = $t->inverse();
        [$x, $y] = $t->apply(5.0, 10.0);
        [$rx, $ry] = $inv->apply($x, $y);
        $this->assertEqualsWithDelta(5.0, $rx, 0.0001);
        $this->assertEqualsWithDelta(10.0, $ry, 0.0001);
    }

    public function test_inverse_roundtrip_preserves_point(): void
    {
        $t = Transform::matrix(2.0, 1.0, 0.5, 3.0, 10.0, 20.0);
        $inv = $t->inverse();
        $composed = $t->multiply($inv);
        $this->assertTrue($composed->equals(Transform::identity()));
    }

    public function test_inverse_of_singular_matrix_throws(): void
    {
        $t = Transform::matrix(0.0, 0.0, 0.0, 0.0, 0.0, 0.0);
        $this->expectException(\LogicException::class);
        $t->inverse();
    }
}
