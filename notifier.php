<?php

use Symfony\Component\Yaml\Yaml;
use Amp\ByteStream\ResourceOutputStream;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket;
use Monolog\Logger;

function notifier($bot) {
    //$cert = new Socket\Certificate(__DIR__ . '/../test/server.pem');

    //$context = (new Socket\BindContext)
    //    ->withTlsContext((new Socket\ServerTlsContext)->withDefaultCertificate($cert));

    $servers = [
        Socket\Server::listen("0.0.0.0:1337"),
        Socket\Server::listen("[::]:1337"),
    ];
    //Probably setup logging from main later
    $logHandler = new StreamHandler(new ResourceOutputStream(STDOUT));
    $logHandler->setFormatter(new ConsoleFormatter);
    $logger = new Logger('server');
    $logger->pushHandler($logHandler);

    $server = new HttpServer($servers, new CallableRequestHandler(static function (Request $request) use (&$bot) {
        $notifier_keys = Yaml::parseFile('notifier_keys.yaml');
        $key = $request->getHeader('key');
        if (isset($notifier_keys[$key])) {
            echo "Request from $notifier_keys[$key] ($key)\n";
        } else {
            return new Response(Status::FORBIDDEN, [
                "content-type" => "text/plain; charset=utf-8"
            ], "Invalid key");
        }
        $path = $request->getUri()->getPath();
        $path = explode('/', $path);
        $path = array_filter($path);

        $action = array_shift($path);
        if (strtolower($action) == 'privmsg') {
            $chan = array_shift($path);
            if(!$chan) {
                return new Response(Status::BAD_REQUEST, [
                    "content-type" => "text/plain; charset=utf-8"
                ], "Must specify a chan to privmsg");
            }
            $msg = yield $request->getBody()->buffer();
            $msg = explode("\n", $msg);
            foreach($msg as $line) {
                $bot->pm("#$chan", substr($line, 0, 400));
            }
            return new Response(Status::OK, [
                "content-type" => "text/plain; charset=utf-8"
            ], "PRIVMSG sent");
        }

        return new Response(Status::BAD_REQUEST, [
            "content-type" => "text/plain; charset=utf-8"
        ], "Unknown request");
    }), $logger);

    yield $server->start();

    // Stop the server when SIGINT is received (this is technically optional, but it is best to call Server::stop()).
    Amp\Loop::onSignal(\SIGINT, static function (string $watcherId) use ($server) {
        Amp\Loop::cancel($watcherId);
        yield $server->stop();
    });
}