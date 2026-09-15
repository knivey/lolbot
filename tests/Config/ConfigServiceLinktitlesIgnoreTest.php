<?php

namespace Tests\Config;

use lolbot\config\ChangeNotifier;
use lolbot\config\ConfigChange;
use lolbot\config\ConfigService;
use lolbot\config\InvalidSettingException;
use lolbot\config\NotFoundException;
use scripts\linktitles\entities\ignore_type;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Captures ConfigChange notifications so tests can assert the seam fires.
 * (Distinct class name from CapturingNotifier to avoid cross-file redeclare.)
 */
class LtIgnoreCapturingNotifier implements ChangeNotifier
{
    /** @var list<ConfigChange> */
    public array $changes = [];
    public function notify(ConfigChange $change): void
    {
        $this->changes[] = $change;
    }
    public function reset(): void
    {
        $this->changes = [];
    }
}

class ConfigServiceLinktitlesIgnoreTest extends ConfigTestCase
{
    private ConfigService $svc;
    private LtIgnoreCapturingNotifier $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifier = new LtIgnoreCapturingNotifier();
        $this->svc = new ConfigService($this->em, $this->notifier);
    }

    public function test_add_global_wraps_delimiters_and_notifies(): void
    {
        $ig = $this->svc->addLinktitlesIgnore('example\.com/path', ignore_type::global);
        $this->assertSame('@example\.com/path@i', $ig->regex);
        $this->assertSame(ignore_type::global, $ig->type);
        $this->assertNull($ig->network);
        $this->assertNull($ig->bot);
        $this->assertCount(1, $this->notifier->changes);
        $this->assertSame('linktitles_ignore', $this->notifier->changes[0]->entityType);
        $this->assertSame('create', $this->notifier->changes[0]->action);
        $this->assertSame($ig->id, $this->notifier->changes[0]->id);
    }

    public function test_add_picks_delimiter_not_in_pattern(): void
    {
        $ig = $this->svc->addLinktitlesIgnore('https?://(www\.)?@example@\.com', ignore_type::global);
        $this->assertSame('#https?://(www\.)?@example@\.com#i', $ig->regex);
    }

    public function test_add_rejects_pattern_with_all_delimiters(): void
    {
        $this->expectException(InvalidSettingException::class);
        $this->svc->addLinktitlesIgnore('@#~%', ignore_type::global);
    }

    public function test_add_rejects_invalid_regex(): void
    {
        $this->expectException(InvalidSettingException::class);
        $this->svc->addLinktitlesIgnore('unclosed[', ignore_type::global);
    }

    public function test_add_rejects_empty_pattern(): void
    {
        $this->expectException(InvalidSettingException::class);
        $this->svc->addLinktitlesIgnore('  ', ignore_type::global);
    }

    public function test_add_network_scoped(): void
    {
        $net = $this->svc->createNetwork('N');
        $ig = $this->svc->addLinktitlesIgnore('x', ignore_type::network, $net);
        $this->assertSame($net, $ig->network);
        $this->assertNull($ig->bot);
    }

    public function test_add_network_scoped_requires_network(): void
    {
        $this->expectException(NotFoundException::class);
        $this->svc->addLinktitlesIgnore('x', ignore_type::network, null);
    }

    public function test_add_network_scoped_unknown_network(): void
    {
        $net = $this->svc->createNetwork('N');
        $this->em->detach($net);
        $this->expectException(NotFoundException::class);
        $this->svc->addLinktitlesIgnore('x', ignore_type::network, $net);
    }

    public function test_add_bot_scoped(): void
    {
        $net = $this->svc->createNetwork('N');
        $bot = $this->svc->createBot($net, 'b1');
        $ig = $this->svc->addLinktitlesIgnore('x', ignore_type::bot, null, $bot);
        $this->assertSame($bot, $ig->bot);
        $this->assertNull($ig->network);
    }

    public function test_add_bot_scoped_requires_bot(): void
    {
        $this->expectException(NotFoundException::class);
        $this->svc->addLinktitlesIgnore('x', ignore_type::bot, null, null);
    }

    public function test_add_global_rejects_scope_targets(): void
    {
        $net = $this->svc->createNetwork('N');
        $this->expectException(InvalidSettingException::class);
        $this->svc->addLinktitlesIgnore('x', ignore_type::global, $net);
    }

    public function test_add_channel_rejected(): void
    {
        $this->expectException(InvalidSettingException::class);
        $this->svc->addLinktitlesIgnore('x', ignore_type::channel);
    }

    public function test_url_list_get_delete_roundtrip(): void
    {
        $ig = $this->svc->addLinktitlesIgnore('x', ignore_type::global);
        $this->assertSame([$ig], $this->svc->listLinktitlesIgnores());
        $this->assertSame($ig, $this->svc->getLinktitlesIgnore($ig->id));

        $id = $ig->id;
        $this->notifier->reset();
        $this->svc->deleteLinktitlesIgnore($ig);

        $this->assertNull($this->svc->getLinktitlesIgnore($id));
        $this->assertSame([], $this->svc->listLinktitlesIgnores());
        $this->assertCount(1, $this->notifier->changes);
        $this->assertSame('delete', $this->notifier->changes[0]->action);
        $this->assertSame($id, $this->notifier->changes[0]->id);
    }

    public function test_hostignore_add_list_delete(): void
    {
        $net = $this->svc->createNetwork('N');
        $this->notifier->reset();
        $hi = $this->svc->addLinktitlesHostignore('*!*@*.bad', ignore_type::network, $net);
        $this->assertSame('*!*@*.bad', $hi->hostmask);
        $this->assertSame($net, $hi->network);
        $this->assertSame([$hi], $this->svc->listLinktitlesHostignores());
        $this->assertSame('linktitles_hostignore', $this->notifier->changes[0]->entityType);
        $this->assertSame('create', $this->notifier->changes[0]->action);

        $id = $hi->id;
        $this->notifier->reset();
        $this->svc->deleteLinktitlesHostignore($hi);

        $this->assertNull($this->svc->getLinktitlesHostignore($id));
        $this->assertSame([], $this->svc->listLinktitlesHostignores());
        $this->assertSame('delete', $this->notifier->changes[0]->action);
    }

    public function test_hostignore_rejects_empty(): void
    {
        $this->expectException(InvalidSettingException::class);
        $this->svc->addLinktitlesHostignore('  ', ignore_type::global);
    }

    public function test_hostignore_channel_rejected(): void
    {
        $this->expectException(InvalidSettingException::class);
        $this->svc->addLinktitlesHostignore('*!*@*', ignore_type::channel);
    }
}
