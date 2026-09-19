<?php
namespace scripts\aidesc;

use Amp\ByteStream\BufferException;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\HttpStatus;
use lolbot\config\ServiceLocator;
use lolbot\config\SettingsResolver;
use lolbot\entities\ApiKey;
use lolbot\entities\AiServiceConfig;
use scripts\linktitles\DescribeResult;
use scripts\linktitles\ImageDescriber;

// Raw-image-body limit for submitted image data; mirrors the 16MB body limit
// linktitles uses when fetching images.
const maxBodyBytes = 1024 * 1024 * 16;

/**
 * Register POST /aidesc on the shared global REST server: callers submit raw
 * image bytes as the request body and receive a plain-text AI description.
 * Auth via api_keys table (key header) with the 'aidesc' scope; settings are
 * the global-scope linktitles AI config + ai_service_config.
 */
function aidesc_register(Router $router, \Psr\Log\LoggerInterface $logger): void {
    $handler = new ClosureRequestHandler(function (Request $request) use ($logger) {
        global $entityManager;

        // Key auth: per-request HINT_REFRESH read so adds/deletes apply live.
        $key = (string)$request->getHeader('key');
        $apiKey = $entityManager->getRepository(ApiKey::class)->findByKey($key);
        if ($key === '' || $apiKey === null || !$apiKey->hasScope('aidesc')) {
            return new Response(HttpStatus::FORBIDDEN, ['content-type' => 'text/plain'], "Invalid key");
        }

        // Amp's HTTP driver caps request bodies at 128KB by default
        // (HttpDriver::DEFAULT_BODY_SIZE_LIMIT); raise it for this endpoint
        // before buffering or larger uploads stall the handler forever.
        $request->getBody()->increaseSizeLimit(maxBodyBytes + 1);
        try {
            $body = $request->getBody()->buffer(limit: maxBodyBytes + 1);
        } catch (BufferException|ClientException $e) {
            return new Response(HttpStatus::PAYLOAD_TOO_LARGE, ['content-type' => 'text/plain'], "Image too large (max 16MB)");
        }
        if (strlen($body) > maxBodyBytes) {
            return new Response(HttpStatus::PAYLOAD_TOO_LARGE, ['content-type' => 'text/plain'], "Image too large (max 16MB)");
        }
        if ($body === '') {
            return new Response(HttpStatus::BAD_REQUEST, ['content-type' => 'text/plain'], "Empty body");
        }

        // Identical resubmits hit the shared in-process cache, keyed by content.
        $cacheKey = 'sha256:' . hash('sha256', $body);
        if (isset(ImageDescriber::$descCache[$cacheKey])) {
            return new Response(HttpStatus::OK, ['content-type' => 'text/plain'], ImageDescriber::$descCache[$cacheKey]);
        }

        try {
            $ai = (new ServiceLocator($entityManager))->getServiceConfig('ai');
            if (!$ai instanceof AiServiceConfig || $ai->apiKey === null || $ai->apiKey === '') {
                return new Response(HttpStatus::SERVICE_UNAVAILABLE, ['content-type' => 'text/plain'], "AI service not configured");
            }

            $settings = (new SettingsResolver($entityManager))->resolveGlobalLinktitles();
            $result = (new ImageDescriber($logger))->describe($body, $ai, $settings);
        } catch (\Throwable $e) {
            // e.g. a manually-deleted ai/settings row makes an entity refresh throw;
            // surface as 500 rather than killing the handler.
            return new Response(HttpStatus::INTERNAL_SERVER_ERROR, ['content-type' => 'text/plain'], "Internal error");
        }
        [$status, $message] = aidesc_map_result($result);
        if ($status === HttpStatus::OK) {
            ImageDescriber::$descCache[$cacheKey] = $result->description ?? '';
        }
        $logger->info("aidesc [" . ($apiKey->label ?? $apiKey->key) . "] " . ($result->error ?? 'ok')
            . ($result->error !== null ? " {$result->errorDetail}" : '') . " {$result->profile}");
        return new Response($status, ['content-type' => 'text/plain'], $message);
    });
    $router->addRoute('POST', '/aidesc', $handler);
}

/**
 * Map a DescribeResult to [HttpStatus, plain-text body].
 * @return array{0: int, 1: string}
 */
function aidesc_map_result(DescribeResult $result): array
{
    if ($result->error === null) {
        return [HttpStatus::OK, $result->description ?? ''];
    }
    return match ($result->error) {
        DescribeResult::TOO_LARGE => [HttpStatus::BAD_REQUEST, "image too large ({$result->errorDetail})"],
        DescribeResult::UNDECODABLE => [HttpStatus::BAD_REQUEST, "undecodable image"],
        DescribeResult::EMPTY => [HttpStatus::BAD_GATEWAY, "empty description"],
        DescribeResult::TIMEOUT => [HttpStatus::GATEWAY_TIMEOUT, "AI timeout"],
        default => [HttpStatus::BAD_GATEWAY, $result->errorDetail],
    };
}
