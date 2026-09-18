<?php

namespace Tests\Linktitles;

use Amp\Http\HttpStatus;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../scripts/linktitles/DescribeResult.php';
require_once __DIR__ . '/../../scripts/aidesc/aidesc.php';

class AidescMapTest extends TestCase
{
    public function test_success_maps_200_with_description(): void
    {
        [$status, $body] = \scripts\aidesc\aidesc_map_result(
            \scripts\linktitles\DescribeResult::success('a red rectangle', ' profile', 1.0)
        );
        $this->assertSame(HttpStatus::OK, $status);
        $this->assertSame('a red rectangle', $body);
    }

    public function test_too_large_maps_400(): void
    {
        [$status, $body] = \scripts\aidesc\aidesc_map_result(
            new \scripts\linktitles\DescribeResult(null, \scripts\linktitles\DescribeResult::TOO_LARGE, '60000x60000x1f')
        );
        $this->assertSame(HttpStatus::BAD_REQUEST, $status);
        $this->assertStringContainsString('too large', $body);
    }

    public function test_undecodable_maps_400(): void
    {
        [$status, $body] = \scripts\aidesc\aidesc_map_result(
            new \scripts\linktitles\DescribeResult(null, \scripts\linktitles\DescribeResult::UNDECODABLE, 'boom')
        );
        $this->assertSame(HttpStatus::BAD_REQUEST, $status);
        $this->assertStringContainsString('undecodable', $body);
    }

    public function test_empty_maps_502(): void
    {
        [$status] = \scripts\aidesc\aidesc_map_result(
            new \scripts\linktitles\DescribeResult(null, \scripts\linktitles\DescribeResult::EMPTY)
        );
        $this->assertSame(HttpStatus::BAD_GATEWAY, $status);
    }

    public function test_timeout_maps_504(): void
    {
        [$status] = \scripts\aidesc\aidesc_map_result(
            new \scripts\linktitles\DescribeResult(null, \scripts\linktitles\DescribeResult::TIMEOUT, 'timed out')
        );
        $this->assertSame(HttpStatus::GATEWAY_TIMEOUT, $status);
    }

    public function test_upstream_maps_502(): void
    {
        [$status, $body] = \scripts\aidesc\aidesc_map_result(
            new \scripts\linktitles\DescribeResult(null, \scripts\linktitles\DescribeResult::UPSTREAM, 'api down')
        );
        $this->assertSame(HttpStatus::BAD_GATEWAY, $status);
        $this->assertSame('api down', $body);
    }
}
