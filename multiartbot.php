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

require_once 'multiartsnotifier.php';

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
        if($cnt == 0)
            $bot->on('chat', 'onchat');
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

$recordings = [];

function record($bot, $nick, $chan, $text) {
    global $recordings, $config;
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

    $exists = false;
    $base = $config['artdir'];
    try {
        $tree = knivey\tools\dirtree($base);
    } catch (Exception $e) {
        echo "{$e}\n";
        return;
    }
    foreach($tree as $ent) {
        if($text == strtolower(basename($ent, '.txt'))) {
            $exists = str_replace($config['artdir'], '', $ent);
            break;
        }
    }
    if($exists)
        $bot->pm($chan, "Warning: That file name has been used by $exists, to playback may require a full path or you can @cancel and use a new name");

    $recordings[$nick] = [
        'name' => $text,
        'nick' => $nick,
        'chan' => $chan,
        'art' => [],
        'timeOut' => Amp\Loop::delay(15000, 'timeOut', [$nick, $bot]),
    ];
    $bot->pm($chan, 'Recording started type @end when done or discard with @cancel');
}

function tryRec($bot, $nick, $chan, $text) {
    global $recordings;
    if(!isset($recordings[$nick]))
        return;
    Amp\Loop::cancel($recordings[$nick]['timeOut']);
    $recordings[$nick]['timeOut'] = Amp\Loop::delay(15000, 'timeOut', [$nick, $bot]);
    $recordings[$nick]['art'][] = $text;
}

function timeOut($watcher, $data) {
    global $recordings;
    list ($nick, $bot) = $data;
    if(!isset($recordings[$nick])) {
        echo "Timeout called but not recording?\n";
        return;
    }
    $bot->pm($recordings[$nick]['chan'], "Canceling art for $nick due to no messages for 15 seconds");
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

function reqart($bot, $chan, $file) {
    global $config, $playing;
    if(isset($playing[$chan])) {
        return;
    }
    $base = $config['artdir'];
    try {
        $tree = knivey\tools\dirtree($base);
    } catch (Exception $e) {
        echo "{$e}\n";
        return;
    }
    //try fullpath first
    foreach($tree as $ent) {
        if ($file . '.txt' == strtolower(str_replace($config['artdir'], '', $ent))) {
            playart($bot, $chan, $ent);
            return;
        }
    }
    foreach($tree as $ent) {
        if($file == strtolower(basename($ent, '.txt'))) {
            playart($bot, $chan, $ent);
            return;
        }
    }
    try {
        $client = HttpClientBuilder::buildDefault();
        $url = "https://irc.watch/ascii/$file/";
        $req = new Request("https://irc.watch/ascii/txt/$file.txt");
        /** @var Response $response */
        $response = yield $client->request($req);
        $body = yield $response->getBody()->buffer();
        if ($response->getStatus() == 200) {
            file_put_contents("ircwatch.txt", "$body\n$url");
            playart($bot, $chan, "ircwatch.txt");
            return;
        }
    } catch (Exception $error) {
        // If something goes wrong Amp will throw the exception where the promise was yielded.
        // The HttpClient::request() method itself will never throw directly, but returns a promise.
        echo "$error\n";
        //$bot->pm($chan, "LinkTitles Exception: " . $error);
    }
    //$bot->pm($chan, "that art not found");
}

function searchart($bot, $chan, $file) {
    global $config, $playing;
    if(isset($playing[$chan])) {
        return;
    }
    $base = $config['artdir'];
    try {
        $tree = knivey\tools\dirtree($base);
    } catch (Exception $e) {
        echo "{$e}\n";
        return;
    }
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
    $out = [];
    if(!empty($matches)) {
        $cnt = 0;
        foreach ($matches as $match) {
            $out[] = str_replace($config['artdir'], '', $match);
            if ($cnt++ > 50) {
                $out[] = count($matches) . " total matches only showing 50";
                break;
            }
        }
        pumpToChan($chan, $out);
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
    try {
        $tree = knivey\tools\dirtree($base);
    } catch (Exception $e) {
        echo "{$e}\n";
        return;
    }
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
        playart($bot, $chan, $matches[array_rand($matches)]);
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

function playart($bot, $chan, $file)
{
    global $playing, $config;
    if (!isset($playing[$chan])) {
        $playing[$chan] = new Playing();
        $playing[$chan]->data = irctools\loadartfile($file);
        array_unshift($playing[$chan]->data, "Playing " . str_replace($config['artdir'], '', $file));
    }
    startPump($chan);
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
        if (count($playing[$chan]->data) > 100) {
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