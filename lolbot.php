#!/usr/bin/env php
<?php
require_once 'bootstrap.php';
dieIfPendingMigration();

use \Revolt\EventLoop;
use Amp\ByteStream;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use function Amp\async;
use lolbot\entities\Network;
use Monolog\Logger;
use knivey\cmdr\Cmdr;
use Crell\Tukio\Dispatcher;
use Crell\Tukio\OrderedListenerProvider;

use Irc\Event\{ChatEvent, PmEvent, KickEvent, ModeEvent, WelcomeEvent};
use lolbot\entities\Ignore;

$logHandler = new StreamHandler(ByteStream\getStdout());
$logHandler->setFormatter(new ConsoleFormatter);
$logHandler->setLevel(\Psr\Log\LogLevel::INFO);



/**
 * helper to make replies in cmds easier
 * @param \Irc\Event\ChatEvent $args
 * @param \Irc\Client $bot
 * @param string $prefix
 * @return array{0: \Closure(string,?string=): void, 1: \Closure(string,?string=): void}
 */
function makeRepliers(\Irc\Event\ChatEvent $args, \Irc\Client $bot, string $prefix): array {
    return [
        function (string $msg, ?string $err = null) use ($args, $bot, $prefix) {
            if($err == null) {
                $bot->pm($args->chan, "\2$prefix:\2 $msg");
            } else {
                $bot->pm($args->chan, "\2$prefix $err:\2 $msg");
            }
        },
        function (string $msg, ?string $err = null) use ($args, $bot, $prefix) {
            if($err == null) {
                $bot->notice($args->nick, "\2$prefix:\2 $msg");
            } else {
                $bot->notice($args->nick, "\2$prefix $err:\2 $msg");
            }
        }
    ];
}

require_once 'library/Duration.inc';

require_once 'scripts/notifier/notifier.php';

//require_once 'scripts/bing/bing.php';
require_once 'scripts/brave/brave.php';
require_once 'scripts/wolfram/wolfram.php';
require_once 'scripts/owncast/owncast.php';
require_once 'scripts/zyzz/zyzz.php';
require_once 'scripts/wiki/wiki.php';
require_once 'scripts/insult/insult.php';
require_once "scripts/mal/mal.php";

use scripts\bomb_game\bomb_game;

use scripts\lastfm\lastfm;
use scripts\alias\alias;
use scripts\weather\weather;
use scripts\remindme\remindme;
use scripts\tell\tell;
use scripts\seen\seen;
use scripts\codesand\codesand;

use scripts\tools\tools;

use scripts\urbandict\urbandict;
use scripts\help\help;
use scripts\stocks\stocks;
use scripts\crypto\crypto;

use scripts\linktitles\linktitles;
use scripts\youtube\youtube;
use scripts\twitter\twitter;
use scripts\invidious\invidious;
use scripts\github\github;
use scripts\tiktok\tiktok;
use scripts\reddit\reddit;
use scripts\imgur\imgur;

require_once 'scripts/translate/translate.php';
require_once 'scripts/yoda/yoda.php';


