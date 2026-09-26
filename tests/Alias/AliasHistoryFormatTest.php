<?php

namespace Tests\Alias;

use PHPUnit\Framework\TestCase;
use scripts\alias\alias;

class AliasHistoryFormatTest extends TestCase
{
    /**
     * Builds one timeline entry shaped like buildTimeline() output, with
     * defaults and overrides.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function entry(string $event, ?int $version, ?string $value = null, array $overrides = []): array
    {
        $entry = [
            'version' => $version,
            'event' => $event,
            'fullhost' => 'tester!user@example.com',
            'created' => new \DateTimeImmutable('2026-03-04 05:06:07 UTC'),
            'value' => $value,
            'act' => null,
            'cmd' => null,
            'note' => null,
        ];
        return array_merge($entry, $overrides);
    }

    /**
     * Builds one alias_history row shaped like entity hydration (rows are
     * fed to buildTimeline ordered by id ASC), with defaults and overrides.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function historyRow(string $event, ?string $value = null, array $overrides = []): array
    {
        $row = [
            'id' => 0,
            'chan' => '#test',
            'chanLowered' => '#test',
            'name' => 'poke',
            'nameLowered' => 'poke',
            'value' => $value,
            'act' => null,
            'cmd' => null,
            'fullhost' => 'tester!user@example.com',
            'created' => new \DateTimeImmutable('2026-03-04 05:06:07 UTC'),
            'event' => $event,
            'note' => null,
        ];
        return array_merge($row, $overrides);
    }

    public function test_history_line_formats_save(): void
    {
        $this->assertSame(
            "\2v2\2 saved by tester!user@example.com at 2026-03-04 05:06 UTC",
            alias::historyLine(self::entry('save', 2, 'slaps $nick'))
        );
    }

    public function test_history_line_formats_removed(): void
    {
        $this->assertSame(
            "\2removed\2 by tester!user@example.com at 2026-03-04 05:06 UTC",
            alias::historyLine(self::entry('removed', null))
        );
    }

    public function test_history_line_formats_reverted_with_restored_note(): void
    {
        $this->assertSame(
            "\2reverted\2 to v1 by tester!user@example.com at 2026-03-04 05:06 UTC",
            alias::historyLine(self::entry('reverted', null, null, ['note' => 'restored version 1']))
        );
    }

    public function test_history_line_formats_reverted_without_parseable_note(): void
    {
        $this->assertSame(
            "\2reverted\2 by tester!user@example.com at 2026-03-04 05:06 UTC",
            alias::historyLine(self::entry('reverted', null))
        );
        $this->assertSame(
            "\2reverted\2 by tester!user@example.com at 2026-03-04 05:06 UTC",
            alias::historyLine(self::entry('reverted', null, null, ['note' => 'no digits here']))
        );
    }

    public function test_history_line_without_created_falls_back_to_unknown(): void
    {
        $this->assertSame(
            "\2removed\2 by tester!user@example.com at unknown",
            alias::historyLine(self::entry('removed', null, null, ['created' => null]))
        );
    }

    public function test_history_line_unknown_event_falls_back_to_event_label(): void
    {
        $this->assertSame(
            "\2renamed\2 by tester!user@example.com at 2026-03-04 05:06 UTC",
            alias::historyLine(self::entry('renamed', null))
        );
    }

    public function test_history_lines_format_whole_timeline(): void
    {
        $rows = [
            self::historyRow('save', 'one', ['created' => new \DateTimeImmutable('2026-01-01 12:00:00 UTC')]),
            self::historyRow('removed'),
            self::historyRow('save', 'two', ['created' => new \DateTimeImmutable('2026-02-02 13:00:00 UTC')]),
            self::historyRow('reverted', null, ['note' => 'restored version 2']),
        ];
        $lines = array_map(
            fn(array $entry): string => alias::historyLine($entry),
            alias::buildTimeline($rows)['entries']
        );
        $this->assertSame([
            "\2v1\2 saved by tester!user@example.com at 2026-01-01 12:00 UTC",
            "\2removed\2 by tester!user@example.com at 2026-03-04 05:06 UTC",
            "\2v2\2 saved by tester!user@example.com at 2026-02-02 13:00 UTC",
            "\2reverted\2 to v2 by tester!user@example.com at 2026-03-04 05:06 UTC",
        ], $lines);
    }

    public function test_history_markdown_renders_header_and_sections(): void
    {
        $markdown = alias::historyMarkdown([
            self::entry('save', 1, 'hello'),
            self::entry('removed', null),
            self::entry('reverted', null, null, ['note' => 'restored version 1']),
        ], '#test', 'poke');
        $expected = <<<'EOT'
            # Alias history for poke in #test

            ## v1 saved

            - **By:** `tester!user@example.com`
            - **At:** 2026-03-04 05:06 UTC

            **Value:**
            ```
            hello
            ```

            ---

            ## removed

            - **By:** `tester!user@example.com`
            - **At:** 2026-03-04 05:06 UTC

            ---

            ## reverted to v1

            - **By:** `tester!user@example.com`
            - **At:** 2026-03-04 05:06 UTC
            - **Note:** restored version 1
            EOT;
        // the heredoc drops the trailing newline the formatter emits
        $this->assertSame($expected . "\n", $markdown);
    }

    public function test_history_markdown_omits_value_and_note_when_unset(): void
    {
        $markdown = alias::historyMarkdown([self::entry('removed', null)], '#test', 'poke');
        $expected = <<<'EOT'
            # Alias history for poke in #test

            ## removed

            - **By:** `tester!user@example.com`
            - **At:** 2026-03-04 05:06 UTC
            EOT;
        $this->assertSame($expected . "\n", $markdown);
    }

    public function test_history_markdown_multiline_value_stays_in_one_fence(): void
    {
        $markdown = alias::historyMarkdown([self::entry('save', 1, "line one\nline two")], '#test', 'poke');
        $this->assertStringContainsString("```\nline one\nline two\n```", $markdown);
    }

    public function test_version_suffix_formats_current_version_and_latest_save_date(): void
    {
        $timeline = alias::buildTimeline([
            self::historyRow('save', 'one', ['created' => new \DateTimeImmutable('2026-01-01 12:00:00 UTC')]),
            self::historyRow('save', 'two', ['created' => new \DateTimeImmutable('2026-02-02 13:00:00 UTC')]),
            self::historyRow('reverted', null, ['note' => 'restored version 1']),
        ]);
        $this->assertSame(
            " \2Version:\2 2 of 2 \2Updated:\2 2026-02-02 13:00 UTC",
            alias::versionSuffix($timeline)
        );
    }

    public function test_version_suffix_empty_when_removed(): void
    {
        $timeline = alias::buildTimeline([
            self::historyRow('save', 'one'),
            self::historyRow('removed'),
        ]);
        $this->assertSame('', alias::versionSuffix($timeline));
    }

    public function test_version_suffix_empty_without_history(): void
    {
        $this->assertSame('', alias::versionSuffix(alias::buildTimeline([])));
    }

    public function test_version_suffix_empty_with_markers_only(): void
    {
        $timeline = alias::buildTimeline([self::historyRow('removed')]);
        $this->assertSame('', alias::versionSuffix($timeline));
    }

    public function test_version_suffix_empty_when_no_save_has_created(): void
    {
        $timeline = alias::buildTimeline([
            self::historyRow('save', 'one', ['created' => null]),
            self::historyRow('save', 'two', ['created' => null]),
        ]);
        $this->assertSame('', alias::versionSuffix($timeline));
    }
}
