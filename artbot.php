<?php
// Another bot just used for playing ascii arts


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

$config = Yaml::parseFile(__DIR__ . '/artconfig.yaml');
require_once 'art-common.php';


$bot = null;
Loop::run(function () {
    global $bot, $config;

    $bot = new \Irc\Client($config['name'], $config['server'], $config['port'], $config['bindIp'], $config['ssl']);
    $bot->setThrottle($config['throttle'] ?? true);
    $bot->setServerPassword($config['pass'] ?? '');

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
        echo "====== mode ======\n";
        var_dump($args->args);
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
        global $config, $router;

        if(isIgnored($args->fullhost))
            return;

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

        if($cmd == 'search' || $cmd == 'find') {
            searchart($bot, $args->channel, $text);
            return;
        }
        if($cmd == 'random') {
            randart($bot, $args->channel, $text);
            return;
        }
        if($cmd == 'stop') {
            stop($bot, $args->from, $args->channel, $text);
            return;
        }
        if($cmd == 'record') {
            record($bot, $args->from, $args->channel, $text);
            return;
        }
        if($cmd == 'end') {
            endart($bot, $args->from, $args->channel, $text);
            return;
        }
        if($cmd == 'cancel') {
            cancel($bot, $args->from, $args->channel, $text);
            return;
        }
        if(trim($cmd) == '')
            return;
        Amp\asyncCall('reqart', $bot, $args->channel, $cmd);
    });

    Loop::onSignal(SIGINT, function ($watcherId) use ($bot) {
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
        echo "Stopping Amp\\Loop\n";
        Amp\Loop::stop();
    });
    Loop::onSignal(SIGTERM, function ($watcherId) use ($bot) {
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
        echo "Stopping Amp\\Loop\n";
        Amp\Loop::stop();
    });

    $bot->go();
});

$playing = [];

function pumpToChan(string $chan, array $data) {
    \Amp\asyncCall(function () use ($chan, $data) {
        global $playing, $bot;
        if (isset($playing[$chan])) {
            array_push($playing[$chan], ...$data);
        } else {
            while (!empty($playing[$chan])) {
                $bot->pm($chan, irctools\fixColors(array_shift($playing[$chan])));
                yield \Amp\delay(25);
            }
            unset($playing[$chan]);
        }
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