<?php
namespace Tests\Irc;

use Irc\Client;
use PHPUnit\Framework\TestCase;
use Monolog\Logger;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../library/Irc/Consts.php';

class ClientServerTest extends TestCase
{
    private function makeClient(): Client
    {
        return new Client('testbot', 'irc.old.example', new Logger('test'), '6667', '0', false);
    }

    public function test_set_server_updates_properties(): void
    {
        $c = $this->makeClient();
        $c->setServer('irc.new.example', '7000', true, 'secret', false, '1.2.3.4');

        $r = new \ReflectionProperty(Client::class, 'server');
        $this->assertSame('irc.new.example', $r->getValue($c));

        $port = new \ReflectionProperty(Client::class, 'port');
        $this->assertSame('7000', $port->getValue($c));

        $ssl = new \ReflectionProperty(Client::class, 'ssl');
        $this->assertTrue($ssl->getValue($c));
    }

    public function test_reconnect_without_socket_is_safe_noop(): void
    {
        $c = $this->makeClient();
        $c->reconnect(); // No connection ever established; must not throw.
        $this->assertFalse($c->isConnected);
    }
}
