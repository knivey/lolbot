#!/usr/bin/env php
<?php
// Another bot just used for playing ascii arts
/*
 * Experimenting with pumping from several bots at the same time on efnet
 * for faster pumps of moderate size arts
 */

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

$playing = [];

if(isset($argv[1])) {
    if(!file_exists($argv[1]) || !is_file($argv[1]))
        die("Usage: ".__FILE__." [config.yaml]\n  ({$argv[1]} does not exist or is not a file)\n");
    $configFile = $argv[1];
} else {
    $configFile = __DIR__."/multiartconfig.yaml";
}

$config = Yaml::parseFile($configFile);
if(!is_array($config))
    die("bad config file");

if(!isset($config['network_id']))
    die("config must have a network_id set\n");

use knivey\cmdr\Cmdr;

$router = new Cmdr();

$logHandler = new StreamHandler(ByteStream\getStdout());
$logHandler->setFormatter(new ConsoleFormatter);
$logHandler->setLevel(\Psr\Log\LogLevel::INFO);

require_once 'library/Nicks.php';
require_once 'artbot_rest_server.php';
require_once 'artbot_scripts/art-common.php';
require_once 'artbot_scripts/quotes.php';
require_once 'artbot_scripts/urlimg.php';
require_once 'artbot_scripts/drawing.php';
require_once 'artbot_scripts/bashorg.php';
require_once 'artbot_scripts/artfart.php';
require_once 'artbot_scripts/help.php';
$router->loadFuncs();

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

function canRun($args): bool
{
    global $nicks, $config;

    if (isset($config['artMinAccess'])) {
        if (!is_string($config['artMinAccess']) ||
            strlen($config['artMinAccess']) > 1 ||
            !str_contains('~&@%+', $config['artMinAccess'])
        ) {
            echo "artMinAccess configured incorrectly, must be one of ~&@%+\n";
            return false;
        }
        switch ($config['artMinAccess']) {
            case '~':
                return $nicks->isOwner($args->nick, $args->channel);
            case '&':
                return $nicks->isAdminOrHigher($args->nick, $args->channel);
            case '@':
                return $nicks->isOpOrHigher($args->nick, $args->channel);
            case '%':
                return $nicks->isHalfOpOrHigher($args->nick, $args->channel);
            case '+':
                return $nicks->isVoiceOrHigher($args->nick, $args->channel);
        }
    }
    return true;
}

use Symfony\Component\Cache\Adapter\ArrayAdapter;
global $ORMconfig;
$ignoreCache = new ArrayAdapter(defaultLifetime: 5, storeSerialized: false, maxLifetime: 10, maxItems: 100);

/**
 * @var Nicks|null $nicks
 */
