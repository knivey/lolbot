<?php

use Amp\Http\Server\Router;
use Amp\Http\Server\Server;
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

$restRouter = new Router;

/**
 * query string to key=>val array
 * @param string $v result from $request->getUri()->getQuery()
 * @return array
 */
function parseQuery($v) {
    $v = explode("&", $v);
    $vars = [];
    foreach ($v as $var) {
        @list($key, $val) = explode("=", $var, 1);
        @$vars[urldecode($key)] = isset($vay) ? urldecode($val) : true;
    }
    return $vars;
}

function startRestServer() {
    global $config, $restRouter;
    if(!isset($config['listen'])) {
        return null;
    }
    if(isset($config['listen_cert'])) {
        $cert = new Socket\Certificate($config['listen_cert']);
        $context = (new Socket\BindContext)
            ->withTlsContext((new Socket\ServerTlsContext)->withDefaultCertificate($cert));
    } else {
        $context = null;
    }
    $servers = [
        Socket\Server::listen($config['listen'], $context)
    ];
    //Probably setup logging from main later
    $logHandler = new StreamHandler(new ResourceOutputStream(STDOUT));
    $logHandler->setFormatter(new ConsoleFormatter);
    $logger = new Logger('server');
    $logger->pushHandler($logHandler);

    $server = new Server($servers, $restRouter, $logger);

    $restRouter->addRoute("POST", "/privmsg/{chan}", new CallableRequestHandler(function (Request $request) {
        $notifier_keys = Yaml::parseFile(__DIR__. '/notifier_keys.yaml');
        $key = $request->getHeader('key');
        if (isset($notifier_keys[$key])) {
            echo "Request from $notifier_keys[$key] ($key)\n";
        } else {
            return new Response(Status::FORBIDDEN, [
                "content-type" => "text/plain; charset=utf-8"
            ], "Invalid key");
        }
        $args = $request->getAttribute(Router::class);
        if(!isset($args['chan'])) { // todo not sure if needed
            return new Response(Status::BAD_REQUEST, [
                "content-type" => "text/plain; charset=utf-8"
            ], "Must specify a chan to privmsg");
        }
        $chan = "#{$args['chan']}";
        $msg = yield $request->getBody()->buffer();
        $msg = str_replace("\r", "\n", $msg);
        $msg = explode("\n", $msg);
        pumpToChan($chan, $msg);

        return new Response(Status::OK, [
            "content-type" => "text/plain; charset=utf-8"
        ], "PRIVMSG sent\n");
    }));

    yield $server->start();

    return $server;
}