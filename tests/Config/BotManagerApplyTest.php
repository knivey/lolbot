<?php
namespace Tests\Config;

use lolbot\config\ConfigChange;
use lolbot\config\ConfigService;
use lolbot\entities\Bot;
use lolbot\entities\Network;
use library\BotManager;

require_once __DIR__ . '/../../vendor/autoload.php';

class BotManagerApplyTest extends ConfigTestCase
{
    /**
     * @return array{0: BotManager, 1: \PHPUnit\Framework\MockObject\MockObject&\Irc\Client}
     */
    private function mgrWithBot(Network $net, Bot $bot): array
    {
        $mgr = new BotManager($this->em);
        $client = $this->createMock(\Irc\Client::class);
        $mgr->clients[$bot->id] = $client;
        $mgr->bots[$bot->id] = $bot;
        $mgr->networks[$bot->id] = $net;
        $mgr->state[$bot->id] = new \stdClass();
        return [$mgr, $client];
    }

    public function test_channel_create_joins(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        $chan = $svc->addChannel($bot, '#test');
        [$mgr, $client] = $this->mgrWithBot($net, $bot);
        $client->expects($this->once())->method('join')->with('#test');
        $mgr->apply(new ConfigChange('channel', $chan->id, 'create'));
    }

    public function test_channel_delete_parts(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        [$mgr, $client] = $this->mgrWithBot($net, $bot);
        $client->expects($this->once())->method('part')->with('#gone');
        $mgr->apply(new ConfigChange('channel', 0, 'delete', ['botId' => $bot->id, 'chan' => '#gone']));
    }

    public function test_bot_update_reloads_nick_when_changed(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'oldname');
        [$mgr, $client] = $this->mgrWithBot($net, $bot);
        $bot->name = 'newname';
        $this->em->flush();
        $client->expects($this->once())->method('setNick')->with('newname');
        $mgr->apply(new ConfigChange('bot', $bot->id, 'update'));
    }

    public function test_server_update_triggers_jump_for_network_bots(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        $srv = $svc->addServer($net, 'irc.example.net', 6667, false, true, null);
        [$mgr, $client] = $this->mgrWithBot($net, $bot);
        $client->expects($this->once())->method('reconnect');
        $mgr->apply(new ConfigChange('server', $srv->id, 'update'));
    }

    public function test_linktitles_setting_update_refreshes_enabled_holder(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        [$mgr, $client] = $this->mgrWithBot($net, $bot);
        $mgr->state[$bot->id]->linktitlesEnabled = false;
        $svc->setLinktitlesSetting($net, null, 'enabled', true);
        // Find the linktitles_setting id so apply can resolve the network.
        $setting = $this->em->getRepository(\scripts\linktitles\entities\linktitles_setting::class)->findOneBy(['network' => $net]);
        $this->assertNotNull($setting);
        $mgr->apply(new ConfigChange('linktitles_setting', $setting->id, 'update'));
        $this->assertTrue($mgr->state[$bot->id]->linktitlesEnabled);
    }
}
