<?php
require_once 'library/async_get_contents.php';

use Amp\Loop;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Options;
use knivey\cmdr\attributes\Syntax;
use knivey\irctools;

$recordings = [];

#[Cmd("record")]
#[Syntax('<filename>')]
function record($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    $nick = $args->nick;
    $chan = $args->chan;
    $file = $req->args['filename'];
    global $recordings, $config;
    if(isset($recordings[$nick])) {
        return;
    }
    if(!preg_match('/^[a-z0-9\-\_]+$/', $file)) {
        $bot->pm($chan, 'Pick a filename matching [a-z0-9\-\_]+');
        return;
    }
    $reserved = ['artfart', 'random', 'search', 'find', 'stop', 'record', 'end', 'cancel'];
    if(in_array(strtolower($file), $reserved)) {
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
        if($file == strtolower(basename($ent, '.txt'))) {
            $exists = substr($ent, strlen($config['artdir']));
            break;
        }
    }
    if($exists)
        $bot->pm($chan, "Warning: That file name has been used by $exists, to playback may require a full path or you can @cancel and use a new name");

    $recordings[$nick] = [
        'name' => $file,
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

#[Cmd("end")]
function endart($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    $nick = $args->nick;
    $chan = $args->chan;
//function endart($bot, $nick, $chan, $text) {
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

#[Cmd("cancel")]
function cancel($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    $nick = $args->nick;
    $chan = $args->chan;
    global $recordings;
    if(!isset($recordings[$nick])) {
        $bot->pm($chan, "You aren't doing a recording");
        return;
    }
    $bot->pm($chan, "Recording canceled");
    Amp\Loop::cancel($recordings[$nick]['timeOut']);
    unset($recordings[$nick]);
}

function reqart($bot, $chan, $file, $opts = []) {
    \Amp\asyncCall(function() use ($bot, $chan, $file, $opts) {
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
        if(array_key_exists('--edit', $opts) || array_key_exists('--asciibird', $opts)) {
            $matches = yield searchIrcwatch($file, true);
            if(count($matches) == 0) {
                $bot->pm($chan, "that art isnt available on irc.watch and cant be loaded to asciibird :(");
                return;
            }
            $bot->pm($chan, "https://asciibird.jewbird.live/?ircwatch=$file.txt");
            return;
        }
        //try fullpath first
        //TODO match last part of paths ex terps/artfile matches h4x/terps/artfile
        foreach($tree as $ent) {
            if ($file . '.txt' == strtolower(substr($ent, strlen($config['artdir'])))) {
                playart($bot, $chan, $ent, opts: $opts);
                return;
            }
        }
        foreach($tree as $ent) {
            if($file == strtolower(basename($ent, '.txt'))) {
                playart($bot, $chan, $ent, opts: $opts);
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
                playart($bot, $chan, "ircwatch.txt", opts: $opts);
                return;
            }
        } catch (Exception $error) {
            // If something goes wrong Amp will throw the exception where the promise was yielded.
            // The HttpClient::request() method itself will never throw directly, but returns a promise.
            echo "$error\n";
            //$bot->pm($chan, "LinkTitles Exception: " . $error);
        }
        //$bot->pm($chan, "that art not found");
    });
}

function searchIrcwatch($file, $noglob = false) {
    $file = strtolower($file);
    return \Amp\call(function () use ($file, $noglob) {
        try {
            $client = HttpClientBuilder::buildDefault();
            $req = new Request("https://irc.watch/js/ascii-index.js");
            /** @var Response $response */
            $response = yield $client->request($req);
            $body = yield $response->getBody()->buffer();
            if ($response->getStatus() == 200) {
                if (preg_match("@^(var\s?ascii_list\s?=\s?)@i", $body, $m)) {
                    $body = substr(substr($body, strlen($m[1])), 0, -1);
                    $index = json_decode($body, 1);
                    if (!is_array($index)) {
                        echo "irc.watch ascii-index.js bad contents (not array)\n";
                        goto fileload;
                    } else {
                        file_put_contents("ascii-index.js", $body);
                        echo "irc.watch ascii-index.js recieved with " . count($index) . " files\n";
                    }
                } else {
                    echo "irc.watch ascii-index.js failed to match regex\n";
                }
            } else {
                fileload:
                if (file_exists("ascii-index.js")) {
                    echo "No irc.watch response, loading saved ascii-index.js\n";
                    $index = json_decode(file_get_contents("ascii-index.js"), 1);
                    if (!is_array($index)) {
                        return [];
                    }
                } else {
                    echo "No irc.watch response and no ascii-index.js file exists\n";
                    return [];
                }
            }
        } catch (Exception $error) {
            echo "$error\n";
            return [];
        }
        $out = [];
        foreach($index as $check) {
            if(!$noglob) {
                if (fnmatch("*$file*", strtolower($check))) {
                    $out[$check] = $check;
                }
            } else {
                if ($file == strtolower($check)) {
                    $out[$check] = $check;
                }
            }
        }

        return $out;
    });
}

//todo paginate search
#[Cmd("search", "find")]
#[Syntax('<query>')]
function searchart($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    $nick = $args->nick;
    $chan = $args->chan;
    $file = $req->args['query'];
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

#[Cmd("random")]
#[Syntax('[search]')]
function randart($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    $nick = $args->nick;
    $chan = $args->chan;

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
    $file = '';
    if(isset($req->args['search'])) {
        $file = strtolower($req->args['search']);
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

#[Cmd("stop")]
function stop($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    $nick = $args->nick;
    $chan = $args->chan;
    global $recordings, $playing;
    if(isset($playing[$chan])) {
        $playing[$chan] = [];
        $bot->pm($chan, 'stopped');
    } else {
        if(isset($recordings[$nick])) {
            endart($args, $bot, $req);
            return;
        }
        $bot->pm($chan, 'not playing');
    }
}

function playart($bot, $chan, $file, $searched = false, $opts = [])
{
    global $playing, $config;
    if (!isset($playing[$chan])) {
        $pump = irctools\loadartfile($file);
        var_dump($opts);
        if(array_key_exists('--flip', $opts)) {
            $pump = array_reverse($pump);
            //could be some dupes
            $find    = [
                "/", "\\",
                "╮", "╯", "╰", "╭", "┴", "┬",
                "┬", "╨",
                "┴", "╥",
                "┌", "┍", "┎", "┏",
                "└", "┕", "┖", "┗",
                "┘", "┙", "┚", "┛",
                "┐", "┑", "┒", "┓",
                "╚", "╝", "╔", "╗",
                "▀", "▄", "¯", "_"
            ];
            $replace = [
                "\\", "/",
                "╯", "╮", "╭", "╰", "┬", "┴",
                "┴", "╥",
                "┬", "╨",
                "└", "┕", "┖", "┗",
                "┌", "┍", "┎", "┏",
                "┐", "┑", "┒", "┓",
                "┘", "┙", "┚", "┛",
                "╔", "╗", "╚", "╝",
                "▄", "▀", "_", "¯"
                ];
            //we must do it this way or str_replace rereplaces
            foreach ($pump as &$line) {
                $newline = '';
                foreach (mb_str_split($line) as $c) {
                    $fnd = false;
                    foreach ($find as $k => $f) {
                        if($c == $f) {
                            $newline .= $replace[$k];
                            $fnd = true;
                            break;
                        }
                    }
                    if(!$fnd)
                        $newline .= $c;
                }
                $line = $newline;
            }
        }
        if($file != "ircwatch.txt")
            $pmsg = "Playing " . substr($file, strlen($config['artdir']));
        else
            $pmsg = "Playing from ircwatch";
        if($searched) {
            //TODO: use regex to know what to replace, for keeping case and for ?* in middle of word
            $pmsg = str_ireplace($searched, "\x0306$searched\x0F", $pmsg);
        }
        array_unshift($pump, $pmsg);
        pumpToChan($chan, $pump);
    }
}

//little helper because exec() echod
function quietExec($cmd)
{
    $descSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = proc_open($cmd, $descSpec, $pipes);
    if (!is_resource($p)) {
        throw new Exception("Unable to execute $cmd\n");
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($p);
    return [$rc, $out, $err];
}

#[Cmd("a2m")]
#[Syntax('<url>')]
#[Options('--width')]
function a2m($args, \Irc\Client $bot, \knivey\cmdr\Request $req)
{
    global $config;
    $chan = $args->chan;
    if(!isset($config['a2m'])) {
        $bot->pm($chan, "a2m not setup in config");
        return;
    }
    \Amp\asyncCall(function () use ($bot, $chan, $req) {
        global $playing, $config;
        try {
            $a2m = $config['a2m'];
            $url = $req->args['url'];
            /*
             * restricting the allowed URL for this to try to only do ansi arts otherwise anything would run through
             *
             * also content-type: application/octet-stream is what https://16colo.rs/ gives
             * curl -i https://16colo.rs/pack/impure79/raw/ldn-fatnikon.ans
             *
             * TODO since we are limiting to 16colo.rs just allow any url to the file and auto get width option etc
             */
            if(!preg_match("@https?://16colo\.rs/.+\.ans@i", $url)) {
                $bot->pm($chan, "\2a2m Error:\2 Limited to https://16colo.rs/ raw urls (https://16colo.rs/pack/impure79/raw/ldn-fatnikon.ans)");
                return;
            }

            $body = yield async_get_contents($url);
            if(!is_dir('ans'))
                mkdir('ans');
            // perhaps in future we try to find proper names and keep files around.
            $file = "ans/" . uniqid() . ".ans";
            file_put_contents($file, $body);
            $width = intval($req->args->getOptVal("--width"));
            if(!$width)
                $width = 80;
            list($rc, $out, $err) = quietExec("$a2m -w $width $file");
            if($rc != 0) {
                $bot->pm($chan, "\2a2m Error:\2 " . trim($err));
                unlink($file);
                return;
            }
            pumpToChan($chan, explode("\n", trim($out)));
            unlink($file);
        } catch (\async_get_exception $error) {
            $bot->pm($chan, $error->getIRCMsg());
        }
    });
}

