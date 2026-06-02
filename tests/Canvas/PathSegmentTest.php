<?php
namespace Tests\Canvas;

use draw\ClosePath;
use draw\LineTo;
use draw\MoveTo;
use PHPUnit\Framework\TestCase;

class PathSegmentTest extends TestCase
{
    public function test_move_to_flatten_returns_empty(): void
    {
        $seg = new MoveTo(10.0, 20.0);
        $this->assertSame([], $seg->flatten(0.0, 0.0, 0.5));
    }

    public function test_move_to_end_point(): void
    {
        $seg = new MoveTo(10.0, 20.0);
        $this->assertSame([10.0, 20.0], $seg->endPoint());
    }

    public function test_line_to_flatten_returns_endpoint(): void
    {
        $seg = new LineTo(15.0, 25.0);
        $result = $seg->flatten(5.0, 5.0, 0.5);
        $this->assertCount(1, $result);
        $this->assertSame([15.0, 25.0], $result[0]);
    }

    public function test_line_to_end_point(): void
    {
        $seg = new LineTo(15.0, 25.0);
        $this->assertSame([15.0, 25.0], $seg->endPoint());
    }

    public function test_close_path_flatten_returns_empty(): void
    {
        $seg = new ClosePath(10.0, 20.0);
        $this->assertSame([], $seg->flatten(30.0, 40.0, 0.5));
    }

    public function test_close_path_end_point_returns_subpath_start(): void
    {
        $seg = new ClosePath(10.0, 20.0);
        $this->assertSame([10.0, 20.0], $seg->endPoint());
    }
}
