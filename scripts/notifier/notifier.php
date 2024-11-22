<?php
namespace scripts\notifier;

use Symfony\Component\Yaml\Yaml;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;

function notifier(\Irc\Client $bot, $addresses, $logger) {
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

function requestHandler(Request $request, $bot) {
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