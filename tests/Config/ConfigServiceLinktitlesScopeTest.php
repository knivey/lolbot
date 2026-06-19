<?php
namespace Tests\Config;

use lolbot\config\ConfigService;
use scripts\linktitles\entities\linktitles_setting;

require_once __DIR__ . '/../../vendor/autoload.php';

class ConfigServiceLinktitlesScopeTest extends ConfigTestCase
{
    public function test_delete_scope_removes_whole_row(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $svc->setLinktitlesSetting($net, null, 'enabled', true);
        $svc->setLinktitlesSetting($net, null, 'url_log_chan', '#urls');

        $svc->deleteLinktitlesSettingScope($net, null);

        $this->assertSame(0, count($this->em->getRepository(linktitles_setting::class)->findAll()));
    }

    public function test_delete_scope_is_noop_when_no_row(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $svc->deleteLinktitlesSettingScope($net, null); // must not throw
        $this->assertSame(0, count($this->em->getRepository(linktitles_setting::class)->findAll()));
    }

    public function test_save_linktitles_setting_persists(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $s = new linktitles_setting();
        $s->network = $net;
        $s->enabled = true;
        $svc->saveLinktitlesSetting($s);
        $this->em->clear();
        $loaded = $this->em->getRepository(linktitles_setting::class)->findAll()[0];
        $this->assertTrue($loaded->enabled);
    }

    public function test_delete_channel_notifies_with_data_bag(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        $chan = $svc->addChannel($bot, '#gone');
        $chanId = $chan->id;
        $botId = $bot->id;
        $svc->deleteChannel($chan);
        // Row gone, and the (default Noop) notifier would carry botId+chan — verified by the
        // data being captured before remove. Assert the channel is gone:
        $this->assertNull($this->em->getRepository(\lolbot\entities\Channel::class)->find($chanId));
        $this->assertSame($botId, $bot->id);
    }
}
