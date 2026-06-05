<?php

namespace Tests\Alias;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../library/extract_opts_and_args.php';

class ExtractOptsAndArgsTest extends TestCase
{
    public function test_no_options(): void
    {
        [$opts, $args] = \extractOptsAndArgs('hello world');
        $this->assertSame([], $opts);
        $this->assertSame(['hello', 'world'], $args);
    }

    public function test_option_with_value(): void
    {
        [$opts, $args] = \extractOptsAndArgs('--amt=5 cats');
        $this->assertSame(['--amt' => '5'], $opts);
        $this->assertSame(['cats'], $args);
    }

    public function test_option_without_value(): void
    {
        [$opts, $args] = \extractOptsAndArgs('--verbose search terms');
        $this->assertSame(['--verbose' => null], $opts);
        $this->assertSame(['search', 'terms'], $args);
    }

    public function test_multiple_options(): void
    {
        [$opts, $args] = \extractOptsAndArgs('--amt=5 --verbose cats and dogs');
        $this->assertSame(['--amt' => '5', '--verbose' => null], $opts);
        $this->assertSame(['cats', 'and', 'dogs'], $args);
    }

    public function test_empty_string(): void
    {
        [$opts, $args] = \extractOptsAndArgs('');
        $this->assertSame([], $opts);
        $this->assertSame([], $args);
    }

    public function test_only_options(): void
    {
        [$opts, $args] = \extractOptsAndArgs('--amt=3 --verbose');
        $this->assertSame(['--amt' => '3', '--verbose' => null], $opts);
        $this->assertSame([], $args);
    }

    public function test_option_with_equals_in_value(): void
    {
        [$opts, $args] = \extractOptsAndArgs('--fmt=a=b hello');
        $this->assertSame(['--fmt' => 'a=b'], $opts);
        $this->assertSame(['hello'], $args);
    }

    public function test_double_dash_only_not_option(): void
    {
        [$opts, $args] = \extractOptsAndArgs('-- hello');
        $this->assertSame([], $opts);
        $this->assertSame(['--', 'hello'], $args);
    }

    public function test_single_dash_not_option(): void
    {
        [$opts, $args] = \extractOptsAndArgs('-v hello');
        $this->assertSame([], $opts);
        $this->assertSame(['-v', 'hello'], $args);
    }

    public function test_mixed_options_and_args(): void
    {
        [$opts, $args] = \extractOptsAndArgs('search --amt=5 for --verbose cats');
        $this->assertSame(['--amt' => '5', '--verbose' => null], $opts);
        $this->assertSame(['search', 'for', 'cats'], $args);
    }
}
