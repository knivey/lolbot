<?php
namespace Tests\Config;

use lolbot\config\ChangeNotifier;
use lolbot\config\ConfigChange;
use lolbot\config\NoopChangeNotifier;

require_once __DIR__ . '/../../vendor/autoload.php';

class ChangeNotifierTest extends \PHPUnit\Framework\TestCase
{
    public function test_config_change_holds_fields(): void
    {
        $c = new ConfigChange('network', 7, 'create');
        $this->assertSame('network', $c->entityType);
        $this->assertSame(7, $c->id);
        $this->assertSame('create', $c->action);
    }

    public function test_noop_notifier_implements_interface_and_does_not_throw(): void
    {
        $n = new NoopChangeNotifier();
        $this->assertInstanceOf(ChangeNotifier::class, $n);
        $n->notify(new ConfigChange('bot', 1, 'update')); // must not throw
    }
}
