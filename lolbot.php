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

use lolbot\entities\Ignore;

$logHandler = new StreamHandler(ByteStream\getStdout());
$logHandler->setFormatter(new ConsoleFormatter);
$logHandler->setLevel(\Psr\Log\LogLevel::INFO);



/**
 * helper to make replies in cmds easier
 * @param object $args
 * @param \Irc\Client $bot
 * @param string $prefix
 * @return array{0: \Closure(string,?string=): void, 1: \Closure(string,?string=): void}
 */
function makeRepliers(object $args, \Irc\Client $bot, string $prefix): array {
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
//use scripts\remindme\remindme;
use scripts\tell\tell;
use scripts\seen\seen;
//use scripts\codesand\codesand;

use scripts\tools\tools;

//use scripts\urbandict\urbandict;
//use scripts\help\help;
//use scripts\stocks\stocks;

use scripts\linktitles\linktitles;
//use scripts\youtube\youtube;
//use scripts\twitter\twitter;
//use scripts\invidious\invidious;
//use scripts\github\github;

use scripts\reddit\reddit;

require_once 'scripts/durendaltv/durendaltv.php';
require_once 'scripts/translate/translate.php';
require_once 'scripts/yoda/yoda.php';


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

/**
 * @var \Irc\Client[]
 */
$clients = [];
/**
 * notify servers
 * @var Amp\Http\Server\HttpServer[] $servers
 */
$servers = [];

use Symfony\Component\Cache\Adapter\ArrayAdapter;
global $ORMconfig;
$ignoreCache = new ArrayAdapter(defaultLifetime: 5, storeSerialized: false, maxLifetime: 10, maxItems: 100);

function startBot(lolbot\entities\Network $network, lolbot\entities\Bot $dbBot): \Irc\Client
{
    global $config, $logHandler;
    //TODO add support and check for per bot servers first
    $server = $network->selectServer();
    $log = new Logger($dbBot->name);
    $log->pushHandler($logHandler);
    $client = new \Irc\Client($dbBot->name, $server->address, $log, (string)$server->port, $dbBot->bindIp, $server->ssl);
    $client->setThrottle($server->throttle);
    $client->setServerPassword($server->password ?? '');
    if (isset($dbBot->sasl_user) && isset($dbBot->sasl_pass)) {
        $client->setSasl($dbBot->sasl_user, $dbBot->sasl_pass);
    }
    $nicks = new Nicks($client);
    $chans = new Channels($client);

    $router = new Cmdr();
    $router->loadFuncs();

    $bomb_game = new bomb_game($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:bomb_game", [$logHandler]), $nicks, $chans, $router);
    $router->loadMethods($bomb_game);
    $alias = new alias($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:alias", [$logHandler]), $nicks, $chans, $router);
    $router->loadMethods($alias);
    $weather = new weather($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:weather", [$logHandler]), $nicks, $chans, $router);
    $router->loadMethods($weather);
    $lastfm = new lastfm($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:lastfm", [$logHandler]), $nicks, $chans, $router);
    $router->loadMethods($lastfm);
//    $remindme = new remindme($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:remindme", [$logHandler]), $nicks, $chans, $router);
//    $router->loadMethods($remindme);
    $tell = new tell($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:tell", [$logHandler]), $nicks, $chans, $router);
    $router->loadMethods($tell);
    $seen = new seen($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:seen", [$logHandler]), $nicks, $chans, $router);
    $router->loadMethods($seen);
//    $codesand = new codesand($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:codesand", [$logHandler]), $nicks, $chans, $router);
//    $router->loadMethods($codesand);
    $tools = new tools($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:tools", [$logHandler]), $nicks, $chans, $router);
    $router->loadMethods($tools);
//    $urbandict = new urbandict($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:urbandict", [$logHandler]), $nicks, $chans, $router);
//    $router->loadMethods($urbandict);
//    $help = new help($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:help", [$logHandler]), $nicks, $chans, $router);
//    $router->loadMethods($help);
//    $stocks = new stocks($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:stocks", [$logHandler]), $nicks, $chans, $router);
//    $router->loadMethods($stocks);

    $eventLogger = new Logger("{$dbBot->name}:linktitles:UrlEvents", [$logHandler]);
    $eventProvider = new OrderedListenerProvider();
    $eventDispatcher = new Dispatcher($eventProvider, $eventLogger);
    $linktitles = new linktitles($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:linktitles", [$logHandler]), $nicks, $chans, $router);
    $linktitles->eventDispatcher = $eventDispatcher;
    $router->loadMethods($linktitles);

//    $youtube = new youtube($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:youtube", [$logHandler]), $nicks, $chans, $router);
//    $youtube->setEventProvider($eventProvider);
//    $router->loadMethods($youtube);

    //$twitter = new twitter($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:twitter", [$logHandler]), $nicks, $chans, $router);
    //$twitter->setEventProvider($eventProvider);
    //$router->loadMethods($twitter);

//    $invidious = new invidious($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:invidious", [$logHandler]), $nicks, $chans, $router);
//    $invidious->setEventProvider($eventProvider);
//    $router->loadMethods($invidious);

//    $github = new github($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:github", [$logHandler]), $nicks, $chans, $router);
//    $github->setEventProvider($eventProvider);
//    $router->loadMethods($github);

    $reddit = new reddit($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:reddit", [$logHandler]), $nicks, $chans, $router);
    $reddit->setEventProvider($eventProvider);
    $router->loadMethods($reddit);

    $client->on('welcome', function ($e, \Irc\Client $bot) use ($dbBot) {
        foreach (explode("\n", $dbBot->onConnect) as $line) {
            if($line == "")
                continue;
            str_replace('$me', $bot->getNick(), $line);
            $bot->send($line);
        }
        $join = [];
        foreach ($dbBot->getChannels() as $channel)
            $join[] = $channel->name;
        $bot->join(implode(',', $join));
    });

    EventLoop::repeat(10, function() use ($client, $dbBot) {
        if(!$client->isEstablished()) {
            return;
        }
        if($client->isCurrentNick($dbBot->name)) {
            return;
        }
        $client->nick($dbBot->name);
    });

    $client->on('kick', function ($args, \Irc\Client $bot) {
        if ($args->nick == $bot->getNick())
            $bot->join($args->channel);
    });

    //Stop abuse from an IRCOP called sylar
    $client->on('mode', function ($args, \Irc\Client $bot) {
        if ($args->on == $bot->getNick()) {
            $adding = true;
            foreach (str_split($args->args[0]) as $mode) {
                switch ($mode) {
                    case '+':
                        $adding = true;
                        break;
                    case '-':
                        $adding = false;
                        break;
                    case 'd':
                    case 'D':
                        if ($adding)
                            $bot->send("MODE {$bot->getNick()} -{$mode}");
                }
            }
        }
    });

    $client->on('chat', function ($args, \Irc\Client $bot) use ($alias, $linktitles, $network, $dbBot, $router) {
        try {
            global $config, $entityManager, $ignoreCache;

            $ignored = $ignoreCache->getItem($args->fullhost);
            if (!$ignored->isHit()) {
                $ignoreRepository = $entityManager->getRepository(Ignore::class);
                if (count($ignoreRepository->findMatching($args->fullhost, $network)) > 0)
                    $ignored->set(true);
                else
                    $ignored->set(false);
                $ignoreCache->save($ignored);
            }
            if ($ignored->get())
                return;


            if ($config['bots'][$dbBot->id]['linktitles'] ?? false) {
                async(fn() => $linktitles->linktitles($bot, $args->nick, $args->channel, $args->identhost, $args->text));
            }

            if ($dbBot->trigger != "") {
                if (substr($args->text, 0, 1) != $dbBot->trigger) {
                    return;
                }
                $text = substr($args->text, 1);
            } elseif ($dbBot->trigger_re != "") {
                $trig = "/(^{$dbBot->trigger_re}).+$/";
                if (!preg_match($trig, $args->text, $m)) {
                    return;
                }
                $text = substr($args->text, strlen($m[1]));
            } else {
                echo "No trigger defined\n";
                return;
            }


            $ar = explode(' ', $text);
            if (array_shift($ar) == 'ping') {
                $bot->msg($args->channel, "Pong");
            }
            /*
            $ar = explode(' ', $text);
            if (array_shift($ar) == 'test') {
                $lines = $chans->dump();
                foreach($lines as $line)
                    $bot->msg($args->nick, $line);
                return;
            }*/


            $text = explode(' ', $text);
            $cmd = array_shift($text);
            $text = implode(' ', $text);
            if (trim($cmd) == '')
                return;

            async(function () use ($cmd, $text, $args, $bot, $router, $alias): void {
                if ($router->cmdExists($cmd)) {
                    try {
                        $router->call($cmd, $text, $args, $bot);
                    } catch (Exception $e) {
                        $bot->notice($args->from, $e->getMessage());
                    }
                } else {
                    //call other cmd handlers
                    $tmpText = $text;
                    $opts = parseOpts($tmpText, []);
                    $cmdArgs = \knivey\tools\makeArgs($tmpText);
                    if (!is_array($cmdArgs))
                        $cmdArgs = [];
                    if (count($cmdArgs) == 1 && $cmdArgs[0] == "")
                        $cmdArgs = [];
                    $alias->handleCmd($args, $bot, $cmd, $cmdArgs);
                }
            });
        } catch (Exception $e) {
            echo "UNCAUGHT EXCEPTION $e\n";
        }
    });
    $client->on('pm', function ($args, \Irc\Client $bot) use ($router) {
        $text = explode(' ', $args->text);
        $cmd = array_shift($text);
        $text = implode(' ', $text);
        if (trim($cmd) == '')
            return;

        try {
            $router->callPriv($cmd, $text, $args, $bot);
        } catch (Exception $e) {
            $bot->notice($args->from, $e->getMessage());
        }
    });
    $client->go();
    return $client;
}

function main() {
    global $clients, $entityManager, $config, $servers, $logHandler;
    $nets = $entityManager->getRepository(Network::class)->findAll();
    foreach ($nets as $network) {
        foreach ($network->getBots() as $bot) {
            $clients[$bot->id] = startBot($network, $bot);
            if(isset($config['bots'][$bot->id]['listen'])) {
                $logger = new Logger("{$bot->name}:notifier");
                $logger->pushHandler($logHandler);
                $servers[$bot->id] = \scripts\notifier\notifier($clients[$bot->id], $config['bots'][$bot->id]['listen'], $logger);
            }
        }
    }

    //TODO first time attempt gracefull, second catch force it
    EventLoop::onSignal(SIGINT, function () use ($servers, $clients): void {
        shutdown($clients, $servers, "Caught SIGINT GOODBYE!!!!");
    });

    EventLoop::onSignal(SIGTERM, function () use ($servers, $clients): void {
        shutdown($clients, $servers, "Caught SIGTERM GOODBYE!!!!");
    });
}


function shutdown($clients, $servers, $msg) {
        echo "shutdown started: $msg\n";
        foreach ($clients as $bot) {
            if (!$bot->isConnected)
                continue;
            try {
                $bot->sendNow("quit :$msg\r\n");
            } catch (Exception $e) {
                echo "Exception when sending quit\n $e\n";
            }
            $bot->exit();
        }
        foreach ($servers as $server)
            $server->stop();

        \Amp\delay(0.5); // maybe help with sending the quits
        echo "Stopped?\n";
        exit(0);
}

main();
EventLoop::run();