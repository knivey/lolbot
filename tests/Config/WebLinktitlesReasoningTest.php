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

    /**
     * @param list<array{name:string,label:string,type:string,value:string,source:string,hint:string}> $fields
     * @return array{name:string,label:string,type:string,value:string,source:string,hint:string}|null
     */
    private function findField(array $fields, string $name): ?array
    {
        foreach ($fields as $f) {
            if ($f['name'] === $name) {
                return $f;
            }
        }
        return null;
    }

    public function test_global_fields_reasoning_when_set(): void
    {
        $s = new \scripts\linktitles\entities\linktitles_setting();
        $s->ai_vision_reasoning = ['effort' => 'low'];
        $f = $this->findField(web_lt_global_fields($s), 'ai_vision_reasoning');
        $this->assertNotNull($f);
        $this->assertSame('json', $f['type']);
        $this->assertSame('global', $f['source']);
        $this->assertSame('{"effort":"low"}', $f['value']);
        $this->assertSame('default: (none)', $f['hint']);
    }

    public function test_global_fields_reasoning_default_when_null(): void
    {
        $f = $this->findField(web_lt_global_fields(null), 'ai_vision_reasoning');
        $this->assertNotNull($f);
        $this->assertSame('default', $f['source']);
        $this->assertSame('', $f['value']);
    }

    /**
     * @param string $reasoningSource
     * @param array<string, mixed>|null $reasoning
     */
    private function resolvedWith(string $reasoningSource, ?array $reasoning): \lolbot\config\LinktitlesResolved
    {
        return new \lolbot\config\LinktitlesResolved(
            enabled: true,
            urlLogChan: null,
            aiVisionModel: 'model',
            aiVisionPrompt: 'prompt',
            aiVisionReasoningEffort: null,
            aiVisionReasoning: $reasoning,
            aiVisionDisabled: false,
            sources: [
                'enabled' => 'global',
                'ai_vision_disabled' => 'global',
                'url_log_chan' => 'default',
                'ai_vision_model' => 'global',
                'ai_vision_prompt' => 'global',
                'ai_vision_reasoning_effort' => 'default',
                'ai_vision_reasoning' => $reasoningSource,
            ],
        );
    }

    public function test_resolved_fields_reasoning_inherited_hint(): void
    {
        $f = $this->findField(
            web_lt_resolved_fields($this->resolvedWith('network', ['effort' => 'low'])),
            'ai_vision_reasoning',
        );
        $this->assertNotNull($f);
        $this->assertSame('json', $f['type']);
        $this->assertSame('network', $f['source']);
        $this->assertSame('{"effort":"low"}', $f['value']);
        $this->assertSame('inherits: {"effort":"low"} (from network)', $f['hint']);
    }

    public function test_resolved_fields_reasoning_none(): void
    {
        $f = $this->findField(
            web_lt_resolved_fields($this->resolvedWith('default', null)),
            'ai_vision_reasoning',
        );
        $this->assertNotNull($f);
        $this->assertSame('default', $f['source']);
        $this->assertSame('', $f['value']);
        $this->assertSame('inherits: (none) (from default)', $f['hint']);
    }
}
