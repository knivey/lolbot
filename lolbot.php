#!/usr/bin/env php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Amp\ByteStream\ResourceOutputStream;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use monolog\Logger;
use Symfony\Component\Yaml\Yaml;
use Amp\Loop;
use knivey\cmdr\Cmdr;
use Crell\Tukio\Dispatcher;
use Crell\Tukio\OrderedListenerProvider;


$router = new Cmdr();

$logHandler = new StreamHandler(new ResourceOutputStream(\STDOUT));
$logHandler->setFormatter(new ConsoleFormatter);
$logHandler->setLevel(\Psr\Log\LogLevel::INFO);

$eventLogger = new Logger("Events");
$eventProvider = new OrderedListenerProvider();
$eventDispatcher = new Dispatcher($eventProvider, $eventLogger);


if(isset($argv[1])) {
    if(!file_exists($argv[1]) || !is_file($argv[1]))
        die("Usage: ".__FILE__." [config.yaml]\n  ({$argv[1]} does not exist or is not a file)\n");
    $configFile = $argv[1];
} else {
    $configFile = __DIR__."/config.yaml";
}

$config = Yaml::parseFile($configFile);
if(!is_array($config))
    die("bad config file");

if($config['codesand'] ?? false) {
    require_once 'scripts/codesand/common.php';
}

