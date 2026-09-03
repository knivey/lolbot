<?php
namespace Tests\Config;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Pure helpers for the reasoning (JSON) form field on the linktitles panel.
 * Function-surface tests: the section file is require_once'd directly
 * (WebAuthTest pattern); no routing/session involved.
 */
class WebLinktitlesReasoningTest extends ConfigTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../web/sections/linktitles.php';
    }

    public function test_parses_json_object(): void
    {
        $out = web_lt_parse_reasoning_json('{"effort":"low","enabled":true}');
        $this->assertSame(['effort' => 'low', 'enabled' => true], $out);
    }

    public function test_empty_object_is_valid(): void
    {
        $this->assertSame([], web_lt_parse_reasoning_json('{}'));
        $this->assertSame([], web_lt_parse_reasoning_json('[]'));
    }

    public function test_rejects_non_empty_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('string keys');
        web_lt_parse_reasoning_json('[1,2]');
    }

    public function test_rejects_scalar_json(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON object');
        web_lt_parse_reasoning_json('5');
    }

    public function test_rejects_invalid_syntax(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON object');
        web_lt_parse_reasoning_json('{effort:low}');
    }
}
