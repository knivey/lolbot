<?php
namespace Tests\Config;

use lolbot\config\ConfigService;

require_once __DIR__ . '/../../vendor/autoload.php';

class ConfigServiceUpdateTest extends ConfigTestCase
{
    public function test_update_persists_change(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('Old');
        $net->name = 'New';
        $svc->update($net, 'network');
        $this->em->clear();
        $found = $svc->getNetwork($net->id);
        $this->assertNotNull($found);
        $this->assertSame('New', $found->name);
    }
}
