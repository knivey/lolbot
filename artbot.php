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

$recordings = [];

function record($bot, $nick, $chan, $text) {
    global $recordings;
    if(isset($recordings[$nick])) {
        return;
    }
    if(!preg_match('/^[a-z0-9\-\_]+$/', $text)) {
        $bot->pm($chan, 'Pick a filename matching [a-z0-9\-\_]+');
        return;
    }
    $reserved = ['artfart', 'random', 'search', 'find', 'stop', 'record', 'end', 'cancel'];
    if(in_array(strtolower($text), $reserved)) {
        $bot->pm($chan, 'That name has been reserved');
        return;
    }
    $recordings[$nick] = [
        'name' => $text,
        'nick' => $nick,
        'chan' => $chan,
        'art' => [],
        'timeOut' => Amp\Loop::delay(10000, 'timeOut', [$nick, $bot]),
    ];
    $bot->pm($chan, 'Recording started');
}

function tryRec($bot, $nick, $chan, $text) {
    global $recordings;
    if(!isset($recordings[$nick]))
        return;
    Amp\Loop::cancel($recordings[$nick]['timeOut']);
    $recordings[$nick]['timeOut'] = Amp\Loop::delay(10000, 'timeOut', [$nick, $bot]);
    $recordings[$nick]['art'][] = $text;
}

function timeOut($watcher, $data) {
    global $recordings;
    list ($nick, $bot) = $data;
    if(!isset($recordings[$nick])) {
        echo "Timeout called but not recording?\n";
        return;
    }
    $bot->pm($recordings[$nick]['chan'], "Canceling art for $nick due to no messages for 10 seconds");
    Amp\Loop::cancel($recordings[$nick]['timeOut']);
    unset($recordings[$nick]);
}

function endart($bot, $nick, $chan, $text) {
    global $recordings, $config;
    if(!isset($recordings[$nick])) {
        $bot->pm($chan, "You aren't doing a recording");
        return;
    }
    Amp\Loop::cancel($recordings[$nick]['timeOut']);
    //last line will be the command for end, so delete it
    array_pop($recordings[$nick]['art']);
    if(empty($recordings[$nick]['art'])) {
        $bot->pm($recordings[$nick]['chan'], "Nothing recorded, cancelling");
        unset($recordings[$nick]);
        return;
    }
    //TODO make h4x channel name?
    $dir = "${config['artdir']}h4x/$nick";
    if(file_exists($dir) && !is_dir($dir)) {
        $bot->pm($recordings[$nick]['chan'], "crazy error occurred panicing atm");
        unset($recordings[$nick]);
        return;
    }
    if(!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
    $file = "$dir/". $recordings[$nick]['name'] . '.txt';
    file_put_contents($file, implode("\n", $recordings[$nick]['art']));
    $bot->pm($recordings[$nick]['chan'], "Recording finished ;) saved to " . str_replace($config['artdir'], '', $file));
    unset($recordings[$nick]);
}

function cancel($bot, $nick, $chan, $text) {
    global $recordings;
    if(!isset($recordings[$nick])) {
        $bot->pm($chan, "You aren't doing a recording");
        return;
    }
    $bot->pm($chan, "Recording canceled");
    Amp\Loop::cancel($recordings[$nick]['timeOut']);
    unset($recordings[$nick]);
}

function dirtree($dir, $ext = "txt") {
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
            if($type == 'dir' && $name[0] != '.') {
                foreach(dirtree($name . '/') as $ent) {
                    $tree[] = $ent;
                }
            }
            if($type == 'file' && $name[0] != '.' && 'txt' == strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
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
    //try fullpath first
    foreach($tree as $ent) {
        if ($file . '.txt' == strtolower(str_replace($config['artdir'], '', $ent))) {
            playart(null, [$bot, $chan, $ent]);
        }
    }
    foreach($tree as $ent) {
        if($file == strtolower(basename($ent, '.txt'))) {
            playart(null, [$bot, $chan, $ent]);
            return;
        }
    }
    $bot->pm($chan, "that art not found");
}

function searchart($bot, $chan, $file) {
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
            if (fnmatch("*$file*", strtolower($check))) {
                $matches[] = $ent;
            }
        }
    }
    if(!empty($matches)) {
        $cnt = 0;
        foreach ($matches as $match) {
            $bot->pm($chan, str_replace($config['artdir'], '', $match));
            if ($cnt++ > 100) {
                $bot->pm($chan, count($matches) . " total matches only showing 100");
                break;
            }
        }
    }
    else
        $bot->pm($chan, "no matching art found");
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
            if (fnmatch("*$file*", strtolower($check))) {
                $matches[] = $ent;
            }
        }
    }
    if(!empty($matches))
        playart(null, [$bot, $chan, $matches[array_rand($matches)]]);
    else
        $bot->pm($chan, "no matching art found");
}

function stop($bot, $nick, $chan, $text) {
    global $recordings, $playing;
    if(isset($playing[$chan])) {
        $playing[$chan] = [];
        $bot->pm($chan, 'stopped');
    } else {
        if(isset($recordings[$nick])) {
            endart($bot, $nick, $chan, $text);
            return;
        }
        $bot->pm($chan, 'not playing');
    }
}

/**
 * needed because some stupid art files are UTF-16LE
 */
function loadfile($file) {
    $cont = file_get_contents($file);
    //php apparently sucked at its detection so just checking this manually
    if($cont[0] == "\xFF" && $cont[1] == "\xFE") {
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
