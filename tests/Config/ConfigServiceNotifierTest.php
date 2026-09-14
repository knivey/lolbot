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

    public function test_delete_network_notifies_with_bot_ids_data_bag(): void
    {
        $notifier = new CapturingNotifier();
        $svc = new ConfigService($this->em, $notifier);
        $net = $svc->createNetwork('N');
        $b1 = $svc->createBot($net, 'b1');
        $b2 = $svc->createBot($net, 'b2');
        $notifier->reset();

        $svc->deleteNetwork($net);

        $this->assertCount(1, $notifier->changes);
        $this->assertSame('network', $notifier->changes[0]->entityType);
        $this->assertSame('delete', $notifier->changes[0]->action);
        // Bot rows vanish via ON DELETE CASCADE before the push arrives, so
        // the ids must travel in the data bag for the bot to drop its clients.
        $this->assertSame(['botIds' => [$b1->id, $b2->id]], $notifier->changes[0]->data);
    }

    public function test_delete_server_notifies_with_network_id_data_bag(): void
    {
        $notifier = new CapturingNotifier();
        $svc = new ConfigService($this->em, $notifier);
        $net = $svc->createNetwork('N');
        $srv = $svc->addServer($net, 'irc.example.net');
        $notifier->reset();

        $id = $srv->id;
        $svc->deleteServer($srv);

        $this->assertCount(1, $notifier->changes);
        $this->assertSame('server', $notifier->changes[0]->entityType);
        $this->assertSame('delete', $notifier->changes[0]->action);
        $this->assertSame($id, $notifier->changes[0]->id);
        // The row is gone by push time, so the network id must travel in the
        // data bag for the bot to route the jump.
        $this->assertSame(['networkId' => $net->id], $notifier->changes[0]->data);
    }
}
