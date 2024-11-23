<?php

use Amp\Http\Server\Router;
use Amp\Http\Server\DefaultErrorHandler;
use Symfony\Component\Yaml\Yaml;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\HttpStatus;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\RequestHandler;
use Amp\Socket;
use Monolog\Logger;
use Cspray\Labrador\Http\Cors\ArrayConfiguration;
use Cspray\Labrador\Http\Cors\SimpleConfigurationLoader;
use Cspray\Labrador\Http\Cors\CorsMiddleware;
use function Amp\Http\Server\Middleware\stackMiddleware;

class artbot_rest_server {
    public  Router $restRouter;
    public Logger $logger;

    public SocketHttpServer $server;

    public RequestHandler $stack;

    public ErrorHandler $errorHandler;

    public function __construct(
        public $logHandler
    )
    {
        $this->logger = new Logger('server');
        $this->logger->pushHandler($logHandler);
    }

    public function initRestServer() {
        global $config;
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

        $this->server = SocketHttpServer::createForDirectAccess($this->logger);
        
        if(is_array($config['listen'])) {
            foreach ($config['listen'] as $address) {
                $this->server->expose($address, $context);
            }
        } else {
            $this->server->expose($config['listen'], $context);
        }


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

        $this->errorHandler = new DefaultErrorHandler();
        $this->restRouter = new Router($this->server, $this->logger, $this->errorHandler);

        $this->stack = stackMiddleware($this->restRouter, $middleware);

        $this->setupRoutes();

        return $this->server;
    }

    public function start() {
        if(isset($this->server))
            $this->server->start($this->stack, $this->errorHandler);
    }

    public function stop() {
        if(isset($this->server))
            $this->server->stop();
    }

    public function addRoute(string $method, string $uri, RequestHandler $requestHandler) {
        global $config;
        if(!isset($config['listen'])) {
            return null;
        }
        $this->restRouter->addRoute($method, $uri, $requestHandler);
    }

    private function setupRoutes() {
        $this->restRouter->addRoute("POST", "/privmsg/{chan}", new ClosureRequestHandler(
            function (Request $request): Response {
                $notifier_keys = Yaml::parseFile(__DIR__. '/notifier_keys.yaml');
                $key = $request->getHeader('key');
                if (isset($notifier_keys[$key])) {
                    echo "Request from $notifier_keys[$key] ($key)\n";
                } else {
                    echo \Irc\stripForTerminal("Blocked request for bad key $key\n");
                    return new Response(HttpStatus::FORBIDDEN, [
                        "content-type" => "text/plain; charset=utf-8"
                    ], "Invalid key");
                }
                $args = $request->getAttribute(Router::class);
                if(!isset($args['chan'])) { // todo not sure if needed
                    return new Response(HttpStatus::BAD_REQUEST, [
                        "content-type" => "text/plain; charset=utf-8"
                    ], "Must specify a chan to privmsg");
                }
                $chan = "#{$args['chan']}";
                $msg = $request->getBody()->buffer();
                $msg = str_replace("\r", "\n", $msg);
                $msg = explode("\n", $msg);
                pumpToChan($chan, $msg);

                return new Response(HttpStatus::OK, [
                    "content-type" => "text/plain; charset=utf-8"
                ], "PRIVMSG sent\n");
        }));
    }
}