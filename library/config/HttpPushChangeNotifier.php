<?php
namespace lolbot\config;

use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\Request;
use Amp\NullCancellation;

/**
 * ChangeNotifier that POSTs each ConfigChange to the running channel bot's
 * global REST server (POST /_control/apply) so the change can be applied live.
 * Constructed by the mutating client (admin-cli / web) with the bot's listen URL
 * and core control key. Fire-and-forget: if the bot is unreachable, the change
 * is already persisted in the DB and will apply on next start.
 */
class HttpPushChangeNotifier implements ChangeNotifier
{
    public function __construct(
        private DelegateHttpClient $http,
        private string $applyUrl,
        private string $controlKey,
    ) {}

    public function notify(ConfigChange $change): void
    {
        try {
            $payload = [
                'entityType' => $change->entityType,
                'id' => $change->id,
                'action' => $change->action,
            ];
            if ($change->data !== null) {
                $payload['data'] = $change->data;
            }
            $request = new Request(rtrim($this->applyUrl, '/') . '/_control/apply', 'POST');
            $request->setHeader('key', $this->controlKey);
            $request->setHeader('content-type', 'application/json');
            $request->setBody(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $this->http->request($request, new NullCancellation());
        } catch (\Throwable $e) {
            echo "live-sync push skipped: " . $e->getMessage() . "\n";
        }
    }
}
