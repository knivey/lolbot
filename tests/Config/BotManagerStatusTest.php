<?php
namespace Tests\Config;

use lolbot\config\ConfigService;
use lolbot\entities\Bot;
use lolbot\entities\Network;
use library\BotManager;

require_once __DIR__ . '/../../vendor/autoload.php';

class BotManagerStatusTest extends ConfigTestCase
{
    public function test_botStatus_reports_connected_nick_channels_server(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');

        $mgr = new BotManager($this->em);
        $client = $this->createMock(\Irc\Client::class);
        $client->method('isEstablished')->willReturn(true);
        $client->method('getNick')->willReturn('b');
        $client->method('getJoinedChannels')->willReturn(['#dev', '#bots']);
        $client->method('getServerDesc')->willReturn('irc.example.net:6697 ssl');
        $mgr->clients[$bot->id] = $client;
        $mgr->bots[$bot->id] = $bot;
        $mgr->networks[$bot->id] = $net;

        $status = $mgr->botStatus($bot->id);
        $this->assertNotNull($status);
        $this->assertSame($bot->id, $status['id']);
        $this->assertSame('b', $status['name']);
        $this->assertSame('N', $status['network']);
        $this->assertTrue($status['connected']);
        $this->assertSame('b', $status['nick']);
        $this->assertSame(['#dev', '#bots'], $status['channels']);
        $this->assertSame('irc.example.net:6697 ssl', $status['server']);
    }

    public function test_allBotStatuses_returns_one_entry_per_live_bot(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');

        $mgr = new BotManager($this->em);
        $client = $this->createMock(\Irc\Client::class);
        $client->method('isEstablished')->willReturn(false);
        $client->method('getNick')->willReturn('b');
        $client->method('getJoinedChannels')->willReturn([]);
        $client->method('getServerDesc')->willReturn('irc.example.net:6697 ssl');
        $mgr->clients[$bot->id] = $client;
        $mgr->bots[$bot->id] = $bot;
        $mgr->networks[$bot->id] = $net;

        $all = $mgr->allBotStatuses();
        $this->assertCount(1, $all);
        $this->assertFalse($all[0]['connected']);
    }

    public function test_botStatus_returns_null_for_unknown_bot(): void
    {
        $mgr = new BotManager($this->em);
        $this->assertNull($mgr->botStatus(999));
    }
}
