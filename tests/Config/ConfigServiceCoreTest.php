<?php
namespace Tests\Config;

use lolbot\config\ConfigService;
use lolbot\config\DuplicateNameException;
use lolbot\config\NotFoundException;
use lolbot\entities\Channel;
use lolbot\entities\Network;

require_once __DIR__ . '/../../vendor/autoload.php';

class ConfigServiceCoreTest extends ConfigTestCase
{
    private ConfigService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ConfigService($this->em);
    }

    public function test_create_network_and_duplicate(): void
    {
        $n = $this->svc->createNetwork('Libera');
        $this->assertSame('Libera', $n->name);
        $this->assertNotNull($n->id);

        $this->expectException(DuplicateNameException::class);
        $this->svc->createNetwork('Libera');
    }

    public function test_get_and_delete_network(): void
    {
        $n = $this->svc->createNetwork('EFnet');
        $id = $n->id;
        $this->assertSame('EFnet', $this->svc->getNetwork($id)->name);

        $this->svc->deleteNetwork($n);
        $this->assertNull($this->svc->getNetwork($id));
    }

    public function test_create_bot_and_add_channel(): void
    {
        $net = $this->svc->createNetwork('N');
        $bot = $this->svc->createBot($net, 'lolbot');
        $this->assertSame('N', $bot->network->name);

        $chan = $this->svc->addChannel($bot, '#test');
        $this->assertSame('#test', $chan->name);
        $this->assertSame($bot->id, $chan->bot->id);

        $chanId = $chan->id;
        $this->svc->deleteChannel($chan);
        $this->assertNull($this->em->getRepository(\lolbot\entities\Channel::class)->find($chanId));
    }

    public function test_create_bot_unknown_network_throws(): void
    {
        $net = new Network(); // detached, no id
        $net->name = 'ghost';
        $this->expectException(NotFoundException::class);
        $this->svc->createBot($net, 'x');
    }

    public function test_add_server(): void
    {
        $net = $this->svc->createNetwork('N');
        $srv = $this->svc->addServer($net, 'irc.example.net', 7000, true, false, 'pass');
        $this->assertSame('irc.example.net', $srv->address);
        $this->assertSame(7000, $srv->port);
        $this->assertTrue($srv->ssl);
        $this->assertFalse($srv->throttle);
        $this->assertSame('pass', $srv->password);

        $srvId = $srv->id;
        $this->svc->deleteServer($srv);
        $this->assertNull($this->svc->getServer($srvId));
    }
}
