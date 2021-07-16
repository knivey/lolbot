#!/usr/bin/env php
<?php
// Another bot just used for playing ascii arts
/*
 * Experimenting with pumping from several bots at the same time on efnet
 * for faster pumps of moderate size arts
 */

require_once __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

set_include_path(implode(PATH_SEPARATOR, array(__DIR__ . '/library', __DIR__ . '/plugins', get_include_path())));

spl_autoload_register(function ($class) {
    $path = str_replace('\\', '/', $class) . '.php';
    include $path;
    return class_exists($class, false);
});

use Amp\Loop;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use knivey\irctools;
use const Irc\ERR_CANNOTSENDTOCHAN;
use \Ayesh\CaseInsensitiveArray\Strict as CIArray;
$playing = new CIArray();

$config = Yaml::parseFile(__DIR__ . '/multiartconfig.yaml');

// Workaround with CIArray to have php pass reference
class Playing {
    public array $data = [];
}
use knivey\cmdr\Cmdr;

$router = new Cmdr();
require_once 'multiartsnotifier.php';
require_once 'artbot_scripts/art-common.php';
require_once 'artbot_scripts/quotes.php';
require_once 'artbot_scripts/urlimg.php';
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

function onchat($args, \Irc\Client $bot)
{
    global $config, $router;

    tryRec($bot, $args->from, $args->channel, $args->text);
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

    //TODO SOME ART NAMES HAVE SPACES
    $text = explode(' ', $text);
    $cmd = strtolower(array_shift($text));
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
        var_dump($text);
        $opts = parseOpts($text, ['--flip', '--edit', '--asciibird']);
        var_dump($opts);
        reqart($bot, $args->channel, $cmd, $opts);
    }
}

/** @var \Irc\Client[] $bots */
$bots = [];

Loop::run(function () {
    global $bots, $config;
    var_dump($config);

    $cnt = 0;
    foreach ($config['bots'] as $bcfg) {
        $bot = new \Irc\Client($bcfg['name'], $bcfg['server'], $bcfg['port'], $bcfg['bindIp'], $bcfg['ssl']);
        $bots[] = $bot;
        $bot->setThrottle($bcfg['throttle'] ?? true);
        $bot->setServerPassword($bcfg['pass'] ?? '');

        //all bots have same set of chans
        $bot->on('welcome', function ($e, \Irc\Client $bot) {
            global $config;
            $bot->join(implode(',', $config['channels']));
        });

        $bot->on('kick', function ($args, \Irc\Client $bot) {
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
            /***** Init scripts with hooks ******
             * definately will do this in a better way later via registering or whatever
             */
            if (function_exists("initQuotes"))
                initQuotes($bot);

            $bot->on('chat', 'onchat');
        }
        $cnt++;
    }
    $server = yield from multinotifier();

    $botExit = function ($watcherId) use ($server) {
        global $bots;
        Amp\Loop::cancel($watcherId);
        echo "Caught SIGINT! exiting ...\n";
        $promises = [];
        foreach ($bots as $bot) {
            $promises[] = $bot->sendNow("quit :Going for a smoke break\r\n");
        }
        try {
            yield \Amp\Promise\some($promises);
        } catch (Exception $e) {
            echo "Exception when sending quit\n $e\n";
        }
        foreach ($bots as $bot) {
            $bot->exit();
        }
        if ($server != null) {
            $server->stop();
        }
        echo "Stopping Amp\\Loop\n";
        Amp\Loop::stop();
    };

    Loop::onSignal(SIGINT, $botExit);
    Loop::onSignal(SIGTERM, $botExit);

    foreach ($bots as $bot) {
        $bot->go();
    }

});

function selectBot($chan) : \Irc\Client | false {
    global $bots;
    static $current = 0;
    $tries = 0;
    $i = $current;
    while($tries <= count($bots)) {
        $i++;
        if ($i == count($bots))
            $i = 0;
        if ($bots[$i]->onChannel($chan)) {
            $current = $i;
            return $bots[$i];
        }
        $tries++;
    }
    return false;
}

function botsOnChan($chan)
{
    global $bots;
    $cnt = 0;
    foreach ($bots as $bot) {
        if ($bot->onChannel($chan))
            $cnt++;
    }
    return $cnt;
}

function pumpToChan(string $chan, array $data) {
    global $playing;
    if(isset($playing[$chan])) {
        array_push($playing[$chan]->data, ...$data);
    } else {
        $playing[$chan] = new Playing();
        $playing[$chan]->data = $data;
        var_dump($playing);
        startPump($chan);
    }
}

function startPump($chan) {
    \Amp\asyncCall(function() use($chan) {
        global $playing;
        if(!isset($playing[$chan])) {
            echo "startPump but chan not in array?\n";
            return;
        }
        //we cant send empty lines
        $playing[$chan]->data = array_filter($playing[$chan]->data);
        if (count($playing[$chan]->data) > 6000) {
            $playing[$chan]->data = [$playing[$chan]->data[0], "that arts too big for this network"];
        }
        $bot = null;
        $nextbot = null;
        while (!empty($playing[$chan]->data)) {
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
                if (($nextbot = selectBot($chan)) === false) {
                    unset($playing[$chan]);
                    echo "Stopping pump to $chan, not enough bots left on it\n";
                    return;
                }
            } else {
                if ($nextbot != null) {
                    $bot = $nextbot;
                    if (($nextbot = selectBot($chan)) === false) {
                        unset($playing[$chan]);
                        echo "Stopping pump to $chan, not enough bots left on it\n";
                        return;
                    }
                }
            }
            $eventIdx = null;
            $def = new \Amp\Deferred();
            $botNick = $bot->getNick();
            $sendAmount = 4;
            if(count($playing[$chan]->data) < $sendAmount)
                $sendAmount = count($playing[$chan]->data);
            $cnt = 0;
            $nextbot->on('chat', function($args, $bot) use ($chan, &$eventIdx, &$def, &$cnt, $botNick, $sendAmount) {
                if ($args->from != $botNick)
                    return;
                if($args->chan != $chan)
                    return;
                $cnt++;
                if($cnt == $sendAmount) {
                    $bot->off('chat', null, $eventIdx);
                    $def->resolve();
                }
            }, $eventIdx);

            foreach (range(0,$sendAmount - 1) as $x) {
                if(isset($playing[$chan]) && !empty($playing[$chan]->data)) {
                    $line = array_shift($playing[$chan]->data);
                    $bot->pm($chan, irctools\fixColors($line));
                    yield \Amp\delay(100 / $botson);
                }
            }
            try {
                yield \Amp\Promise\timeout($def->promise(), 2000);
            } catch (\Amp\TimeoutException $e) {
                echo "Something horrible has happened, timeout on looking for pump lines\n";
                unset($playing[$chan]);
                $nextbot->off('chat', null, $eventIdx);
            }
        }
        unset($playing[$chan]);
    });
}