<?php

namespace Tests\Urbandict;

use PHPUnit\Framework\TestCase;
use scripts\urbandict\urbandict;

class ParseDefsTest extends TestCase
{
    private static string $html;

    public static function setUpBeforeClass(): void
    {
        $raw = file_get_contents(__DIR__ . '/../fixtures/urbandict/define-duckhunt.html.gz');
        if ($raw === false) {
            self::fail('fixture missing: tests/fixtures/urbandict/define-duckhunt.html.gz');
        }
        self::$html = (string) gzdecode($raw);
    }

    public function test_parses_exact_term_article_definition(): void
    {
        $defs = urbandict::parseDefs(self::$html);
        $this->assertNotEmpty($defs);
        $first = $defs[0];
        $this->assertSame('Duckhunt', $first['word']);
        $this->assertStringStartsWith('When you and you friends go out to holla at girls', $first['meaning']);
        $this->assertSame('Snowbunniluver May 10, 2014', $first['by']);
        $this->assertNotSame('', $first['example']);
        $this->assertFalse($first['wotd']);
    }

    public function test_parses_feed_definitions_after_article(): void
    {
        $defs = urbandict::parseDefs(self::$html);
        $this->assertSame('Super Mario / Duck Hunt', $defs[1]['word']);
        $this->assertStringStartsWith('1). A double threat.', $defs[1]['meaning']);
        $this->assertSame('ThE LaTe JC April 13, 2005', $defs[1]['by']);
        $this->assertSame('Duck Hunt', $defs[2]['word']);
    }

    public function test_flags_word_of_the_day_defs(): void
    {
        $defs = urbandict::parseDefs(self::$html);
        // the feed is padded with the last several WOTD entries; they must be
        // flagged so ud() can skip them (gh#132)
        $wotdWords = [];
        foreach ($defs as $d) {
            if ($d['wotd']) {
                $wotdWords[] = $d['word'];
            }
        }
        $this->assertContains('crunchy', $wotdWords);
        $this->assertContains('murderhobo', $wotdWords);
        // the real defs for the term are not WOTD
        foreach ([0, 1, 2] as $i) {
            $this->assertFalse($defs[$i]['wotd']);
        }
    }

    public function test_decodes_html_entities_in_fields(): void
    {
        $defs = urbandict::parseDefs(self::$html);
        foreach ($defs as $d) {
            $this->assertStringNotContainsString('&amp;', $d['meaning']);
            $this->assertStringNotContainsString('&quot;', $d['example']);
        }
    }

    public function test_returns_empty_array_when_no_definitions(): void
    {
        $this->assertSame([], urbandict::parseDefs('<html><body>nothing here</body></html>'));
    }

    /**
     * @return array{word: string, meaning: string, example: string, by: string, wotd: bool}
     */
    private static function mkDef(string $word, bool $wotd = false): array
    {
        return ['word' => $word, 'meaning' => "m $word", 'example' => "e $word", 'by' => "a $word", 'wotd' => $wotd];
    }

    public function test_selectDefs_takes_first_non_wotd_defs_in_order(): void
    {
        $defs = [
            self::mkDef('w1', true),
            self::mkDef('a'),
            self::mkDef('w2', true),
            self::mkDef('b'),
            self::mkDef('c'),
        ];
        $sel = urbandict::selectDefs($defs, 2);
        $this->assertSame(['a', 'b'], array_column($sel, 'word'));
        $sel = urbandict::selectDefs($defs, 1);
        $this->assertSame(['a'], array_column($sel, 'word'));
    }

    public function test_selectDefs_falls_back_to_first_def_when_all_are_wotd(): void
    {
        $defs = [
            self::mkDef('w1', true),
            self::mkDef('w2', true),
        ];
        $sel = urbandict::selectDefs($defs, 2);
        $this->assertSame(['w1'], array_column($sel, 'word'));
    }

    public function test_selectDefs_empty_input(): void
    {
        $this->assertSame([], urbandict::selectDefs([], 2));
    }

    public function test_selectDefs_max_zero_selects_nothing(): void
    {
        $this->assertSame([], urbandict::selectDefs([self::mkDef('a'), self::mkDef('w', true)], 0));
        $this->assertSame([], urbandict::selectDefs([self::mkDef('w', true)], 0));
    }

    public function test_selectDefs_shorter_than_max_and_fallback_at_max_one(): void
    {
        $one = [self::mkDef('a')];
        $this->assertSame(['a'], array_column(urbandict::selectDefs($one, 2), 'word'));
        $allWotd = [self::mkDef('w1', true), self::mkDef('w2', true)];
        $this->assertSame(['w1'], array_column(urbandict::selectDefs($allWotd, 1), 'word'));
    }
}
