<?php

namespace Tests\Tools;

use PHPUnit\Framework\TestCase;
use scripts\tools\tools;

class ParseWiktionaryDefsTest extends TestCase
{
    private static function fixture(): string
    {
        $raw = file_get_contents(__DIR__ . '/../fixtures/tools/wiktionary-hello.json.gz');
        if ($raw === false) {
            self::fail('fixture missing: tests/fixtures/tools/wiktionary-hello.json.gz');
        }
        return (string) gzdecode($raw);
    }

    public function test_parses_english_sections(): void
    {
        $defs = tools::parseWiktionaryDefs(self::fixture());
        $this->assertNotEmpty($defs);
        $this->assertSame('Interjection', $defs[0]['pos']);
        $this->assertStringStartsWith('A greeting (salutation) said when meeting', $defs[0]['definition']);
        $this->assertSame('Hello, everyone.', $defs[0]['example']);
        $this->assertSame('Noun', $defs[1]['pos']);
        $this->assertSame('"Hello!" or an equivalent greeting.', $defs[1]['definition']);
        $this->assertSame('They gave each other a quick hello when they met, and went back on their merry ways.', $defs[1]['example']);
    }

    public function test_strips_all_html_from_fields(): void
    {
        foreach (tools::parseWiktionaryDefs(self::fixture()) as $d) {
            $this->assertDoesNotMatchRegularExpression('#<[^>]+>#', $d['definition']);
            $this->assertDoesNotMatchRegularExpression('#<[^>]+>#', $d['example']);
        }
    }

    public function test_missing_example_returns_empty_string(): void
    {
        // sanity: the Verb section in the fixture has no examples key at all
        $sections = json_decode(self::fixture(), true)['en'];
        $this->assertArrayNotHasKey('examples', $sections[2]['definitions'][0]);
        $defs = tools::parseWiktionaryDefs(self::fixture());
        $this->assertSame('Verb', $defs[2]['pos']);
        $this->assertSame('', $defs[2]['example']);
    }

    public function test_non_wiktionary_body_returns_empty_array(): void
    {
        $this->assertSame([], tools::parseWiktionaryDefs('{"status":404}'));
        $this->assertSame([], tools::parseWiktionaryDefs('not json at all'));
        $this->assertSame([], tools::parseWiktionaryDefs('{"fr": []}'));
    }
}
