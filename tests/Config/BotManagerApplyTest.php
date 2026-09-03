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

    public function test_bot_update_disabled_drops_client(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        [$mgr, $client] = $this->mgrWithBot($net, $bot);
        $client->expects($this->once())->method('exit');
        $bot->disabled = true;
        $this->em->flush();
        $mgr->apply(new ConfigChange('bot', $bot->id, 'update'));
        $this->assertArrayNotHasKey($bot->id, $mgr->clients);
    }

    public function test_bot_update_network_disabled_drops_client(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        [$mgr, $client] = $this->mgrWithBot($net, $bot);
        $client->expects($this->once())->method('exit');
        $net->disabled = true;
        $this->em->flush();
        $mgr->apply(new ConfigChange('bot', $bot->id, 'update'));
        $this->assertArrayNotHasKey($bot->id, $mgr->clients);
    }

    public function test_bot_update_reenable_spawns(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        $bot->disabled = true;
        $this->em->flush();
        $mgr = new RecordingBotManager($this->em, $this);

        $mgr->apply(new ConfigChange('bot', $bot->id, 'update'));
        $this->assertSame([], $mgr->spawned);

        $bot->disabled = false;
        $this->em->flush();
        $mgr->apply(new ConfigChange('bot', $bot->id, 'update'));
        $this->assertSame([$bot->id], $mgr->spawned);
        $this->assertArrayHasKey($bot->id, $mgr->clients);
    }

    public function test_bot_create_disabled_does_not_spawn(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        $bot->disabled = true;
        $this->em->flush();
        $mgr = new RecordingBotManager($this->em, $this);
        $mgr->apply(new ConfigChange('bot', $bot->id, 'create'));
        $this->assertSame([], $mgr->spawned);
    }

    public function test_network_update_disabled_drops_all_bots(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot1 = $svc->createBot($net, 'b1');
        $bot2 = $svc->createBot($net, 'b2');
        $mgr = new BotManager($this->em);
        foreach ([$bot1, $bot2] as $b) {
            $mgr->clients[$b->id] = $this->createMock(\Irc\Client::class);
            $mgr->bots[$b->id] = $b;
            $mgr->networks[$b->id] = $net;
            $mgr->state[$b->id] = new \stdClass();
        }
        $net->disabled = true;
        $this->em->flush();
        $mgr->apply(new ConfigChange('network', $net->id, 'update'));
        $this->assertArrayNotHasKey($bot1->id, $mgr->clients);
        $this->assertArrayNotHasKey($bot2->id, $mgr->clients);
    }

    public function test_network_update_reenable_spawns_only_enabled_bots(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $b1 = $svc->createBot($net, 'b1');
        $b2 = $svc->createBot($net, 'b2');
        $b2->disabled = true;
        $net->disabled = true;
        $this->em->flush();

        $mgr = new RecordingBotManager($this->em, $this);
        $mgr->apply(new ConfigChange('network', $net->id, 'update'));
        $this->assertSame([], $mgr->spawned);

        $net->disabled = false;
        $this->em->flush();
        $mgr->apply(new ConfigChange('network', $net->id, 'update'));
        $this->assertSame([$b1->id], $mgr->spawned);
    }
}

/**
 * BotManager whose spawn() is stubbed: records bot ids and registers mock
 * clients instead of constructing real IRC connections.
 */
class RecordingBotManager extends BotManager
{
    /** @var list<int> */
    public array $spawned = [];
    private \PHPUnit\Framework\TestCase $tc;

    public function __construct(\Doctrine\ORM\EntityManager $em, \PHPUnit\Framework\TestCase $tc)
    {
        parent::__construct($em);
        $this->tc = $tc;
    }

    public function spawn(\lolbot\entities\Network $network, \lolbot\entities\Bot $dbBot): \Irc\Client
    {
        $this->spawned[] = $dbBot->id;
        $client = (new \PHPUnit\Framework\MockObject\MockBuilder($this->tc, \Irc\Client::class))
            ->disableOriginalConstructor()
            ->getMock();
        $this->clients[$dbBot->id] = $client;
        $this->bots[$dbBot->id] = $dbBot;
        $this->networks[$dbBot->id] = $network;
        $this->state[$dbBot->id] = new \stdClass();
        return $client;
    }
}
