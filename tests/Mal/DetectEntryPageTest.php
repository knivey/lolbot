<?php

namespace Tests\Mal;

use PHPUnit\Framework\TestCase;
use scripts\mal\mal;

class DetectEntryPageTest extends TestCase
{
    private static function fixture(string $name): string
    {
        $raw = file_get_contents(__DIR__ . '/../fixtures/mal/' . $name);
        if ($raw === false) {
            self::fail("fixture missing: $name");
        }
        return (string) gzdecode($raw);
    }

    public function test_detects_entry_page_landing(): void
    {
        // exact-title searches now 303 straight to the anime's entry page;
        // the old div.title flow would then pick up related-entry manga
        // cards instead of the anime itself (gh#131)
        $entry = mal::detectEntryPage(self::fixture('search-entry.html.gz'));
        $this->assertNotNull($entry);
        $this->assertSame('61192', $entry['id']);
        $this->assertSame('https://myanimelist.net/anime/61192/All_You_Need_Is_Kill', $entry['url']);
        $this->assertSame('All You Need Is Kill', $entry['title']);
    }

    public function test_list_page_is_not_an_entry_page(): void
    {
        $this->assertNull(mal::detectEntryPage(self::fixture('search-list.html.gz')));
    }

    public function test_unrelated_html_is_not_an_entry_page(): void
    {
        $this->assertNull(mal::detectEntryPage('<html><body>nope</body></html>'));
    }
}
