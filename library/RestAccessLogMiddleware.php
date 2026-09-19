<?php

namespace library;

use Amp\Http\Server\ClientException;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Access log for the channel bot's REST server: one line per request
 * (method, target, status, handler duration, remote address) once the inner
 * handler returns. Wraps the whole router rather than being registered
 * per-route, so unmatched paths (404s) are logged too. The key header is
 * deliberately never logged.
 */
final class RestAccessLogMiddleware implements Middleware
{
    public function __construct(private LoggerInterface $logger) {}

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $start = hrtime(true);
        $method = $request->getMethod();
        $uri = (string)$request->getUri();
        $remote = $request->getClient()->getRemoteAddress()->toString();

        try {
            $response = $requestHandler->handleRequest($request);
        } catch (ClientException $e) {
            // Client vanished mid-request; mirrors Amp's AccessLoggerMiddleware.
            try {
                $this->logger->log(LogLevel::WARNING, \sprintf(
                    'Client exception for "%s %s" from %s: %s',
                    $method,
                    $uri,
                    $remote,
                    $e->getMessage(),
                ));
            } catch (\Throwable) {
                // A failing log sink must never break the request; stdout still logs.
            }
            throw $e;
        }

        $ms = (hrtime(true) - $start) / 1e6;
        $status = $response->getStatus();
        $level = $status >= 500 ? LogLevel::WARNING : LogLevel::INFO;
        try {
            $this->logger->log($level, \sprintf(
                '"%s %s" %d %s from %s',
                $method,
                $uri,
                $status,
                self::formatDuration($ms),
                $remote,
            ));
        } catch (\Throwable) {
            // A failing log sink must never break the request; stdout still logs.
        }
        return $response;
    }

    private static function formatDuration(float $ms): string
    {
        if ($ms < 1) {
            return round($ms * 1000) . 'µs';
        }
        if ($ms < 1000) {
            return round($ms, 1) . 'ms';
        }
        return round($ms / 1000, 2) . 's';
    }
}
