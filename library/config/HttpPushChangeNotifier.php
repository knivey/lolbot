<?php
namespace lolbot\config;

/**
 * ChangeNotifier that POSTs each ConfigChange to the running channel bot's
 * global REST server (POST /_control/apply) so the change can be applied live.
 * Constructed by the mutating client (admin-cli / web) with the bot's listen URL
 * and core control key.
 *
 * Uses cURL (synchronous) because the mutating client runs in a plain PHP script
 * context with no Amp event loop driving — the Amp HTTP client would deadlock there.
 * Fire-and-forget: if the bot is unreachable, the change is already persisted in
 * the DB and will apply on next start.
 */
class HttpPushChangeNotifier implements ChangeNotifier
{
    public function __construct(
        private string $applyUrl,
        private string $controlKey,
    ) {}

    /**
     * Build the request spec (URL, headers, JSON body) for a change. Pure / no I/O,
     * so it can be unit-tested without a network.
     *
     * @return array{url: string, headers: list<string>, body: string}
     */
    public function buildRequest(ConfigChange $change): array
    {
        $payload = [
            'entityType' => $change->entityType,
            'id' => $change->id,
            'action' => $change->action,
        ];
        if ($change->data !== null) {
            $payload['data'] = $change->data;
        }
        return [
            'url' => rtrim($this->applyUrl, '/') . '/_control/apply',
            'headers' => [
                'key: ' . $this->controlKey,
                'content-type: application/json',
            ],
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
    }

    public function notify(ConfigChange $change): void
    {
        if (!function_exists('curl_init')) {
            return; // no cURL available — change is already in the DB; apply on next start.
        }
        try {
            $req = $this->buildRequest($change);
            $ch = curl_init($req['url']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $req['headers']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $req['body']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_exec($ch);
        } catch (\Throwable $e) {
            // Bot not running / unreachable — change is already in the DB.
            echo "live-sync push skipped: " . $e->getMessage() . "\n";
        }
    }
}
