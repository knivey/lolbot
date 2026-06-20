<?php
namespace Tests\Config;

use lolbot\config\ConfigChange;
use lolbot\config\HttpPushChangeNotifier;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

class HttpPushChangeNotifierTest extends TestCase
{
    public function test_build_request_url_method_headers_body(): void
    {
        $n = new HttpPushChangeNotifier('http://127.0.0.1:1339', 'sekret');
        $req = $n->buildRequest(new ConfigChange('channel', 42, 'create'));

        $this->assertSame('http://127.0.0.1:1339/_control/apply', $req['url']);
        $this->assertContains('key: sekret', $req['headers']);
        $this->assertContains('content-type: application/json', $req['headers']);
        $payload = json_decode($req['body'], true);
        $this->assertIsArray($payload);
        $this->assertSame('channel', $payload['entityType']);
        $this->assertSame(42, $payload['id']);
        $this->assertSame('create', $payload['action']);
        $this->assertArrayNotHasKey('data', $payload);
    }

    public function test_build_request_includes_data_bag_when_present(): void
    {
        $n = new HttpPushChangeNotifier('http://127.0.0.1:1339', 'sekret');
        $req = $n->buildRequest(new ConfigChange('channel', 7, 'delete', ['botId' => 3, 'chan' => '#gone']));
        $payload = json_decode($req['body'], true);
        $this->assertIsArray($payload);
        $this->assertSame(['botId' => 3, 'chan' => '#gone'], $payload['data'] ?? null);
    }

    public function test_build_request_trims_trailing_slash_on_apply_url(): void
    {
        $n = new HttpPushChangeNotifier('http://127.0.0.1:1339/', 'k');
        $req = $n->buildRequest(new ConfigChange('bot', 1, 'update'));
        $this->assertSame('http://127.0.0.1:1339/_control/apply', $req['url']);
    }

    public function test_notify_tolerates_unreachable_bot(): void
    {
        $n = new HttpPushChangeNotifier('http://127.0.0.1:1', 'sekret'); // nothing listening
        $n->notify(new ConfigChange('bot', 1, 'delete')); // must not throw / hang
        $this->expectNotToPerformAssertions();
    }
}
