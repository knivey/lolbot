#!/usr/bin/env php
<?php


require_once 'bootstrap.php';

use lolbot\entities\Ignore;
use lolbot\entities\Network;

use Amp\ByteStream;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Router;
use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\TimeoutCancellation;
use Monolog\Logger;
use Symfony\Component\Yaml\Yaml;
use function Amp\async;
use Amp\Future;

use \Revolt\EventLoop;
use knivey\irctools;

use Irc\Event\{ChatEvent, KickEvent, ModeEvent, WelcomeEvent, NumericEvent};
use const Irc\ERR_CANNOTSENDTOCHAN;

if(isset($argv[1])) {
    if(!file_exists($argv[1]) || !is_file($argv[1]))
        die("Usage: ".__FILE__." [config.yaml]\n  ({$argv[1]} does not exist or is not a file)\n");
    $configFile = $argv[1];
} else {
    $configFile = __DIR__."/artbotsconfig.yaml";
}

$config = Yaml::parseFile($configFile);
if(!is_array($config))
    die("bad config file");

if(!isset($config['networks']) || !is_array($config['networks']))
    die("config must have a 'networks' array\n");

use knivey\cmdr\Cmdr;

$logHandler = new StreamHandler(ByteStream\getStdout());
$logHandler->setFormatter(new ConsoleFormatter);
$logHandler->setLevel(\Psr\Log\LogLevel::INFO);

require_once 'library/Nicks.php';
require_once 'NetworkContext.php';
require_once 'artbot_rest_server.php';
require_once 'artbot_scripts/art-common.php';
require_once 'artbot_scripts/quotes.php';
require_once 'artbot_scripts/urlimg.php';
require_once 'artbot_scripts/drawing.php';
require_once 'artbot_scripts/bashorg.php';
require_once 'artbot_scripts/artfart.php';
require_once 'artbot_scripts/help.php';
require_once 'artbot_scripts/svg.php';

//copied from Cmdr should give it its own function in there later
/**
 * @param array<string, string> $validOpts
 * @return array<string, mixed>
 */
function parseOpts(string &$msg, array $validOpts = []): array {
    $opts = [];
    $msga = explode(' ', $msg);
    $msgb = [];
    foreach ($msga as $w) {
        if(str_contains($w, "=")) {
            list($lhs, $rhs) = explode("=", $w, 2);
        } else {
            $lhs = $w;
            $rhs = true;
        }
        if(in_array($lhs, $validOpts))
            $opts[$lhs] = $rhs;
        else
            $msgb[] = $w;
    }
    $msg = implode(' ', $msgb);
    return $opts;
}

