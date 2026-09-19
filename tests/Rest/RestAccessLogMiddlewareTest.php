<?php

namespace Tests\Rest;

use Amp\Http\Server\ClientException;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use League\Uri\Http as Uri;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

require_once __DIR__ . '/../../vendor/autoload.php';

class RestAccessLogMiddlewareTest extends TestCase
{
    /** @var list<array{0: string, 1: string}> */
    private array $logs = [];

    private function makeMiddleware(): \library\RestAccessLogMiddleware
    {
        $this->logs = [];
        $logger = $this->createStub(\Psr\Log\LoggerInterface::class);
        $logger->method('log')->willReturnCallback(function (string $level, string $message): void {
            $this->logs[] = [$level, $message];
        });
        return new \library\RestAccessLogMiddleware($logger);
    }

    private function makeRequest(): Request
    {
        $client = $this->createStub(\Amp\Http\Server\Driver\Client::class);
        $client->method('getRemoteAddress')->willReturn(new \Amp\Socket\InternetAddress('127.0.0.1', 53214));
        return new Request($client, 'POST', Uri::new('/aidesc?x=1'));
    }

    public function test_logs_request_line_at_info(): void
    {
        $handler = $this->createStub(RequestHandler::class);
        $handler->method('handleRequest')->willReturn(new Response(200));
        $response = $this->makeMiddleware()->handleRequest($this->makeRequest(), $handler);

        $this->assertSame(200, $response->getStatus());
        $this->assertCount(1, $this->logs);
        [$level, $message] = $this->logs[0];
        $this->assertSame(LogLevel::INFO, $level);
        $this->assertMatchesRegularExpression(
            '#^"POST /aidesc\?x=1" 200 (?:\d+(?:\.\d+)?s|\d+(?:\.\d+)?ms|\d+µs) from 127\.0\.0\.1:53214$#',
            $message,
        );
    }

    public function test_5xx_logs_at_warning(): void
    {
        $handler = $this->createStub(RequestHandler::class);
        $handler->method('handleRequest')->willReturn(new Response(500));
        $this->makeMiddleware()->handleRequest($this->makeRequest(), $handler);

        $this->assertCount(1, $this->logs);
        $this->assertSame(LogLevel::WARNING, $this->logs[0][0]);
    }

    public function test_client_exception_is_logged_and_rethrown(): void
    {
        $handler = $this->createStub(RequestHandler::class);
        $handler->method('handleRequest')->willThrowException(
            new ClientException($this->createStub(\Amp\Http\Server\Driver\Client::class), 'peer vanished')
        );

        try {
            $this->makeMiddleware()->handleRequest($this->makeRequest(), $handler);
            $this->fail('ClientException should have propagated');
        } catch (ClientException) {
        }

        $this->assertCount(1, $this->logs);
        $this->assertSame(LogLevel::WARNING, $this->logs[0][0]);
        $this->assertStringContainsString('"POST /aidesc?x=1"', $this->logs[0][1]);
        $this->assertStringContainsString('peer vanished', $this->logs[0][1]);
    }

    public function test_key_header_value_never_logged(): void
    {
        $handler = $this->createStub(RequestHandler::class);
        $handler->method('handleRequest')->willReturn(new Response(403));
        $request = $this->makeRequest();
        $request->setHeader('key', 's3cr3tkey');

        $this->makeMiddleware()->handleRequest($request, $handler);

        $this->assertCount(1, $this->logs);
        $this->assertStringNotContainsString('s3cr3tkey', $this->logs[0][1]);
    }

    public function test_throwing_logger_does_not_break_the_response(): void
    {
        $logger = $this->createStub(\Psr\Log\LoggerInterface::class);
        $logger->method('log')->willThrowException(new \RuntimeException('disk full'));
        $middleware = new \library\RestAccessLogMiddleware($logger);

        $handler = $this->createStub(RequestHandler::class);
        $handler->method('handleRequest')->willReturn(new Response(200));

        $response = $middleware->handleRequest($this->makeRequest(), $handler);

        $this->assertSame(200, $response->getStatus());
    }
}