/**
 * helper to make replies in cmds easier
 * @param object $args
 * @param \Irc\Client $bot
 * @param string $prefix
 * @return array<Closure(string,string)>
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

require_once 'scripts/notifier/notifier.php';

require_once 'scripts/weather/weather.php';
require_once 'scripts/bing/bing.php';
require_once 'scripts/stocks/stocks.php';
require_once 'scripts/wolfram/wolfram.php';
require_once 'scripts/lastfm/lastfm.php';
require_once 'scripts/help/help.php';
require_once 'scripts/tools/tools.php';
require_once 'scripts/tell/tell.php';
require_once 'scripts/remindme/remindme.php';
require_once 'scripts/owncast/owncast.php';
require_once 'scripts/urbandict/urbandict.php';
require_once 'scripts/seen/seen.php';
require_once 'scripts/zyzz/zyzz.php';
require_once 'scripts/wiki/wiki.php';
require_once 'scripts/alias/alias.php';
require_once 'scripts/markov_quotes/markov_quotes.php';
require_once 'scripts/insult/insult.php';
require_once "scripts/JRH/jrh.php";
require_once "scripts/mal/mal.php";

require_once 'scripts/linktitles/linktitles.php';
require_once 'scripts/youtube/youtube.php';
require_once 'scripts/twitter/twitter.php';
require_once 'scripts/github/github.php';
require_once 'scripts/reddit/reddit.php';
require_once 'scripts/durendaltv/durendaltv.php';
require_once 'scripts/bomb_game/bomb_game.php';
require_once 'scripts/translate/translate.php';

#[\knivey\cmdr\attributes\PrivCmd("dumpnicks")]
function dumpnicks($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    global $nicks;
    print_r($nicks->ppl);
}

$router->loadFuncs();

//copied from Cmdr should give it its own function in there later
function parseOpts(string &$msg, array $validOpts = []): array {
    $opts = [];
    $msg = explode(' ', $msg);
    $msgb = [];
    foreach ($msg as $w) {
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
$bot = null;
$nicks = null;
try {
    Loop::run(function () {
        global $bot, $config, $logHandler, $nicks, $router;

        $log = new Logger($config['name']);
        $log->pushHandler($logHandler);
        $bot = new \Irc\Client($config['name'], $config['server'], $log, $config['port'], ($config['bindIp'] ?? '0'), $config['ssl']);
        $bot->setThrottle($config['throttle'] ?? true);
        $bot->setServerPassword($config['pass'] ?? '');
        \scripts\tell\initTell($bot);
        \scripts\seen\initSeen($bot);
        \scripts\remindme\initRemindme($bot);
        $bomb_game = new \scripts\bomb_game\bomb_game();
        $router->loadMethods($bomb_game);

        $nicks = new Nicks($bot);
        $bot->on('welcome', function ($e, \Irc\Client $bot) {
            global $config;
            $nick = $bot->getNick();
            $bot->send("MODE $nick +x");
            $bot->join(implode(',', $config['channels']));
        });

        $bot->on('kick', function ($args, \Irc\Client $bot) {
            $bot->join($args->channel);
        });

        //Stop abuse from an IRCOP called sylar
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

        $bot->on('chat', function ($args, \Irc\Client $bot) {
            try {
                global $config, $router;

                if(isIgnored($args->fullhost))
                    return;

                if ($config['linktitles'] ?? false) {
                    \Amp\asyncCall(scripts\linktitles\linktitles(...), $bot, $args->nick, $args->channel, $args->identhost, $args->text);
                }

                if (isset($config['trigger'])) {
                    if (substr($args->text, 0, 1) != $config['trigger']) {
                        return;
                    }
                    $text = substr($args->text, 1);
                } elseif (isset($config['trigger_re'])) {
                    $trig = "/(^${config['trigger_re']}).+$/";
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


                $text = explode(' ', $text);
                $cmd = array_shift($text);
                $text = implode(' ', $text);
                if(trim($cmd) == '')
                    return;

                if(isset($router->cmds[$cmd])) {
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
                    if(!is_array($cmdArgs))
                        $cmdArgs = [];
                    if(count($cmdArgs) == 1 && $cmdArgs[0] == "")
                        $cmdArgs = [];
                    \scripts\alias\handleCmd($args, $bot, $cmd, $cmdArgs);
                }
            } catch (Exception $e) {
                echo "UNCAUGHT EXCEPTION $e\n";
            }
        });
        $bot->on('pm', function ($args, \Irc\Client $bot) {
            global $router;
            $text = explode(' ', $args->text);
            $cmd = array_shift($text);
            $text = implode(' ', $text);
            if(trim($cmd) == '')
                return;

            try {
                $router->callPriv($cmd, $text, $args, $bot);
            } catch (Exception $e) {
                $bot->notice($args->from, $e->getMessage());
            }
        });
        $server = yield from \scripts\notifier\notifier($bot);

        Loop::onSignal(SIGINT, function ($watcherId) use ($bot, $server) {
            Amp\Loop::cancel($watcherId);
            if (!$bot->isConnected)
                die("Terminating, not connected\n");
            echo "Caught SIGINT! exiting ...\n";
            try {
                yield $bot->sendNow("quit :Caught SIGTERM GOODBYE!!!!\r\n");
            } catch (Exception $e) {
                echo "Exception when sending quit\n $e\n";
            }
            $bot->exit();
            if ($server != null) {
                $server->stop();
            }
            echo "Stopping Amp\\Loop\n";
            Amp\Loop::stop();
        });

        Loop::onSignal(SIGTERM, function ($watcherId) use ($bot, $server) {
            Amp\Loop::cancel($watcherId);
            if (!$bot->isConnected)
                die("Terminating, not connected\n");
            echo "Caught SIGTERM! exiting ...\n";
            try {
                yield $bot->sendNow("quit :Caught SIGTERM GOODBYE!!!!\r\n");
            } catch (Exception $e) {
                echo "Exception when sending quit\n $e\n";
            }
            $bot->exit();
            if ($server != null) {
                $server->stop();
            }
            echo "Stopping Amp\\Loop\n";
            Amp\Loop::stop();
        });

        $bot->go();
    });
} catch (Exception $e) {
    echo "=================================================\n";
    echo "Exception throw from Loop::run exiting (I HOPE)..\n";
    echo "=================================================\n";
    echo $e . "\n";
    echo "=================================================\n";
    exit(1);
}

/*
 * will probably move this later to some kinda user auth thing
 */