//copied from Cmdr should give it its own function in there later
/**
 * @param array<string> $validOpts
 * @return array<string, string|null>
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
            $rhs = null;
        }
        if(in_array($lhs, $validOpts))
            $opts[$lhs] = $rhs;
        else
            $msgb[] = $w;
    }
    $msg = implode(' ', $msgb);
    return $opts;
}

require_once 'library/Nicks.php';
require_once 'library/Channels.php';
require_once 'library/extract_opts_and_args.php';

\Revolt\EventLoop::setErrorHandler(function(\Throwable $error) {
    echo "Uncaught error: " . $error->getMessage() . "\n";
    $e = $error->getPrevious() ?? $error;
    echo "  at " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
    while ($e = $e->getPrevious()) {
        echo "Caused by: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
});

use Symfony\Component\Cache\Adapter\ArrayAdapter;
global $ORMconfig;
$ignoreCache = new ArrayAdapter(defaultLifetime: 5, storeSerialized: false, maxLifetime: 10, maxItems: 100);

function main(): void {
    global $entityManager, $config, $logHandler;
    $mgr = new \library\BotManager($entityManager);

    // Start every existing bot.
    $nets = $entityManager->getRepository(Network::class)->findAll();
    foreach ($nets as $network) {
        foreach ($network->getBots() as $bot) {
            $mgr->spawn($network, $bot);
        }
    }

    // Global REST server (single listen + control_key), if configured.
    $server = null;
    if (isset($config['listen'])) {
        $logger = new Logger("control");
        $logger->pushHandler($logHandler);
        $server = \Amp\Http\Server\SocketHttpServer::createForDirectAccess($logger);
        $server->expose($config['listen']);
        $router = new \Amp\Http\Server\Router($server, $logger, new \Amp\Http\Server\DefaultErrorHandler());
        $coreKey = is_string($config['control_key'] ?? null) ? $config['control_key'] : '';

        // POST /_control/apply  {entityType, id, action, data?}
        $router->addRoute('POST', '/_control/apply', new \Amp\Http\Server\RequestHandler\ClosureRequestHandler(
            function (\Amp\Http\Server\Request $request) use ($mgr, $coreKey) {
                if ($coreKey === '' || !hash_equals($coreKey, (string)$request->getHeader('key'))) {
                    return new \Amp\Http\Server\Response(403, ['content-type' => 'text/plain'], "Invalid key");
                }
                $payload = json_decode($request->getBody()->buffer(), true);
                if (!is_array($payload) || !isset($payload['entityType'], $payload['action'])) {
                    return new \Amp\Http\Server\Response(400, ['content-type' => 'text/plain'], "Bad payload");
                }
                $data = null;
                if (isset($payload['data']) && is_array($payload['data'])) {
                    $data = [];
                    foreach ($payload['data'] as $k => $v) {
                        $data[(string)$k] = $v;
                    }
                }
                $change = new \lolbot\config\ConfigChange(
                    (string)$payload['entityType'],
                    isset($payload['id']) ? (int)$payload['id'] : null,
                    (string)$payload['action'],
                    $data,
                );
                \Amp\async(fn() => $mgr->apply($change));
                return new \Amp\Http\Server\Response(200, ['content-type' => 'text/plain'], "applied");
            }
        ));

        // Manual lifecycle: /_control/reconnect/{botid}, /jump/{botid}, /respawn/{botid}
        $makeLifecycle = function (string $method) use ($mgr, $coreKey) {
            return new \Amp\Http\Server\RequestHandler\ClosureRequestHandler(
                function (\Amp\Http\Server\Request $request) use ($mgr, $coreKey, $method) {
                    if ($coreKey === '' || !hash_equals($coreKey, (string)$request->getHeader('key'))) {
                        return new \Amp\Http\Server\Response(403, ['content-type' => 'text/plain'], "Invalid key");
                    }
                    $args = $request->getAttribute(\Amp\Http\Server\Router::class);
                    $rawBotId = is_array($args) && isset($args['botid']) ? $args['botid'] : 0;
                    $botId = is_numeric($rawBotId) ? (int)$rawBotId : 0;
                    if (!isset($mgr->clients[$botId])) {
                        return new \Amp\Http\Server\Response(404, ['content-type' => 'text/plain'], "No such bot");
                    }
                    \Amp\async(fn() => match ($method) {
                        'reconnect' => $mgr->reconnect($botId),
                        'jump' => $mgr->jump($botId),
                        'respawn' => $mgr->respawn($botId),
                        default => null,
                    });
                    return new \Amp\Http\Server\Response(200, ['content-type' => 'text/plain'], "$method queued");
                }
            );
        };
        $router->addRoute('POST', '/_control/reconnect/{botid}', $makeLifecycle('reconnect'));
        $router->addRoute('POST', '/_control/jump/{botid}', $makeLifecycle('jump'));
        $router->addRoute('POST', '/_control/respawn/{botid}', $makeLifecycle('respawn'));

        // GET /_control/status  — JSON live status of all running bots (for the web panel).
        $router->addRoute('GET', '/_control/status', new \Amp\Http\Server\RequestHandler\ClosureRequestHandler(
            function (\Amp\Http\Server\Request $request) use ($mgr, $coreKey) {
                if ($coreKey === '' || !hash_equals($coreKey, (string)$request->getHeader('key'))) {
                    return new \Amp\Http\Server\Response(403, ['content-type' => 'text/plain'], "Invalid key");
                }
                return new \Amp\Http\Server\Response(
                    200,
                    ['content-type' => 'application/json'],
                    json_encode(['bots' => $mgr->allBotStatuses()], JSON_UNESCAPED_SLASHES),
                );
            }
        ));

        // Scripts register their routes on the shared server. (Defined in Task 6.)
        if (function_exists('\\scripts\\notifier\\notifier_register')) {
            \scripts\notifier\notifier_register($router, $mgr);
        }

        $server->start($router, new \Amp\Http\Server\DefaultErrorHandler());
    }

    EventLoop::onSignal(SIGINT, function () use ($mgr, $server): void {
        shutdown($mgr, $server, "Caught SIGINT GOODBYE!!!!");
    });
    EventLoop::onSignal(SIGTERM, function () use ($mgr, $server): void {
        shutdown($mgr, $server, "Caught SIGTERM GOODBYE!!!!");
    });
}

function shutdown(\library\BotManager $mgr, ?\Amp\Http\Server\HttpServer $server, string $msg): void {
    echo "shutdown started: $msg\n";
    foreach ($mgr->clients as $bot) {
        if (!$bot->isConnected) continue;
        try { $bot->sendNow("quit :$msg\r\n"); } catch (\Exception $e) { echo "Exception when sending quit\n $e\n"; }
        $bot->exit();
    }
    $server?->stop();
    \Amp\delay(0.5);
    echo "Stopped?\n";
    exit(0);
}

main();
EventLoop::run();