$nicks = null;
function onchat($args, \Irc\Client $bot)
{
    async(function() use ($args, $bot) {
        global $config, $router, $reqArtOpts, $entityManager, $ignoreCache;

        $ignored = $ignoreCache->getItem($args->fullhost);
        if(!$ignored->isHit()) {
            $network = $entityManager->getRepository(Network::class)->find($config['network_id']);
            $ignoreRepository = $entityManager->getRepository(Ignore::class);
            if (count($ignoreRepository->findMatching($args->fullhost, $network)) > 0)
                $ignored->set(true);
            else
                $ignored->set(false);
            $ignoreCache->save($ignored);
        }
        if($ignored->get())
            return;

        if(!canRun($args))
            return;
        

        artbot_scripts\tryRec($bot, $args->from, $args->text);
        if (isset($config['trigger'])) {
            if (substr($args->text, 0, 1) != $config['trigger']) {
                return;
            }
            $text = substr($args->text, 1);
        } elseif (isset($config['trigger_re'])) {
            $trig = "/(^{$config['trigger_re']}).+$/";
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
        if($router->cmdExists($cmd)) {
            try {
                $router->call($cmd, $text, $args, $bot);
            } catch (Exception $e) {
                $bot->notice($args->from, $e->getMessage());
            }
        } else {
            var_dump($text);
            $opts = parseOpts($text, $reqArtOpts);
            var_dump($opts);
            $cmdArgs = \knivey\tools\makeArgs($text);
            if(!is_array($cmdArgs))
                $cmdArgs = [];
            artbot_scripts\reqart($bot, $args->channel, $cmd, $opts, $cmdArgs);
        }
    });
}

/** @var \Irc\Client[] $bots */
$bots = [];

function main() {
    global $clients, $config, $logHandler, $nicks;
    var_dump($config);

    $cnt = 0;
    foreach ($config['bots'] as $bcfg) {
        $log = new Logger($bcfg['name']);
        $log->pushHandler($logHandler);
        $bot = new \Irc\Client($bcfg['name'], $bcfg['server'], $log, $bcfg['port'], $bcfg['bindIp'], $bcfg['ssl']);
        $clients[] = $bot;
        $bot->setThrottle($bcfg['throttle'] ?? true);
        $bot->setServerPassword($bcfg['pass'] ?? '');
        if(isset($config['sasl_user']) && isset($config['sasl_pass'])) {
            $bot->setSasl($config['sasl_user'], $config['sasl_pass']);
        }

        EventLoop::repeat(10, function() use ($bot, $bcfg) {
            if(!$bot->isEstablished()) {
                return;
            }
            if($bot->isCurrentNick($bcfg['name'])) {
                return;
            }
            $bot->nick($bcfg['name']);
        });

        //all bots have same set of chans
        $bot->on('welcome', function ($e, \Irc\Client $bot) {
            global $config;
            if(isset($config['onconnect'])) {
                if(!is_array($config['onconnect'])) {
                    die("config['onconnect'] must be an array, found: " . get_debug_type($config['onconnect']));
                }
                foreach ($config["onconnect"] as $line) {
                    str_replace('$me', $bot->getNick(), $line);
                    $bot->send($line);
                }
            }
            $bot->join(implode(',', $config['channels']));
        });

        $bot->on('kick', function ($args, \Irc\Client $bot) {
            if($args->nick == $bot->getNick())
                $bot->join($args->channel);
        });

        $bot->on(ERR_CANNOTSENDTOCHAN, function ($args, \Irc\Client $bot) {
            global $playing;
            $chan = $args->message->getArg(1);
            //if recording i guess forget about it for now
            if(isset($playing[$chan])) {
                unset($playing[$chan]);
                echo "Stopping pump to $chan due to send error\n";
            }
        });

        //Only first bot handles seeing commands, recording arts, etc
        if($cnt == 0) {
            $nicks = new Nicks($bot);
            /***** Init scripts with hooks ******
             * definately will do this in a better way later via registering or whatever
             */
            if (function_exists("initQuotes"))
                initQuotes($bot);

            $bot->on('chat', onchat(...));
        }
        $cnt++;
    }
    $server = new artbot_rest_server($logHandler);
    $server->initRestServer();
    artbot_scripts\setupRestRoutes($server);
    $server->start();

    $botExit = function () use ($server) {
        global $clients;
        echo "Caught SIGINT! exiting ...\n";
        $futures = [];
        foreach ($clients as $bot) {
            $futures[] = async(fn() => $bot->sendNow("quit :Going for a smoke break\r\n"));
        }
        try {
            \Amp\Future\awaitAll($futures, new TimeoutCancellation(5));
        } catch (\Exception $e) {
            echo "Exception when sending quit\n $e\n";
        }
        foreach ($clients as $bot) {
            $bot->exit();
        }
        if ($server != null) {
            $server->stop();
        }
        echo "Stopping ...\n";
        Amp\delay(0.5);
        //var_dump(EventLoop::getIdentifiers());
        die("forcing exit\n");
    };

    EventLoop::onSignal(SIGINT, $botExit);
    EventLoop::onSignal(SIGTERM, $botExit);

    foreach ($clients as $bot) {
        $bot->go();
    }

}
main();
EventLoop::run();

function selectBot($chan): \Irc\Client|false {
    global $clients;
    static $current = 0;
    $tries = 0;
    $i = $current;
    while($tries <= count($clients)) {
        $i++;
        if ($i == count($clients))
            $i = 0;
        if ($clients[$i]->onChannel($chan)) {
            $current = $i;
            return $clients[$i];
        }
        $tries++;
    }
    return false;
}

function botsOnChan($chan)
{
    global $clients;
    $cnt = 0;
    foreach ($clients as $bot) {
        if ($bot->onChannel($chan))
            $cnt++;
    }
    return $cnt;
}

function pumpToChan(string $chan, array $data, $speed = null) {
    global $playing;
    $chan = strtolower($chan);
    if(isset($playing[$chan])) {
        array_push($playing[$chan], ...$data);
    } else {
        $playing[$chan] = $data;
        startPump($chan, $speed);
    }
}

function startPump($chan, $speed = null): Future {
    return async(function() use($chan, $speed) {
        global $playing;
        $chan = strtolower($chan);
        if(!isset($playing[$chan])) {
            echo "startPump but chan not in array?\n";
            return;
        }
        //we cant send empty lines
        $playing[$chan] = array_filter($playing[$chan]);
        if (count($playing[$chan]) > 9001) {
            $playing[$chan] = [$playing[$chan][0], "that arts too big for this network"];
        }
        $bot = null;
        $nextbot = null;
        while (!empty($playing[$chan])) {
            $botson = botsOnChan($chan);
            if($botson < 2) {
                unset($playing[$chan]);
                echo "Stopping pump to $chan, not enough bots left on it\n";
                return;
            }
            //this could probably be cleaned up lol
            if($bot == null) {
                if (($bot = selectBot($chan)) === false) {
                    unset($playing[$chan]);
                    echo "Stopping pump to $chan, no bots left on it\n";
                    return;
                }
                // @phpstan-ignore identical.alwaysFalse
                if (($nextbot = selectBot($chan)) === false) {
                    unset($playing[$chan]);
                    echo "Stopping pump to $chan, not enough bots left on it\n";
                    return;
                }
            } else {
                if ($nextbot != null) {
                    $bot = $nextbot;
                    // @phpstan-ignore identical.alwaysFalse
                    if (($nextbot = selectBot($chan)) === false) {
                        unset($playing[$chan]);
                        echo "Stopping pump to $chan, not enough bots left on it\n";
                        return;
                    }
                }
            }
            $eventIdx = null;
            $def = new \Amp\DeferredFuture();
            $botNick = $bot->getNick();
            $sendAmount = 4;
            if(count($playing[$chan]) < $sendAmount)
                $sendAmount = count($playing[$chan]);
            $cnt = 0;
            $nextbot->on('chat', function($args, $bot) use ($chan, &$eventIdx, &$def, &$cnt, $botNick, $sendAmount) {
                if ($args->from != $botNick)
                    return;
                if(strtolower($args->chan) != $chan)
                    return;
                $cnt++;
                if($cnt == $sendAmount) {
                    $bot->off('chat', null, $eventIdx);
                    $def->complete();
                }
            }, $eventIdx);

            foreach (range(0,$sendAmount - 1) as $x) {
                if(isset($playing[$chan]) && !empty($playing[$chan])) {
                    $line = array_shift($playing[$chan]);
                    $bot->pm($chan, irctools\fixColors($line));
                    $delay = 550 / $botson;
                    if($delay < 85)
                        $delay = 85;
                    if($speed) {
                        $delay = max($delay, $speed);
                    }
                    \Amp\delay($delay/1000);
                }
            }
            try {
                $def->getFuture()->await(new \Amp\TimeoutCancellation(8));
            } catch (\Amp\TimeoutException $e) {
                echo "Something horrible has happened, timeout on looking for pump lines\n";
                unset($playing[$chan]);
                $nextbot->off('chat', null, $eventIdx);
            }
        }
        unset($playing[$chan]);
    });
}