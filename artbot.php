<?php
// Another bot just used for playing ascii arts


require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/library/helpers.php';

use Symfony\Component\Yaml\Yaml;

set_include_path(implode(PATH_SEPARATOR, array(__DIR__ . '/library', __DIR__ . '/plugins', get_include_path())));

spl_autoload_register(function ($class) {
    $path = str_replace('\\', '/', $class) . '.php';
    include $path;
    return class_exists($class, false);
});

use Amp\Loop;


$config = Yaml::parseFile(__DIR__ . '/artconfig.yaml');


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

    $bot->on('chat', function ($args, \Irc\Client $bot) {
        global $config, $router;

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


        $text = explode(' ', $text);
        $cmd = array_shift($text);
        $text = implode(' ', $text);

        if(strtolower($cmd) == 'random') {
            randart($bot, $args->channel, $text);
            return;
        }
        if(strtolower($cmd) == 'stop') {
            stop($bot, $args->channel);
            return;
        }
        reqart($bot, $args->channel, $cmd);
    });

    Loop::onSignal(SIGINT, function ($watcherId) use ($bot) {
        echo "Caught SIGINT! exiting ...\n";
        yield from $bot->sendNow("quit :Caught SIGINT GOODBYE!!!!\r\n");
        $bot->exit();
        Amp\Loop::cancel($watcherId);
    });

    while (!$bot->exit) {
        yield from $bot->go();
    }
    if ($bot->exit) {
        echo "Stopping Amp\\Loop\n";
        Amp\Loop::stop();
        //exit();
        return;
    }
});

function dirtree($dir) {
    if(!is_dir($dir)) {
        return false;
    }
    if($dir[strlen($dir)-1] != '/') {
        $dir = "$dir/";
    }
    $tree = [];
    if ($dh = opendir($dir)) {
        while (($file = readdir($dh)) !== false) {
            $name = $dir . $file;
            $type = filetype($name);
            if($file == '.' || $file == '..') {
                continue;
            }
            if($type == 'dir') {
                foreach(dirtree($name . '/') as $ent) {
                    $tree[] = $ent;
                }
            }
            if($type == 'file') {
                $tree[] = $name;
            }
        }
        closedir($dh);
    }
    return $tree;
}

$playing = [];

function reqart($bot, $chan, $file) {
    global $config, $playing;
    if(isset($playing[$chan])) {
        return;
    }
    $base = $config['artdir'];
    if(!is_dir($base)) {
        echo "Incorrect artdir in config\n";
        return;
    }
    $tree = dirtree($base);
    foreach($tree as $ent) {
        if($file == basename($ent, '.txt')) {
            playart(null, [$bot, $chan, $ent]);
            return;
        }
    }
    $bot->pm($chan, "that art not found");
}

function randart($bot, $chan, $file) {
    global $config, $playing;
    if(isset($playing[$chan])) {
        return;
    }
    $base = $config['artdir'];
    if(!is_dir($base)) {
        echo "Incorrect artdir in config\n";
        return;
    }
    $tree = dirtree($base);
    $matches = $tree;
    if($file != '') {
        $matches = [];
        foreach ($tree as $ent) {
            $check = str_replace($config['artdir'], '', $ent);
            $check = str_replace('.txt', '', $check);
            if (fnmatch("*$file*", $check)) {
                $matches[] = $ent;
            }
        }
    }
    if(!empty($matches))
        playart(null, [$bot, $chan, $matches[array_rand($matches)]]);
    else
        $bot->pm($chan, "no matching art found");
}

function stop($bot, $chan) {
    global $playing;
    if(isset($playing[$chan])) {
        $playing[$chan] = [];
        $bot->pm($chan, 'stopped');
    } else {
        $bot->pm($chan, 'not playing');
    }
}

/**
 * needed because some stupid art files are UTF-16LE
 */
function loadfile($file) {
    $cont = file_get_contents($file);
    if(!mb_check_encoding($cont, "UTF-8")) {
        //UTF-16LE is best bet then fallback to the auto
        if(mb_check_encoding($cont, "UTF-16LE")) {
            $cont = mb_convert_encoding($cont, "UTF-8", "UTF-16LE");
        } else {
            $cont = mb_convert_encoding($cont, "UTF-8");
        }
    }
    $cont = str_replace("\r", "\n", $cont);
    return array_filter(explode("\n", $cont));
}

function playart($watcherId, $data) {
    list($bot, $chan, $file) = $data;
    global $playing, $config;

    if(!isset($playing[$chan])) {
        $playing[$chan] = loadfile($file);
        array_unshift($playing[$chan], "Playing " . str_replace($config['artdir'], '', $file));
    }
    if(empty($playing[$chan])) {
        unset($playing[$chan]);
        return;
    }
    $bot->pm($chan, fixColors(array_shift($playing[$chan])));
    \Amp\Loop::delay(30, 'playart', [$bot, $chan, $file]);
}
