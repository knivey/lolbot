#!/usr/bin/env php
<?php
// Another bot just used for playing ascii arts


require_once __DIR__ . '/vendor/autoload.php';

use JetBrains\PhpStorm\Pure;
use Symfony\Component\Yaml\Yaml;

use Amp\ByteStream\ResourceOutputStream;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use monolog\Logger;

use Amp\Loop;
use knivey\irctools;
use knivey\cmdr\Cmdr;

$router = new Cmdr();

$logHandler = new StreamHandler(new ResourceOutputStream(\STDOUT));
$logHandler->setFormatter(new ConsoleFormatter);
$logHandler->setLevel(\Psr\Log\LogLevel::INFO);

if(isset($argv[1])) {
    if(!file_exists($argv[1]) || !is_file($argv[1]))
        die("Usage: ".__FILE__." [config.yaml]\n  ({$argv[1]} does not exist or is not a file)\n");
    $configFile = $argv[1];
} else {
    $configFile = __DIR__."/artconfig.yaml";
}

$config = Yaml::parseFile($configFile);
if(!is_array($config))
    die("bad config file");

require_once 'artbot_rest_server.php';
require_once 'artbot_scripts/art-common.php';
require_once 'artbot_scripts/quotes.php';
require_once 'artbot_scripts/urlimg.php';
require_once 'artbot_scripts/drawing.php';
require_once 'artbot_scripts/bashorg.php';
require_once 'artbot_scripts/artfart.php';
require_once 'scripts/help/help.php';
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

$bot = null;
Loop::run(function () {
    global $bot, $config, $logHandler;

    $log = new Logger($config['name']);
    $log->pushHandler($logHandler);
    $bot = new \Irc\Client($config['name'], $config['server'], $log, $config['port'], $config['bindIp'], $config['ssl']);
    $bot->setThrottle($config['throttle'] ?? true);
    $bot->setServerPassword($config['pass'] ?? '');

    /***** Init scripts with hooks ******
     * definately will do this in a better way later via registering or whatever
     */
    if (function_exists("initQuotes"))
        initQuotes($bot);

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
        global $config, $router, $reqArtOpts;

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
        //Bescause we want to handle arguments to the arts later we might convert the spaces to _
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
            reqart($bot, $args->channel, $cmd, $opts, $cmdArgs);
        }
    });
    $server = yield from startRestServer();

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

$playing = [];

function pumpToChan(string $chan, array $data, $speed = null) {
    \Amp\asyncCall(function () use ($chan, $data, $speed) {
        global $playing, $bot, $config;
        if (isset($playing[$chan])) {
            array_push($playing[$chan], ...$data);
        } else {
            $playing[$chan] = $data;
            while (!empty($playing[$chan])) {
                $bot->pm($chan, irctools\fixColors(array_shift($playing[$chan])));
                $pumpLag = $config['pumplag'] ?? 25;
                if($speed)
                    $pumpLag = max($pumpLag, $speed);
                yield \Amp\delay($pumpLag);
            }
            unset($playing[$chan]);
        }
    });
}


//TODO move this to irctools package
#[Pure] function hostmaskToRegex($mask) {
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