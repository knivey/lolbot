<?php
namespace Tests\Config;

use lolbot\config\ConfigService;
use lolbot\config\NotFoundException;

require_once __DIR__ . '/../../vendor/autoload.php';

class ConfigServiceIgnoreTest extends ConfigTestCase
{
    private ConfigService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ConfigService($this->em);
    }

    public function test_add_ignore_with_networks(): void
    {
        $net = $this->svc->createNetwork('N');
        $ignore = $this->svc->addIgnore('*@*.bad', 'spam', [$net]);
        $this->assertSame('*@*.bad', $ignore->hostmask);
        $this->assertSame('spam', $ignore->reason);
        $this->assertTrue($ignore->assignedToNetwork($net));
    }

    public function test_add_ignore_unknown_network_throws(): void
    {
        $net = $this->svc->createNetwork('N');
        $this->em->detach($net);
        $this->expectException(NotFoundException::class);
        $this->svc->addIgnore('*@*.bad', null, [$net]);
    }

    public function test_add_ignore_networks_to_existing(): void
    {
        $n1 = $this->svc->createNetwork('A');
        $n2 = $this->svc->createNetwork('B');
        $ignore = $this->svc->addIgnore('*@*.bad', null, [$n1]);
        $this->svc->addIgnoreNetworks($ignore, [$n2]);
        $this->assertTrue($ignore->assignedToNetwork($n1));
        $this->assertTrue($ignore->assignedToNetwork($n2));
    }

    public function test_delete_ignore(): void
    {
        $net = $this->svc->createNetwork('N');
        $ignore = $this->svc->addIgnore('*@*.bad', null, [$net]);
        $id = $ignore->id;
        $this->svc->deleteIgnore($ignore);
        $loaded = $this->em->getRepository(\lolbot\entities\Ignore::class)->find($id);
        $this->assertNull($loaded);
    }
}