\Revolt\EventLoop::setErrorHandler(function(\Throwable $error) {
    echo "Uncaught error: " . $error->getMessage() . "\n";
    $e = $error->getPrevious() ?? $error;
    echo "  at " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
    while ($e = $e->getPrevious()) {
        echo "Caused by: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
});

function onchat(\Irc\Event\ChatEvent $args, \Irc\Client $bot, NetworkContext $ctx): void
{
    async(function() use ($args, $bot, $ctx) {
        global $entityManager;

        $ignored = $ctx->ignoreCache->getItem($args->fullhost);
        if(!$ignored->isHit()) {
            $network = $entityManager->getRepository(Network::class)->find($ctx->networkId);
            $ignoreRepository = $entityManager->getRepository(Ignore::class);
            if (count($ignoreRepository->findMatching($args->fullhost, $network)) > 0)
                $ignored->set(true);
            else
                $ignored->set(false);
            $ctx->ignoreCache->save($ignored);
        }
        if($ignored->get())
            return;

        if(!$ctx->canRun($args))
            return;

        artbot_scripts\tryRec($bot, $args->nick, $args->text, $ctx);
        if (isset($ctx->config['trigger'])) {
            if (substr($args->text, 0, 1) != $ctx->config['trigger']) {
                return;
            }
            $text = substr($args->text, 1);
        } elseif (isset($ctx->config['trigger_re'])) {
            $trig = "/(^{$ctx->config['trigger_re']}).+$/";
            if (!preg_match($trig, $args->text, $m)) {
                return;
            }
            $text = substr($args->text, strlen($m[1]));
        } else {
            echo "No trigger defined\n";
            return;
        }

        //TODO SOME ART NAMES HAVE SPACES
        $text = explode(' ', $text);
        $cmd = strtolower(array_shift($text));
        $text = implode(' ', $text);


        if(trim($cmd) == '')
            return;
        if($ctx->router->cmdExists($cmd)) {
            try {
                $ctx->router->call($cmd, $text, $args, $bot);
            } catch (Exception $e) {
                $bot->notice($args->nick, $e->getMessage());
            }
        } else {
            $tmpText = $text;
            $opts = parseOpts($tmpText, $GLOBALS['reqArtOpts']);
            $cmdArgs = \knivey\tools\makeArgs($tmpText);
            if(!is_array($cmdArgs))
                $cmdArgs = [];
            artbot_scripts\reqart($bot, $args->chan, $cmd, $opts, $ctx);
        }
    });
}

function registerNetworkRoutes(artbot_rest_server $server, NetworkContext $ctx, string $prefix): void {
    $server->addRoute("POST", "/{$prefix}/privmsg/{chan}", new ClosureRequestHandler(
        function (Request $request) use ($ctx): Response {
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
            if(!isset($args['chan'])) {
                return new Response(HttpStatus::BAD_REQUEST, [
                    "content-type" => "text/plain; charset=utf-8"
                ], "Must specify a chan to privmsg");
            }
            $chan = "#{$args['chan']}";
            $msg = $request->getBody()->buffer();
            $msg = str_replace("\r", "\n", $msg);
            $msg = explode("\n", $msg);
            $ctx->pumpToChan($chan, $msg);

            return new Response(HttpStatus::OK, [
                "content-type" => "text/plain; charset=utf-8"
            ], "PRIVMSG sent\n");
    }));

    artbot_scripts\setupRestRoutes($server, $ctx, $prefix);
}

/**
 * @param array<string, mixed> $networkConfig
 */
function startNetwork(array $networkConfig, \Monolog\Handler\HandlerInterface $logHandler, ?artbot_rest_server $restServer, string $restUrl): NetworkContext
{
    $ctx = new NetworkContext($networkConfig);

    if ($restServer !== null) {
        $ctx->route = $networkConfig['route'];
        $ctx->restUrl = $restUrl;
        registerNetworkRoutes($restServer, $ctx, $networkConfig['route']);
    }

    $ctx->router = new Cmdr();
    $ctx->router->loadFuncs();

    $cnt = 0;
    foreach ($networkConfig['bots'] as $bcfg) {
        $log = new Logger("{$ctx->name}:{$bcfg['name']}");
        $log->pushHandler($logHandler);
        $bot = new \Irc\Client($bcfg['name'], $bcfg['server'], $log, $bcfg['port'], $bcfg['bindIp'], $bcfg['ssl']);
        $ctx->clients[] = $bot;
        $bot->setThrottle($bcfg['throttle'] ?? true);
        $bot->setServerPassword($bcfg['pass'] ?? '');
        if (isset($networkConfig['sasl_user']) && isset($networkConfig['sasl_pass'])) {
            $bot->setSasl($networkConfig['sasl_user'], $networkConfig['sasl_pass']);
        }

        NetworkContext::register($bot, $ctx);

        EventLoop::repeat(10, function() use ($bot, $bcfg) {
            if(!$bot->isEstablished()) {
                return;
            }
            if($bot->isCurrentNick($bcfg['name'])) {
                return;
            }
            $bot->nick($bcfg['name']);
        });

        $bot->on('welcome', function (WelcomeEvent $e, \Irc\Client $bot) use ($ctx) {
            if(isset($ctx->config['onconnect'])) {
                if(!is_array($ctx->config['onconnect'])) {
                    die("config['onconnect'] must be an array, found: " . get_debug_type($ctx->config['onconnect']));
                }
                foreach ($ctx->config["onconnect"] as $line) {
                    $line = str_replace('$me', $bot->getNick(), $line);
                    $bot->send($line);
                }
            }
            $bot->join(implode(',', $ctx->config['channels']));
        });

        $bot->on('kick', function (KickEvent $args, \Irc\Client $bot) {
            if($args->target == $bot->getNick())
                $bot->join($args->chan);
        });

        $bot->on(ERR_CANNOTSENDTOCHAN, function (NumericEvent $args, \Irc\Client $bot) use ($ctx) {
            $chan = $args->message->getArg(1);
            if(isset($ctx->playing[$chan])) {
                unset($ctx->playing[$chan]);
                echo "Stopping pump to $chan due to send error\n";
            }
        });

        $bot->on('mode', function(ModeEvent $args, \Irc\Client $bot) {
            if($args->target == $bot->getNick()) {
                $adding = true;
                foreach (str_split($args->args[0]) as $mode) {
                    switch($mode) {
                        case '+':
                            $adding = true;
                            break;
                        case '-':
                            $adding = false;
                            break;
                        case 'd':
                        case 'D':
                            if($adding)
                                $bot->send("MODE {$bot->getNick()} -{$mode}");
                    }
                }
            }
        });

        //Only first bot handles seeing commands, recording arts, etc
        if($cnt == 0) {
            $ctx->nicks = new Nicks($bot);
            if (function_exists("initQuotes"))
                initQuotes($bot, $ctx);

            $bot->on('chat', function(ChatEvent $args, $bot) use ($ctx) {
                onchat($args, $bot, $ctx);
            });
        }
        $cnt++;
    }

    foreach ($ctx->clients as $bot) {
        $bot->go();
    }

    return $ctx;
}

/**
 * @param NetworkContext[] $contexts
 */
function artbotShutdown(array $contexts, string $msg, ?artbot_rest_server $restServer): void {
    echo "shutdown started: $msg\n";
    $futures = [];
    foreach ($contexts as $ctx) {
        foreach ($ctx->clients as $bot) {
            if (!$bot->isConnected)
                continue;
            $futures[] = async(fn() => $bot->sendNow("quit :$msg\r\n"));
        }
    }
    try {
        \Amp\Future\awaitAll($futures, new TimeoutCancellation(5));
    } catch (Exception $e) {
        echo "Exception when sending quit\n $e\n";
    }
    foreach ($contexts as $ctx) {
        foreach ($ctx->clients as $bot) {
            $bot->exit();
        }
    }
    if ($restServer)
        $restServer->stop();
    \Amp\delay(0.5);
    echo "Stopped?\n";
    exit(0);
}

$contexts = [];
$restServer = null;

function main(): void {
    global $config, $logHandler, $contexts, $restServer;

    if (isset($config['listen'])) {
        if (!isset($config['rest_url']) || $config['rest_url'] === '') {
            $port = parse_url("http://{$config['listen']}", PHP_URL_PORT) ?? 80;
            $config['rest_url'] = "http://localhost:$port";
            echo "WARNING: rest_url not set, defaulting to {$config['rest_url']}\n";
        }
        $restServer = new artbot_rest_server($logHandler);
        $restServer->initRestServer($config);
    }

    $routes = [];
    foreach ($config['networks'] as $networkConfig) {
        if(!isset($networkConfig['name']))
            die("Each network must have a 'name'\n");
        if(!isset($networkConfig['network_id']))
            die("Each network must have a 'network_id'\n");
        if ($restServer !== null) {
            if (!isset($networkConfig['route']))
                die("Each network must have a 'route' when global listen is configured (missing for '{$networkConfig['name']}')\n");
            if (in_array($networkConfig['route'], $routes))
                die("Duplicate route '{$networkConfig['route']}' on network '{$networkConfig['name']}', routes must be unique\n");
            $routes[] = $networkConfig['route'];
        }
        echo "Starting network: {$networkConfig['name']}\n";
        $contexts[] = startNetwork($networkConfig, $logHandler, $restServer, $config['rest_url'] ?? '');
    }

    if ($restServer !== null) {
        $restServer->start();
    }

    EventLoop::onSignal(SIGINT, function () use ($contexts, $restServer): void {
        artbotShutdown($contexts, "Caught SIGINT GOODBYE!!!!", $restServer);
    });

    EventLoop::onSignal(SIGTERM, function () use ($contexts, $restServer): void {
        artbotShutdown($contexts, "Caught SIGTERM GOODBYE!!!!", $restServer);
    });
}

main();
EventLoop::run();
