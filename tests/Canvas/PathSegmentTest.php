<?php
namespace Tests\Canvas;

use draw\ClosePath;
use draw\LineTo;
use draw\MoveTo;
use draw\QuadraticBezier;
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

    public function test_quadratic_bezier_end_point(): void
    {
        $seg = new QuadraticBezier(5.0, 10.0, 20.0, 30.0);
        $this->assertSame([20.0, 30.0], $seg->endPoint());
    }

    public function test_quadratic_bezier_straight_line_flattens_to_one_vertex(): void
    {
        // Control point on the line from (0,0) to (10,0) → perfectly flat
        $seg = new QuadraticBezier(5.0, 0.0, 10.0, 0.0);
        $result = $seg->flatten(0.0, 0.0, 0.5);
        $this->assertCount(1, $result);
        $this->assertSame([10.0, 0.0], $result[0]);
    }

    public function test_quadratic_bezier_curved_produces_multiple_vertices(): void
    {
        // Control point above the line → curved
        $seg = new QuadraticBezier(5.0, 10.0, 10.0, 0.0);
        $result = $seg->flatten(0.0, 0.0, 0.5);
        $this->assertGreaterThan(1, count($result), 'Curved quadratic should produce multiple vertices');
        // Last vertex must be the endpoint
        $last = $result[count($result) - 1];
        $this->assertEqualsWithDelta(10.0, $last[0], 0.001);
        $this->assertEqualsWithDelta(0.0, $last[1], 0.001);
    }

    public function test_quadratic_bezier_vertices_within_tolerance(): void
    {
        // Quarter-circle-like curve
        $seg = new QuadraticBezier(0.0, 10.0, 10.0, 0.0);
        $result = $seg->flatten(0.0, 0.0, 0.5);
        // Every vertex should be within tolerance of the true curve.
        // For a quadratic: B(t) = (1-t)^2*P0 + 2*(1-t)*t*P1 + t^2*P2
        foreach ($result as $vertex) {
            // Find t that gives this x coordinate: x = 2*(1-t)*t*0 + t^2*10
            // => t = sqrt(x/10)
            $t = sqrt($vertex[0] / 10.0);
            $t = max(0.0, min(1.0, $t));
            $expectedY = 2 * (1 - $t) * $t * 10.0;
            $this->assertEqualsWithDelta(
                $expectedY,
                $vertex[1],
                0.5,
                "Vertex ({$vertex[0]}, {$vertex[1]}) deviates from curve at t=$t"
            );
        }
    }

    public function test_quadratic_bezier_higher_tolerance_produces_fewer_vertices(): void
    {
        $seg = new QuadraticBezier(0.0, 10.0, 10.0, 0.0);
        $fine = $seg->flatten(0.0, 0.0, 0.1);
        $coarse = $seg->flatten(0.0, 0.0, 2.0);
        $this->assertGreaterThan(
            count($coarse),
            count($fine),
            'Finer tolerance should produce more vertices'
        );
    }
}
