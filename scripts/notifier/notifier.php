<?php
namespace scripts\notifier;

use Symfony\Component\Yaml\Yaml;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\HttpStatus;

/**
 * Register the notifier's privmsg/owncast routes on the shared global REST server.
 * Routes are namespaced by bot id: POST /notifier/{botid}/privmsg/{chan},
 * POST /notifier/{botid}/owncast/{chan}. Auth via notifier_keys.yaml (key header).
 *
 * The {chan} segment is the channel name without the leading '#'; it is prepended here.
 */
function notifier_register(Router $router, \library\BotManager $mgr): void {
    $keysFile = __DIR__ . '/notifier_keys.yaml';
    $loadKeys = function () use ($keysFile): array {
        if (!file_exists($keysFile)) return [];
        $k = Yaml::parseFile($keysFile);
        return is_array($k) ? $k : [];
    };

    $privmsg = new ClosureRequestHandler(function (Request $request) use ($mgr, $loadKeys) {
        $args = $request->getAttribute(Router::class);
        $botId = 0;
        $chan = null;
        if (is_array($args)) {
            $rawBotId = $args['botid'] ?? null;
            if (is_int($rawBotId)) {
                $botId = $rawBotId;
            } elseif (is_string($rawBotId)) {
                $botId = (int)$rawBotId;
            }
            $rawChan = $args['chan'] ?? null;
            if (is_string($rawChan)) {
                $chan = '#' . $rawChan;
            }
        }
        $client = $mgr->clients[$botId] ?? null;
        if ($client === null || $chan === null) {
            return new Response(HttpStatus::NOT_FOUND, ['content-type' => 'text/plain'], "No such bot");
        }
        $keys = $loadKeys();
        if (!array_key_exists((string)$request->getHeader('key'), $keys)) {
            return new Response(HttpStatus::FORBIDDEN, ['content-type' => 'text/plain'], "Invalid key");
        }
        $msg = $request->getBody()->buffer();
        $msg = str_replace("\r", "\n", $msg);
        foreach (explode("\n", $msg) as $line) {
            $client->pm($chan, substr($line, 0, 400));
        }
        return new Response(HttpStatus::OK, ['content-type' => 'text/plain'], "PRIVMSG sent");
    });
    $router->addRoute('POST', '/notifier/{botid}/privmsg/{chan}', $privmsg);

    $owncast = new ClosureRequestHandler(function (Request $request) use ($mgr, $loadKeys) {
        $args = $request->getAttribute(Router::class);
        $botId = 0;
        $chan = null;
        if (is_array($args)) {
            $rawBotId = $args['botid'] ?? null;
            if (is_int($rawBotId)) {
                $botId = $rawBotId;
            } elseif (is_string($rawBotId)) {
                $botId = (int)$rawBotId;
            }
            $rawChan = $args['chan'] ?? null;
            if (is_string($rawChan)) {
                $chan = '#' . $rawChan;
            }
        }
        $client = $mgr->clients[$botId] ?? null;
        if ($client === null || $chan === null) {
            return new Response(HttpStatus::NOT_FOUND, ['content-type' => 'text/plain'], "No such bot");
        }
        $keys = $loadKeys();
        if (!array_key_exists((string)$request->getHeader('key'), $keys)) {
            return new Response(HttpStatus::FORBIDDEN, ['content-type' => 'text/plain'], "Invalid key");
        }
        $json = json_decode($request->getBody()->buffer(), true);
        if (is_array($json)) {
            if (($json['type'] ?? '') === 'STREAM_STARTED') {
                $eventData = is_array($json['eventData'] ?? null) ? $json['eventData'] : [];
                $name = is_string($eventData['name'] ?? null) ? $eventData['name'] : '';
                $streamTitle = is_string($eventData['streamTitle'] ?? null) ? $eventData['streamTitle'] : '';
                $summary = is_string($eventData['summary'] ?? null) ? $eventData['summary'] : '';
                $client->pm($chan, "$name now streaming: $streamTitle | $summary");
            }
            if (($json['type'] ?? '') === 'STREAM_STOPPED') {
                $eventData = is_array($json['eventData'] ?? null) ? $json['eventData'] : [];
                $name = is_string($eventData['name'] ?? null) ? $eventData['name'] : '';
                $client->pm($chan, "$name stream stopped");
            }
        }
        return new Response(HttpStatus::OK, ['content-type' => 'text/plain'], "Owncast handled");
    });
    $router->addRoute('POST', '/notifier/{botid}/owncast/{chan}', $owncast);
}