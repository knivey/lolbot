<?php
namespace Tests\Config;

use lolbot\config\ConfigChange;
use lolbot\config\ConfigService;
use lolbot\config\ChangeNotifier;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Captures ConfigChange notifications so tests can assert the seam fires.
 */
class CapturingNotifier implements ChangeNotifier
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

class ConfigServiceNotifierTest extends ConfigTestCase
{
    public function test_create_network_notifies_with_create_action(): void
    {
        $notifier = new CapturingNotifier();
        $svc = new ConfigService($this->em, $notifier);

        $net = $svc->createNetwork('N');

        $this->assertCount(1, $notifier->changes);
        $this->assertSame('network', $notifier->changes[0]->entityType);
        $this->assertSame($net->id, $notifier->changes[0]->id);
        $this->assertSame('create', $notifier->changes[0]->action);
    }

    public function test_delete_network_notifies_with_delete_action(): void
    {
        $notifier = new CapturingNotifier();
        $svc = new ConfigService($this->em, $notifier);
        $net = $svc->createNetwork('N');
        $notifier->reset();

        $id = $net->id;
        $svc->deleteNetwork($net);

        $this->assertCount(1, $notifier->changes);
        $this->assertSame('delete', $notifier->changes[0]->action);
        $this->assertSame($id, $notifier->changes[0]->id);
    }
}
