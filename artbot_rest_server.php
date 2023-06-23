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
use Cspray\Labrador\Http\Cors\ArrayConfiguration;
use Cspray\Labrador\Http\Cors\SimpleConfigurationLoader;
use Cspray\Labrador\Http\Cors\CorsMiddleware;
use function Amp\Http\Server\Middleware\stack;

$restRouter = new Router;

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
    $sockets = [];
    if(is_array($config['listen'])) {
        foreach ($config['listen'] as $address) {
            $sockets[] =  Socket\Server::listen($address, $context);
        }
    } else {
        $sockets[] =  Socket\Server::listen($config['listen'], $context);
    }

    //Probably setup logging from main later
    $logHandler = new StreamHandler(new ResourceOutputStream(STDOUT));
    $logHandler->setFormatter(new ConsoleFormatter);
    $logger = new Logger('server');
    $logger->pushHandler($logHandler);

    $arrayConfig = [
        'origins' => ['*'],
        'allowed_methods' => ['GET', 'POST', 'PUT'],
        'max_age' => 8600,
        'allowed_headers' => ['content-type'],
        'exposable_headers' => ['content-type'],
        'allow_credentials' => false
    ];

    $loader = new SimpleConfigurationLoader(new ArrayConfiguration($arrayConfig));
    $middleware = new CorsMiddleware($loader);

    $server = new Server($sockets, stack($restRouter, $middleware), $logger);

    $restRouter->addRoute("POST", "/privmsg/{chan}", new CallableRequestHandler(function (Request $request) {
        $notifier_keys = Yaml::parseFile(__DIR__. '/notifier_keys.yaml');
        $key = $request->getHeader('key');
        if (isset($notifier_keys[$key])) {
            echo "Request from $notifier_keys[$key] ($key)\n";
        } else {
            echo \Irc\stripForTerminal("Blocked request for bad key $key\n");
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