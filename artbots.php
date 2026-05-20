#!/usr/bin/env php
<?php


require_once 'bootstrap.php';

use lolbot\entities\Ignore;
use lolbot\entities\Network;
use Symfony\Component\Yaml\Yaml;

use Amp\ByteStream;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\TimeoutCancellation;
use Monolog\Logger;
use function Amp\async;
use Amp\Future;

use \Revolt\EventLoop;
use knivey\irctools;

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

//copied from Cmdr should give it its own function in there later
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
    echo "Uncaught error thrown:\n";
    echo $error->getTraceAsString();
});

function onchat($args, \Irc\Client $bot, NetworkContext $ctx)
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

        artbot_scripts\tryRec($bot, $args->from, $args->text, $ctx);
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
                $bot->notice($args->from, $e->getMessage());
            }
        } else {
            $tmpText = $text;
            $opts = parseOpts($tmpText, $GLOBALS['reqArtOpts']);
            $cmdArgs = \knivey\tools\makeArgs($tmpText);
            if(!is_array($cmdArgs))
                $cmdArgs = [];
            artbot_scripts\reqart($bot, $args->channel, $cmd, $opts, $cmdArgs, $ctx);
        }
    });
}

function startNetwork(array $networkConfig, $logHandler): NetworkContext
{
    $ctx = new NetworkContext($networkConfig);

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

        $bot->on('welcome', function ($e, \Irc\Client $bot) use ($ctx) {
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

        $bot->on('kick', function ($args, \Irc\Client $bot) {
            if($args->nick == $bot->getNick())
                $bot->join($args->channel);
        });

        $bot->on(ERR_CANNOTSENDTOCHAN, function ($args, \Irc\Client $bot) use ($ctx) {
            $chan = $args->message->getArg(1);
            if(isset($ctx->playing[$chan])) {
                unset($ctx->playing[$chan]);
                echo "Stopping pump to $chan due to send error\n";
            }
        });

        $bot->on('mode', function($args, \Irc\Client $bot) {
            if($args->on == $bot->getNick()) {
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

            $bot->on('chat', function($args, $bot) use ($ctx) {
                onchat($args, $bot, $ctx);
            });
        }
        $cnt++;
    }

    if (isset($networkConfig['listen'])) {
        $ctx->restServer = new artbot_rest_server($logHandler, $ctx);
        $ctx->restServer->initRestServer();
        artbot_scripts\setupRestRoutes($ctx->restServer, $ctx);
        $ctx->restServer->start();
    }

    foreach ($ctx->clients as $bot) {
        $bot->go();
    }

    return $ctx;
}

function shutdown(array $contexts, string $msg) {
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
        if ($ctx->restServer)
            $ctx->restServer->stop();
    }
    \Amp\delay(0.5);
    echo "Stopped?\n";
    exit(0);
}

$contexts = [];

function main() {
    global $config, $logHandler, $contexts;

    foreach ($config['networks'] as $networkConfig) {
        if(!isset($networkConfig['name']))
            die("Each network must have a 'name'\n");
        if(!isset($networkConfig['network_id']))
            die("Each network must have a 'network_id'\n");
        echo "Starting network: {$networkConfig['name']}\n";
        $contexts[] = startNetwork($networkConfig, $logHandler);
    }

    EventLoop::onSignal(SIGINT, function () use ($contexts): void {
        shutdown($contexts, "Caught SIGINT GOODBYE!!!!");
    });

    EventLoop::onSignal(SIGTERM, function () use ($contexts): void {
        shutdown($contexts, "Caught SIGTERM GOODBYE!!!!");
    });
}

main();
EventLoop::run();
