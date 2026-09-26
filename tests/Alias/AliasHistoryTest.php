<?php

namespace Tests\Alias;

use PHPUnit\Framework\TestCase;
use scripts\alias\alias;

class AliasHistoryTest extends TestCase
{
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
            'created' => new \DateTimeImmutable('2026-01-01 12:00:00'),
            'event' => $event,
            'note' => null,
        ];
        return array_merge($row, $overrides);
    }

    public function test_empty_history_builds_empty_timeline(): void
    {
        $timeline = alias::buildTimeline([]);
        $this->assertSame([], $timeline['entries']);
        $this->assertNull($timeline['currentVersion']);
        $this->assertFalse($timeline['removed']);
        $this->assertSame(0, $timeline['totalSaves']);
    }

    public function test_single_save_is_version_one(): void
    {
        $rows = [self::historyRow('save', 'hello world')];
        $timeline = alias::buildTimeline($rows);
        $this->assertCount(1, $timeline['entries']);
        $entry = $timeline['entries'][0];
        $this->assertSame(1, $entry['version']);
        $this->assertSame('save', $entry['event']);
        $this->assertSame('hello world', $entry['value']);
        $this->assertSame('tester!user@example.com', $entry['fullhost']);
        $this->assertSame($rows[0]['created'], $entry['created']);
        $this->assertNull($entry['act']);
        $this->assertNull($entry['cmd']);
        $this->assertNull($entry['note']);
        $this->assertSame(1, $timeline['currentVersion']);
        $this->assertFalse($timeline['removed']);
        $this->assertSame(1, $timeline['totalSaves']);
    }

    public function test_save_entry_carries_row_fields(): void
    {
        $timeline = alias::buildTimeline([
            self::historyRow('save', 'slaps $nick', [
                'act' => true,
                'cmd' => 'ruby',
                'note' => 'setup note',
            ]),
        ]);
        $entry = $timeline['entries'][0];
        $this->assertSame('slaps $nick', $entry['value']);
        $this->assertTrue($entry['act']);
        $this->assertSame('ruby', $entry['cmd']);
        $this->assertSame('setup note', $entry['note']);
    }

    public function test_versions_number_saves_across_interleaved_markers(): void
    {
        $timeline = alias::buildTimeline([
            self::historyRow('save', 'one'),
            self::historyRow('removed'),
            self::historyRow('save', 'two'),
            self::historyRow('reverted', null, ['note' => 'restored version 2']),
            self::historyRow('save', 'three'),
        ]);
        $this->assertSame([1, null, 2, null, 3], array_map(fn(array $e) => $e['version'], $timeline['entries']));
        $this->assertSame(
            ['save', 'removed', 'save', 'reverted', 'save'],
            array_map(fn(array $e) => $e['event'], $timeline['entries'])
        );
        $this->assertSame(3, $timeline['currentVersion']);
        $this->assertFalse($timeline['removed']);
        $this->assertSame(3, $timeline['totalSaves']);
    }

    public function test_removed_after_last_save_sets_removed_flag(): void
    {
        $timeline = alias::buildTimeline([
            self::historyRow('save', 'one'),
            self::historyRow('save', 'two'),
            self::historyRow('removed'),
        ]);
        $this->assertTrue($timeline['removed']);
        $this->assertSame(2, $timeline['currentVersion']);
        $this->assertSame(2, $timeline['totalSaves']);
        $this->assertNull($timeline['entries'][2]['version']);
    }

    public function test_save_after_removed_clears_flag(): void
    {
        $timeline = alias::buildTimeline([
            self::historyRow('save', 'one'),
            self::historyRow('removed'),
            self::historyRow('save', 'two'),
        ]);
        $this->assertFalse($timeline['removed']);
        $this->assertSame(2, $timeline['currentVersion']);
    }

    public function test_removed_without_saves_still_sets_flag(): void
    {
        // alias predating history tracking, then deleted
        $timeline = alias::buildTimeline([self::historyRow('removed')]);
        $this->assertTrue($timeline['removed']);
        $this->assertNull($timeline['currentVersion']);
        $this->assertSame(0, $timeline['totalSaves']);
    }

    public function test_reverted_marker_does_not_clear_removed(): void
    {
        // only a new save clears the removed flag, per spec
        $timeline = alias::buildTimeline([
            self::historyRow('save', 'one'),
            self::historyRow('removed'),
            self::historyRow('reverted', null, ['note' => 'restored version 1']),
        ]);
        $this->assertTrue($timeline['removed']);
        $this->assertSame(1, $timeline['currentVersion']);
    }

    public function test_skips_malformed_rows(): void
    {
        $timeline = alias::buildTimeline([
            null,
            ['nope' => 1],
            ['event' => 'save'],
        ]);
        $this->assertCount(1, $timeline['entries']);
        $this->assertSame(1, $timeline['entries'][0]['version']);
        $this->assertSame(1, $timeline['currentVersion']);
        $this->assertSame(1, $timeline['totalSaves']);
    }

    public function test_missing_optional_keys_default_to_null(): void
    {
        $timeline = alias::buildTimeline([['event' => 'save']]);
        $entry = $timeline['entries'][0];
        $this->assertNull($entry['value']);
        $this->assertNull($entry['act']);
        $this->assertNull($entry['cmd']);
        $this->assertNull($entry['note']);
        $this->assertNull($entry['created']);
        $this->assertSame('', $entry['fullhost']);
    }

    public function test_empty_timeline_has_no_revert_target(): void
    {
        $timeline = alias::buildTimeline([]);
        $this->assertNull(alias::resolveRevertTarget($timeline));
        $this->assertNull(alias::resolveRevertTarget($timeline, 1));
        $this->assertNull(alias::resolveRevertTarget($timeline, null, true));
    }

    public function test_default_revert_targets_previous_save(): void
    {
        $timeline = alias::buildTimeline([
            self::historyRow('save', 'one'),
            self::historyRow('save', 'two'),
            self::historyRow('save', 'three'),
        ]);
        $target = alias::resolveRevertTarget($timeline);
        $this->assertNotNull($target);
        $this->assertSame(2, $target['version']);
        $this->assertSame('two', $target['value']);
    }

    public function test_explicit_version_targets_that_save(): void
    {
        $timeline = alias::buildTimeline([
            self::historyRow('save', 'one'),
            self::historyRow('save', 'two'),
            self::historyRow('save', 'three'),
        ]);
        $oldest = alias::resolveRevertTarget($timeline, 1);
        $this->assertNotNull($oldest);
        $this->assertSame(1, $oldest['version']);
        $this->assertSame('one', $oldest['value']);
        $newest = alias::resolveRevertTarget($timeline, 3);
        $this->assertNotNull($newest);
        $this->assertSame('three', $newest['value']);
    }

    public function test_invalid_version_returns_null(): void
    {
        $timeline = alias::buildTimeline([
            self::historyRow('save', 'one'),
            self::historyRow('save', 'two'),
        ]);
        $this->assertNull(alias::resolveRevertTarget($timeline, 99));
        $this->assertNull(alias::resolveRevertTarget($timeline, 0));
        $this->assertNull(alias::resolveRevertTarget($timeline, -1));
    }

    public function test_newest_targets_latest_save(): void
    {
        $timeline = alias::buildTimeline([
            self::historyRow('save', 'one'),
            self::historyRow('save', 'two'),
            self::historyRow('save', 'three'),
        ]);
        $target = alias::resolveRevertTarget($timeline, null, true);
        $this->assertNotNull($target);
        $this->assertSame(3, $target['version']);
        $this->assertSame('three', $target['value']);
    }

    public function test_single_save_default_has_no_previous(): void
    {
        $timeline = alias::buildTimeline([self::historyRow('save', 'only')]);
        $this->assertNull(alias::resolveRevertTarget($timeline));
    }

    public function test_single_save_explicit_and_newest_still_resolve(): void
    {
        $timeline = alias::buildTimeline([self::historyRow('save', 'only')]);
        $explicit = alias::resolveRevertTarget($timeline, 1);
        $this->assertNotNull($explicit);
        $this->assertSame('only', $explicit['value']);
        $newest = alias::resolveRevertTarget($timeline, null, true);
        $this->assertNotNull($newest);
        $this->assertSame('only', $newest['value']);
    }

    public function test_removed_alias_default_restores_latest_save(): void
    {
        $timeline = alias::buildTimeline([
            self::historyRow('save', 'one'),
            self::historyRow('save', 'two'),
            self::historyRow('removed'),
        ]);
        $this->assertTrue($timeline['removed']);
        $default = alias::resolveRevertTarget($timeline);
        $this->assertNotNull($default);
        $this->assertSame(2, $default['version']);
        $this->assertSame('two', $default['value']);
        $newest = alias::resolveRevertTarget($timeline, null, true);
        $this->assertNotNull($newest);
        $this->assertSame('two', $newest['value']);
        $explicit = alias::resolveRevertTarget($timeline, 1);
        $this->assertNotNull($explicit);
        $this->assertSame('one', $explicit['value']);
    }

    public function test_explicit_version_takes_precedence_over_newest(): void
    {
        $timeline = alias::buildTimeline([
            self::historyRow('save', 'one'),
            self::historyRow('save', 'two'),
        ]);
        $target = alias::resolveRevertTarget($timeline, 1, true);
        $this->assertNotNull($target);
        $this->assertSame(1, $target['version']);
        $this->assertSame('one', $target['value']);
    }

    public function test_reverted_marker_is_not_a_revert_target(): void
    {
        $timeline = alias::buildTimeline([
            self::historyRow('save', 'one'),
            self::historyRow('reverted', null, ['note' => 'restored version 1']),
        ]);
        $this->assertNull(alias::resolveRevertTarget($timeline));
    }

    public function test_malformed_timeline_returns_null(): void
    {
        $this->assertNull(alias::resolveRevertTarget([]));
        $this->assertNull(alias::resolveRevertTarget(['entries' => 'nope']));
    }
}
