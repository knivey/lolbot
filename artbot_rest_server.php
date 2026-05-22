<?php

use Amp\Http\Server\Router;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Server\ErrorHandler;
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
        $this->logger = new Logger("server");
        $this->logger->pushHandler($logHandler);
    }

    public function initRestServer(array $globalConfig) {
        if(!isset($globalConfig['listen'])) {
            return null;
        }
        if(isset($globalConfig['listen_cert'])) {
            $cert = new Socket\Certificate($globalConfig['listen_cert']);
            $context = (new Socket\BindContext)
                ->withTlsContext((new Socket\ServerTlsContext)->withDefaultCertificate($cert));
        } else {
            $context = null;
        }

        $this->server = SocketHttpServer::createForDirectAccess($this->logger);
        
        if(is_array($globalConfig['listen'])) {
            foreach ($globalConfig['listen'] as $address) {
                $this->server->expose($address, $context);
            }
        } else {
            $this->server->expose($globalConfig['listen'], $context);
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
        $this->restRouter->addRoute($method, $uri, $requestHandler);
    }
}
