<?php
namespace Tests\Config;

use lolbot\entities\Network;

require_once __DIR__ . '/../../vendor/autoload.php';

class ConfigTestCaseTest extends ConfigTestCase
{
    public function test_can_persist_and_reload_an_entity(): void
    {
        $net = new Network();
        $net->name = 'TestNet';
        $this->em->persist($net);
        $this->em->flush();
        $this->em->clear();

        $repo = $this->em->getRepository(Network::class);
        $found = $repo->findOneBy(['name' => 'TestNet']);
        $this->assertNotNull($found);
        $this->assertSame('TestNet', $found->name);
    }
}
