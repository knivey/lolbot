<?php
namespace scripts\notifier;

use Symfony\Component\Yaml\Yaml;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;

/**
 * @param string|array<string> $addresses
 */
function notifier(\Irc\Client $bot, string|array $addresses, \Psr\Log\LoggerInterface $logger): SocketHttpServer {
    //$cert = new Socket\Certificate(__DIR__ . '/../test/server.pem');

    //$context = (new Socket\BindContext)
    //    ->withTlsContext((new Socket\ServerTlsContext)->withDefaultCertificate($cert));
    $server = SocketHttpServer::createForDirectAccess($logger);
    if(is_array($addresses)) {
        foreach ($addresses as $address) {
            $server->expose($address);
        }
    } else {
        $server->expose($addresses);
    }

    $errorHandler = new DefaultErrorHandler();

    $server->start(new ClosureRequestHandler(function (Request $request) use (&$bot) {
        return requestHandler($request, $bot);
    }), $errorHandler);

    return $server;
}

function requestHandler(Request $request, \Irc\Client $bot): Response {
    $path = $request->getUri()->getPath();
    $path = explode('/', $path);
    $path = array_filter($path);

    $action = array_shift($path);

    //TODO might setup an actual router here if more scripts will have webhooks etc
    if (strtolower($action) == 'owncast') {
        $chan = array_shift($path);
        if(!$chan) {
            return new Response(HttpStatus::BAD_REQUEST, [
                "content-type" => "text/plain; charset=utf-8"
            ], "Must specify a chan to privmsg");
        }
        $body = $request->getBody()->buffer();
        $json = json_decode($body, (bool)JSON_OBJECT_AS_ARRAY);
        //var_dump($json);
        //var_dump($body);
        if($json['type'] == 'STREAM_STARTED')
            $bot->pm("#$chan", "{$json['eventData']['name']} now streaming: {$json['eventData']['streamTitle']} | {$json['eventData']['summary']}");
        if($json['type'] == 'STREAM_STOPPED')
            $bot->pm("#$chan", "{$json['eventData']['name']} stream stopped");
        return new Response(HttpStatus::OK, [
            "content-type" => "text/plain; charset=utf-8"
        ], "PRIVMSG sent");
    }

    $notifier_keys = Yaml::parseFile(__DIR__. '/notifier_keys.yaml');
    $key = $request->getHeader('key');
    if (isset($notifier_keys[$key])) {
        echo "Request from {$notifier_keys[$key]} ($key)\n";
    } else {
        return new Response(HttpStatus::FORBIDDEN, [
            "content-type" => "text/plain; charset=utf-8"
        ], "Invalid key");
    }
    if (strtolower($action) == 'privmsg') {
        $chan = array_shift($path);
        if(!$chan) {
            return new Response(HttpStatus::BAD_REQUEST, [
                "content-type" => "text/plain; charset=utf-8"
            ], "Must specify a chan to privmsg");
        }
        $msg = $request->getBody()->buffer();
        $msg = str_replace("\r", "\n", $msg);
        $msg = explode("\n", $msg);
        foreach($msg as $line) {
            $bot->pm("#$chan", substr($line, 0, 400));
        }
        return new Response(HttpStatus::OK, [
            "content-type" => "text/plain; charset=utf-8"
        ], "PRIVMSG sent");
    }

    return new Response(HttpStatus::BAD_REQUEST, [
        "content-type" => "text/plain; charset=utf-8"
    ], "Unknown request");
}

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