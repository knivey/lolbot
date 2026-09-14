<?php
namespace Tests\Config;

use lolbot\config\ConfigService;
use lolbot\entities\Network;

require_once __DIR__ . '/../../vendor/autoload.php';

class NetworkSelectServerTest extends ConfigTestCase
{
    public function test_index_wraps_after_out_of_band_shrink(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $svc->addServer($net, 's1.example.net', 6667, false, true, null);
        $svc->addServer($net, 's2.example.net', 6667, false, true, null);
        $keep = $svc->addServer($net, 's3.example.net', 6667, false, true, null);

        // Warm the collection and advance the round-robin index.
        $net->getServers()->toArray();
        $net->selectServer();
        $net->selectServer();

        // Out-of-band: shrink to a single server (another process), then
        // refresh like BotManager does before selecting.
        $this->em->getConnection()->executeStatement(
            'DELETE FROM Servers WHERE id != ?',
            [$keep->id]
        );
        $this->em->refresh($net);

        // The stale index points past the shrunk collection; selection must
        // wrap instead of returning null.
        $selected = $net->selectServer();
        $this->assertNotNull($selected);
        $this->assertSame('s3.example.net', $selected->address);
    }
}
