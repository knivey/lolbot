<?php
namespace Tests\Config;

use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\Request as HttpRequest;
use Amp\Http\Client\Response;
use lolbot\config\ConfigChange;
use lolbot\config\HttpPushChangeNotifier;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

class HttpPushChangeNotifierTest extends TestCase
{
    public function test_notify_posts_config_change_to_apply_endpoint_with_core_key(): void
    {
        /** @var HttpRequest|null $captured */
        $captured = null;
        $http = $this->createMock(DelegateHttpClient::class);
        $http->expects($this->once())
            ->method('request')
            ->willReturnCallback(function (HttpRequest $r) use (&$captured): Response {
                $captured = $r;
                return new Response('1.1', 200, 'OK', [], '', $r);
            });

        $notifier = new HttpPushChangeNotifier($http, 'http://127.0.0.1:1339', 'sekret');
        $notifier->notify(new ConfigChange('channel', 42, 'create'));

        $this->assertNotNull($captured);
        $this->assertSame('http://127.0.0.1:1339/_control/apply', (string) $captured->getUri());
        $this->assertSame('POST', $captured->getMethod());
        $this->assertSame('sekret', $captured->getHeader('key'));
        $payload = $this->decodeBody($captured);
        $this->assertSame('channel', $payload['entityType']);
        $this->assertSame(42, $payload['id']);
        $this->assertSame('create', $payload['action']);
    }

    public function test_notify_includes_data_bag_when_present(): void
    {
        /** @var HttpRequest|null $captured */
        $captured = null;
        $http = $this->createMock(DelegateHttpClient::class);
        $http->expects($this->once())
            ->method('request')
            ->willReturnCallback(function (HttpRequest $r) use (&$captured): Response {
                $captured = $r;
                return new Response('1.1', 200, 'OK', [], '', $r);
            });
        $notifier = new HttpPushChangeNotifier($http, 'http://127.0.0.1:1339', 'sekret');
        $notifier->notify(new ConfigChange('channel', 7, 'delete', ['botId' => 3, 'chan' => '#gone']));

        $this->assertNotNull($captured);
        $payload = $this->decodeBody($captured);
        $this->assertSame(['botId' => 3, 'chan' => '#gone'], $payload['data'] ?? null);
    }

    public function test_notify_tolerates_unreachable_bot(): void
    {
        $http = $this->createMock(DelegateHttpClient::class);
        $http->expects($this->once())
            ->method('request')
            ->willThrowException(new \Exception('connection refused'));
        $notifier = new HttpPushChangeNotifier($http, 'http://127.0.0.1:1339', 'sekret');
        $this->expectOutputRegex('/live-sync push skipped: connection refused/');
        $notifier->notify(new ConfigChange('bot', 1, 'delete')); // must not throw
    }

    /**
     * @return array<mixed, mixed>
     */
    private function decodeBody(HttpRequest $request): array
    {
        $decoded = json_decode($request->getBody()->getContent()->read() ?? '', true);
        $this->assertIsArray($decoded, 'expected a JSON object body');
        return $decoded;
    }
}