function getUserAuthServ($nick, $bot): \Amp\Promise {
    return \Amp\call(function () use ($nick, $bot) {
        $idx = null;
        $auth = null;
        $success = false;
        $def = new \Amp\Deferred();
        $cb = function ($args, \Irc\Client $bot) use (&$idx, $nick, &$success, &$def) {
            if ($args->nick != 'AuthServ')
                return;
            if (preg_match("/Account information for \x02([^\x02]+)\x02:/", $args->text, $m)) {
                $bot->off('notice', null, $idx);
                $success = true;
                $def->resolve($m[1]);
            }
            $rnick = preg_quote($nick, '/');
            if (preg_match("/User with nick \x02{$rnick}\x02 does not exist\./", $args->text) ||
                preg_match("/{$rnick} must first authenticate with \x02AuthServ\x02\./", $args->text)
            ) {
                $bot->off('notice', null, $idx);
                $success = true;
                $def->resolve(null);
            }
        };
        $bot->on('notice', $cb, $idx);
        $bot->send("as info $nick");
        $auth = yield \Amp\Promise\timeout($def->promise(), 2000);
        if (!$success)
            $bot->off('notice', null, $idx);
        return $auth;
    });
}

function getUserChanAccess($nick, $chan, $bot): \Amp\Promise {
    return \Amp\call(function () use ($nick, $chan, $bot) {
        $idx = null;
        $auth = null;
        $success = false;
        $def = new \Amp\Deferred();
        $cb = function ($args, \Irc\Client $bot) use (&$idx, $nick, $chan, &$success, &$def) {
            if ($args->nick != 'ChanServ')
                return;
            $rnick = preg_quote($nick, '/');
            $rchan = preg_quote($chan, '/');
            if (preg_match("/{$rnick} [^ ]+ has access \x02([^\x02]+)\x02 in {$rchan}/", $args->text, $m)) {
                $bot->off('notice', null, $idx);
                $success = true;
                $def->resolve($m[1]);
            }
            /*
             * Won't recognize suspended users due to response being the following:
             * [ChanServ] knivey (kyte) has access 1 in #california.
             * [ChanServ] knivey's access to #california has been suspended.
             */
            if (preg_match("/User with nick \x02{$rnick}\x02 does not exist\./", $args->text) ||
                preg_match("/{$rnick} must first authenticate with \x02AuthServ\x02\./", $args->text) ||
                preg_match("/{$rnick} [^ ]+ lacks access to {$rchan}\./", $args->text) ||
                preg_match("/{$rchan} has not been registered with ChanServ./", $args->text)
            ) {
                $bot->off('notice', null, $idx);
                $success = true;
                $def->resolve(0);
            }
        };
        $bot->on('notice', $cb, $idx);
        $bot->send("cs $chan access $nick");
        $auth = yield \Amp\Promise\timeout($def->promise(), 2000);
        if (!$success)
            $bot->off('notice', null, $idx);
        return $auth;
    });
}

//TODO move this to irctools package
function hostmaskToRegex($mask) {
    $out = '';
    $i = 0;
    while($i < strlen($mask)) {
        $nextc = strcspn($mask, '*?', $i);
        $out .= preg_quote(substr($mask, $i, $nextc), '@');
        if($nextc + $i == strlen($mask))
            break;
        if($mask[$nextc + $i] == '?')
            $out .= '.';
        if($mask[$nextc + $i] == '*')
            $out .= '.*';
        $i += $nextc + 1;
    }
    return "@{$out}@i";
}

//TODO replace this with something that doesnt check mtime, probably use rpc to manage admin things off irc
function getIgnores($file = "ignores.txt") {
    static $ignores;
    static $mtime;
    if(!file_exists($file))
        return [];
    // Retarded that i had to figure out to do this otherwise php caches mtime..
    clearstatcache();
    $newmtime = filemtime($file);
    if($newmtime <= ($mtime ?? 0))
        return ($ignores ?? []);
    $mtime = $newmtime;
    return $ignores = file($file, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES);
}

function isIgnored($fullhost) {
    $ignores = getIgnores();
    foreach ($ignores as $i) {
        if (preg_match(hostmaskToRegex($i), $fullhost)) {
            return true;
        }
    }
    return false;
}
