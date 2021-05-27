<?php
use Amp\Loop;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use knivey\irctools;

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
            $exists = substr($ent, strlen($config['artdir']));
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
    $bot->pm($recordings[$nick]['chan'], "Recording finished ;) saved to " . substr($file, strlen($config['artdir'])));
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
        if ($file . '.txt' == strtolower(substr($ent, strlen($config['artdir'])))) {
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

function searchIrcwatch($file) {
    $file = strtolower($file);
    return \Amp\call(function () use ($file){
        try {
            $client = HttpClientBuilder::buildDefault();
            $req = new Request("https://irc.watch/js/ascii-index.js");
            /** @var Response $response */
            $response = yield $client->request($req);
            $body = yield $response->getBody()->buffer();
            if ($response->getStatus() == 200) {
                $body = substr(substr($body, strlen("var ascii_list=")), 0, -1);
                $index = json_decode($body,1);
                file_put_contents("ascii-index.js", $body);
            } else {
                if (file_exists("ascii-index.js"))
                    $index = json_decode(file_get_contents("ascii-index.js"), 1);
                else
                    return [];
            }
        } catch (Exception $error) {
            echo "$error\n";
        }
        $out = [];
        foreach($index as $check) {
            if (fnmatch("*$file*", strtolower($check))) {
                $out[$check] = $check;
            }
        }
        return $out;
    });
}

function searchart($bot, $chan, $file) {
    \Amp\asyncCall(function () use ($bot, $chan, $file) {
        global $config, $playing;
        if (isset($playing[$chan])) {
            return;
        }
        $file = strtolower($file);
        $base = $config['artdir'];
        try {
            $tree = knivey\tools\dirtree($base);
        } catch (Exception $e) {
            echo "{$e}\n";
            return;
        }
        $matches = $tree;
        $ircwatch = yield searchIrcwatch($file);
        if ($file != '') {
            $matches = [];
            foreach ($tree as $ent) {
                $check = substr($ent, strlen($config['artdir']));
                $check = str_replace('.txt', '', $check);
                if (fnmatch("*$file*", strtolower($check))) {
                    $matches[] = substr($ent, strlen($config['artdir']));
                    unset($ircwatch[basename($ent, '.txt')]);
                }
            }
        }
        foreach ($ircwatch as $iw) {
            $matches[] = "ircwatch/$iw";
        }
        $out = [];
        if (!empty($matches)) {
            $cnt = 0;
            foreach ($matches as $match) {
                $out[] = str_ireplace($file, "\x0306$file\x0F", $match);
                if ($cnt++ > 50) {
                    $out[] = count($matches) . " total matches only showing 50";
                    break;
                }
            }
            pumpToChan($chan, $out);
        } else
            $bot->pm($chan, "no matching art found");
    });
}

function randart($bot, $chan, $file) {
    global $config, $playing;
    if(isset($playing[$chan])) {
        return;
    }
    $file = strtolower($file);
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
            $check = substr($ent, strlen($config['artdir']));
            $check = str_replace('.txt', '', $check);
            if (fnmatch("*$file*", strtolower($check))) {
                $matches[] = $ent;
            }
        }
    }
    if(!empty($matches))
        playart($bot, $chan, $matches[array_rand($matches)], $file);
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

function playart($bot, $chan, $file, $searched = false)
{
    global $playing, $config;

    if (!isset($playing[$chan])) {
        $pump = irctools\loadartfile($file);
        if($file != "ircwatch.txt")
            $pmsg = "Playing " . substr($file, strlen($config['artdir']));
        else
            $pmsg = "Playing from ircwatch";
        if($searched) {
            $pmsg = str_ireplace($searched, "\x0306$searched\x0F", $pmsg);
        }
        array_unshift($pump, $pmsg);
        pumpToChan($chan, $pump);
    }
}

