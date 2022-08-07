<?php
require_once 'library/async_get_contents.php';

use Amp\ByteStream\InputStream;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Router;
use Amp\Loop;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Promise;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Option;
use knivey\cmdr\attributes\Options;
use knivey\cmdr\attributes\Syntax;
use knivey\irctools;
use knivey\tools;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response as HttpResponse;
use Amp\Http\Server\Request as HttpRequest;
use Amp\Http\Status;
use function Amp\call;


define("IRC_WATCH", "https://internetrelaychat.github.io");

$recordings = [];

global $restRouter;

class BufferOverFlow extends \Exception {
}

function buffer(InputStream $stream, int $max): Promise {
    return call(function () use($stream, $max) {
        $buffer = '';
        while (null !== $chunk = yield $stream->read()) {
            $buffer .= $chunk;
            if(strlen($buffer) > $max)
                throw new \BufferOverFlow("Data too large");
        }
        return $buffer;
    });
}


$allowedPumps = [];

$restRouter->addRoute('POST', '/pump/{key}', new CallableRequestHandler(function (HttpRequest $request) {
    global $allowedPumps;
    $args = $request->getAttribute(Router::class);
    if(!isset($args['key']) || !array_key_exists($args['key'], $allowedPumps)) {
        return new HttpResponse(Status::FORBIDDEN, [
            "content-type" => "text/plain; charset=utf-8"
        ], "Invalid key\n");
    }

    $pumpInfo = $allowedPumps[$args['key']];
    try {
        $msg = yield buffer($request->getBody(), 1024 * 9000);
    } catch (\BufferOverFlow $e) {
        return new HttpResponse(Status::FORBIDDEN, [
            "content-type" => "text/plain; charset=utf-8"
        ], "{$e->getMessage()}\n");
    }
    $msg = str_replace("\r", "\n", $msg);
    $msg = explode("\n", $msg);
    $msg = array_filter($msg);
    array_unshift($msg, "Pump brought to you by {$pumpInfo['nick']}");
    pumpToChan($pumpInfo['chan'], $msg);
    unset($allowedPumps[$args['key']]);
    return new HttpResponse(Status::OK, ['content-type' => 'text/plain'], "PUMPED!\n");
}));

$restRouter->addRoute('POST', '/record/{key}', new CallableRequestHandler(function (HttpRequest $request) {
    global $recordTokens, $config;
    $args = $request->getAttribute(Router::class);
    if(!isset($args['key']) || !array_key_exists($args['key'], $recordTokens)) {
        return new HttpResponse(Status::FORBIDDEN, [
            "content-type" => "text/plain; charset=utf-8"
        ], "Invalid key\n");
    }
    $token = $recordTokens[$args['key']];
    try {
        $msg = yield buffer($request->getBody(), 1024 * 9000);
    } catch (\BufferOverFlow $e) {
        return new HttpResponse(Status::FORBIDDEN, [
            "content-type" => "text/plain; charset=utf-8"
        ], "{$e->getMessage()}\n");
    }
    $msg = str_replace("\r", "\n", $msg);
    $msg = explode("\n", $msg);
    $msg = array_filter($msg);

    if(count($msg) > 9000)
        return new HttpResponse(Status::FORBIDDEN, [
            "content-type" => "text/plain; charset=utf-8"
        ], "Too many lines\n");

    $dir = "${config['artdir']}h4x/{$token->nick}";
    if(file_exists($dir) && !is_dir($dir)) {
        return new HttpResponse(Status::INTERNAL_SERVER_ERROR, [
            "content-type" => "text/plain; charset=utf-8"
        ], "dir for recordings is not valid plz tell admin to fix\n");
    }
    if(!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
    $file = "$dir/{$token->file}.txt";
    file_put_contents($file, implode("\n", $msg));
    pumpToChan($token->chan, ["{$token->nick} has has posted a new art @{$token->file}"]);
    return new HttpResponse(Status::OK, ['content-type' => 'text/plain'], "SAVED!\n");
}));

class RecordToken {
    public function __construct(
        public string $nick,
        public string $chan,
        public string $file,
        public string $token
    ){}
}
/** @var RecordToken[String] */
$recordTokens = [];

/**
 * @param $nick
 * @param $chan
 * @param $file
 * @param $minutes
 * @return Promise<String>
 */
function requestRecordUrl($nick, $chan, $file, $minutes): Promise {
    return \Amp\call(function () use ($nick, $chan, $file, $minutes) {
        global $recordTokens;
        $key = bin2hex(random_bytes(5));
        $url = yield makeUrl("record/$key");
        if(!$url)
            throw new \Exception("Couldn't find my ip");
        $exists = array_reduce($recordTokens, fn($c, $t) => $t->nick == $nick ? $t->token : $c);
        if($exists)
            unset($recordTokens[$exists]);
        $token = new RecordToken($nick, $chan, $file, $key);
        $recordTokens[$key] = $token;
        \Amp\Loop::delay($minutes*60*1000, function () use ($key) {
            global $recordTokens;
            unset($recordTokens[$key]);
        });
        return $url;
    });
}

/**
 * @param string $route
 * @return Promise<String|False>
 */
function makeUrl(string $route): Promise {
    return \Amp\call(function () use ($route) {
        global $config;
        if (isset($config['rest_url'])) {
            $url = $config['rest_url'];
        } else {
            //need the http or it wont parse ipv6 correct
            $port = parse_url("http://{$config['listen']}", PHP_URL_PORT);
            $ourIp = false;
            foreach (["ifconfig.me", "icanhazip.com", "api.ipify.org", "bot.whatismyipaddress.com"] as $ipserv) {
                try {
                    $ourIp = yield async_get_contents("http://$ipserv");
                    if ($ourIp)
                        break;
                } catch (\Exception $e) {
                }
            }
            if (!$ourIp) {
                return false;
            }
            $https = isset($config['listen_cert']) ? "https" : "http";
            $url = "$https://$ourIp:$port";
        }
        $route = ltrim($route, '/');
        return "$url/$route";
    });
}

#[Cmd("getpumper")]
#[CallWrap("\Amp\asyncCall")]
function getpumper($args, \Irc\Client $bot, \knivey\cmdr\Request $req)
{
    global $config, $allowedPumps;
    $key = bin2hex(random_bytes(5));
    $url = yield makeUrl("pump/$key");
    if(!$url) {
        $bot->pm($args->chan, "Couldn't find my ip :(");
        return;
    }

    $allowedPumps[$key] = [
        'nick' => $args->nick,
        'chan' => $args->chan,
    ];

    $bot->notice($args->nick, "  $url  This is valid for 1 pump and expires in 10 min");
    \Amp\Loop::delay(10*60*1000, function () use ($key) {
        global $allowedPumps;
        unset($allowedPumps[$key]);
    });
}

$recordLimit = [];
$limitWarns = [];

#[Cmd("record")]
#[Option(["--post", "--url"], "Get a URL to post the art data to")]
#[Syntax('<filename>')]
#[CallWrap('\Amp\asyncCall')]
function record($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    $nick = $args->nick;
    $chan = $args->chan;
    $host = $args->host;
    $file = $req->args['filename'];
    global $recordings, $config, $recordLimit, $limitWarns;
    if(isset($recordings[$nick])) {
        return;
    }
    if(str_contains($file, "/") || $file[0] == '.') {
        $bot->pm($chan, 'Pick a filename without / and not starting with .');
        return;
    }
    if(!preg_match('//u', $file)) {
        $bot->pm($chan, 'Use proper UTF-8 encoding for the filename.');
        return;
    }
    if(preg_match('/[\x00-\x1F\x7F]/u', $file)) {
        $bot->pm($chan, "Don't use control chars in filename.");
        return;
    }
    $reserved = ['artfart', 'random', 'search', 'find', 'stop', 'record', 'end', 'cancel',
        'addquote', 'quote', 'bash', 'circles', 'lines', 'img', 'url', 'cancelquote', 'endquote', 'stopquote'];
    if(in_array(strtolower($file), $reserved)) {
        $bot->pm($chan, 'That name has been reserved');
        return;
    }
    if(isset($recordLimit[$host]) && $recordLimit[$host] > time()) {
        if(!isset($limitWarns[$host]) || $limitWarns[$host] < time()-2) {
            $bot->pm($chan, "You're recording too fast, wait awhile");
            $limitWarns[$host] = time();
        }
        return;
    }
    $recordLimit[$host] = time()+2;
    unset($limitWarns[$host]);

    $exists = false;
    $base = $config['artdir'];
    try {
        $tree = knivey\tools\dirtree($base);
    } catch (Exception $e) {
        echo "{$e}\n";
        return;
    }
    foreach($tree as $ent) {
        if(strtolower(substr($ent, strlen($config['artdir']))) == strtolower("h4x/{$nick}/$file.txt")) {
            $bot->pm($chan, "\x0300,04\x02You can no longer record over arts due to abuse >:(\x02\x03 you can ask slime or jewbird to delete the file");
            return;
        }
        if($file == strtolower(basename($ent, '.txt'))) {
            $exists = substr($ent, strlen($config['artdir']));
            break;
        }
    }

    if($req->args->getOpt('--post') || $req->args->getOpt('--url')) {
        if($exists)
            $bot->pm($chan, "Warning: That file name has been used by $exists, to playback may require a full path or you can @record --post again to use a new name");
        try {
            $url = yield requestRecordUrl($nick, $chan, $file, 60);
        } catch (\Exception $e) {
            $bot->notice($nick, "Problem encountered: {$e->getMessage()}");
            return;
        }
        $bot->notice($nick, "  $url   This is valid for one recording, valid for 60 minutes unless another recording url is requested");
        return;
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

$reqArtOpts = ['--flip', '--edit', '--asciibird', '--speed'];
function reqart($bot, $chan, $file, $opts = [], $args = []) {
    \Amp\asyncCall(function() use ($bot, $chan, $file, $opts, $args) {
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

        $tryEdit = function ($ent, $ircwatch = false) use ($bot, $chan, $opts) {
            global $config;
            if(array_key_exists('--edit', $opts) || array_key_exists('--asciibird', $opts)) {
                if($ircwatch) {
                    $bot->pm($chan, "https://asciibird.jewbird.live/?ircwatch=$ent");
                } else {
                    $relPath = substr($ent, strlen($config['artdir']));
                    $bot->pm($chan, "https://asciibird.jewbird.live/?haxAscii=$relPath");
                }
                return true;
            }
            return false;
        };

        $speed = null;
        if(array_key_exists('--speed', $opts)) {
            $speed = $opts['--speed'];
            if(!is_numeric($speed) || $speed < 20 || $speed > 500) {
                $bot->pm($chan, "--speed must be between 20 and 500 (milliseconds between lines)");
                return;
            }
        }

        if(strlen($file) > 1 && $file[0] == '@') {
            $file = substr($file, 1);
            $art = selectRandFile($file);
            if($art !== false)
                playart($bot, $chan, $art, $file, $opts, $args, $speed);
            else
                $bot->pm($chan, "no matching art found");
            return;
        }
        //try fullpath first
        foreach($tree as $ent) {
            if ($file . '.txt' == strtolower(substr($ent, strlen($config['artdir'])))) {
                if($tryEdit($ent))
                    return;
                playart($bot, $chan, $ent, opts: $opts, args: $args, speed: $speed);
                return;
            }
        }
        foreach($tree as $ent) {
            if($file == strtolower(basename($ent, '.txt'))) {
                if($tryEdit($ent))
                    return;
                playart($bot, $chan, $ent, opts: $opts, args: $args, speed: $speed);
                return;
            }
        }
        try { // TODO one art is all caps and request is case sensitive, so get the correct name from ascii-index.js
            $client = HttpClientBuilder::buildDefault();
            $url = IRC_WATCH . "/ascii/$file/";
            $req = new Request(IRC_WATCH . "/ascii/txt/$file.txt");
            /** @var Response $response */
            $response = yield $client->request($req);
            $body = yield $response->getBody()->buffer();
            if ($response->getStatus() == 200) {
                if($tryEdit("$file.txt", true))
                    return;
                file_put_contents("ircwatch.txt", "$body\n$url");
                playart($bot, $chan, "ircwatch.txt", opts: $opts, args: $args, speed: $speed);
                return;
            } else {
                echo "irc.watch error: " . $response->getStatus() ."\n";
                echo $body;
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
            $req = new Request(IRC_WATCH . "/js/ascii-index.js");
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

$trashLimit = [];
$trashLimitWarns = [];
#[Cmd("trash")]
#[Syntax("<file>")]
function trash($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    global $config, $trashLimit, $trashLimitWarns;
    $host = $args->host;
    if(isset($trashLimit[$host]) && $trashLimit[$host] > time()) {
        if(!isset($trashLimitWarns[$host]) || $trashLimitWarns[$host] < time()-2) {
            $bot->pm($args->chan, "You're trashing too fast, wait awhile");
            $trashLimitWarns[$host] = time();
        }
        return;
    }
    $trashLimit[$host] = time()+2;
    unset($trashLimitWarns[$host]);

    //Some networks can easily fake hosts to bypass host based auth
    if(!($config['trustedNetwork'] ?? false)) {
        $bot->pm($args->chan, "This network isn't trusted for authentication");
        return;
    }
    if(!isset($config['trashDir'])) {
        $bot->pm($args->chan, "Trash not configured");
        return;
    }
    $allowed = file("artadmins.txt", FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
    $pass = false;
    foreach ($allowed as $mask) {
        if(preg_match(\knivey\tools\globToRegex($mask).'i', $args->fullhost))
            $pass = true;
    }
    if(!$pass) {
        $bot->pm($args->chan, "Your host isn't authorized");
        return;
    }
    $file = $req->args['file'];
    if(!str_starts_with($file, 'h4x/')) {
        $bot->pm($args->chan, "You can only trash files in h4x/ give the full art path/name (no .txt)");
        return;
    }
    $fullpath = realpath($config['artdir']) . "/{$file}.txt";
    if(!is_file($fullpath)) {
        $bot->pm($args->chan, "That doesnt seem to be a file..");
        return;
    }
    $mustBeIn = realpath($config['artdir'] . '/h4x') . '/';
    if(!str_starts_with(realpath($fullpath), $mustBeIn)) {
        $bot->pm($args->chan, "You can only trash files in h4x/ no dirty tricks!!");
        return;
    }
    $end = substr(realpath($fullpath), strlen($mustBeIn));
    $end = dirname($end);

    $trashDir = $config['trashDir'];
    if(file_exists($trashDir) && !is_dir($trashDir)) {
        $bot->pm($args->chan, "Problem with trash directory config");
        return;
    }
    if(!file_exists($trashDir))
        mkdir($trashDir, 0777, true);

    $trashTo = "{$trashDir}/$end";
    @mkdir($trashTo, 0777, true);
    $to = "{$trashTo}/" . basename($fullpath) . tools\microtime_float();
    rename($fullpath, $to);
    $log = "{$args->fullhost} {$file}  $fullpath => $to\n";
    file_put_contents("$trashDir/log", $log, FILE_APPEND|LOCK_EX);
    $bot->pm($args->chan, "Art file moved to trash");
}

/**
 * Gets Finder for art dir, excluding p2u
 */
function getFinder() : \Symfony\Component\Finder\Finder {
    global $config;
    $finder = new Symfony\Component\Finder\Finder();
    $finder->files();
    $finder->in($config['artdir'])->exclude("p2u");
    return $finder;
}

#[Cmd("search", "find")]
#[Option(["--max"], "Max results to show")]
#[Syntax('<query>')]
function searchart($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    $nick = $args->nick;
    $chan = $args->chan;
    $file = $req->args['query'];
    global $config;
    $max = $config['art_search_max'] ?? 100;
    if($req->args->getOpt("--max")) {
        $max = $req->args->getOptVal("--max");
        if (!is_numeric($max)) {
            $bot->msg($args->chan, "--max must be numeric");
            return;
        }
        if ($max < 1) {
            $bot->msg($args->chan, "--max too small, must be >0");
            return;
        }
        if ($max > 10000) {
            $bot->msg($args->chan, "--max too big, limit to <10000");
            return;
        }
    }
    \Amp\asyncCall(function () use ($bot, $chan, $file, $max) {
        global $playing;
        if (isset($playing[$chan])) {
            return;
        }
        $finder = getFinder();
        $finder->path(tools\globToRegex("*$file*.txt") . 'i');
        $ircwatch = yield searchIrcwatch($file);
        foreach($finder as $f) {
            /** @var $f Symfony\Component\Finder\SplFileInfo */
            unset($ircwatch[$f->getBasename('.txt')]);
        }
        $finder->sortByModifiedTime();
        $out = [];
        foreach($finder as $f) {
            /** @var $f Symfony\Component\Finder\SplFileInfo */
            $out[] = substr($f->getRelativePathname(), 0, -4);
        }
        foreach ($ircwatch as $f) {
            $out[] = "ircwatch/$f";
        }

        if(empty($out)) {
            $bot->pm($chan, "no matching art found");
            return;
        }

        $out = preg_replace(tools\globToRegex($file) . 'i', "\x0306\\0\x0F", $out);

        if($cnt = count($out) > $max) {
            $out = array_slice($out, 0, $max);
            $out[] = "$cnt total matches, only showing $max";
        }

        pumpToChan($chan, $out);
    });
}

#[Cmd("recent")]
#[Option(["--play"], "Play each art")]
#[Syntax('[since]...')]
function recent($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    global $config;
    $since = $req->args['since'] ?? '8 days ago';
    $time = strtotime($since);
    //sometimes people just put "5 hours" when they mean "5 hours ago";
    if(time() <= $time) {
        $since = "$since ago";
        $time = strtotime($since);
    }
    if($time === false) {
        $bot->pm($args->chan, "You must give me something php strtotime() can understand, Ex: 8 days ago");
        return;
    }

    $finder = getFinder();
    $finder->date("since $since");
    $finder->sortByModifiedTime();
    if(!$finder->hasResults()) {
        $bot->pm($args->chan, "Nothing found");
        return;
    }
    $out = ["Found {$finder->count()} arts recorded since $since:"];

    //Play the full arts
    if($req->args->getOpt("--play")) {
        if($finder->count() > 500) {
            $bot->pm($args->chan, "thats too many arts to play :(");
            return;
        }
        foreach($finder as $file) {
            $ago = (new Carbon($file->getMTime()))->diffForHumans(Carbon::now(), CarbonInterface::DIFF_RELATIVE_TO_NOW, true, 2);
            $name = substr($file->getRelativePathname(), 0, -4);
            $len = strlen("-----------------------------------------------------------------------------------");
            if(strlen("||||| $ago  $name |||||") < $len)
                $pads = str_repeat("|", $len - strlen("||||| $ago  $name |||||"));
            $out[] = "\x02\x0300,12-----------------------------------------------------------------------------------";
            $out[] = "\x02\x0300,12||||| $ago $pads $name |||||";
            $out[] = "\x02\x0300,12-----------------------------------------------------------------------------------";
            $out = array_merge($out, irctools\loadartfile($file->getRealPath()));
        }
        pumpToChan($args->chan, $out);
        return;
    }


    $table = [];
    foreach($finder as $file) {
        $ago = (new Carbon($file->getMTime()))->diffForHumans(Carbon::now(), CarbonInterface::DIFF_RELATIVE_TO_NOW, true, 2);
        $table[] = [$ago, substr($file->getRelativePathname(), 0, -4)];
    }
    $table = \knivey\tools\multi_array_padding($table);
    $out = array_merge($out, array_map(fn($v) => rtrim(implode($v)), $table));
    pumpToChan($args->chan, $out);
}

function selectRandFile($search = null) : String|false {
    global $config;
    $base = $config['artdir'];
    try {
        $tree = knivey\tools\dirtree($base);
    } catch (Exception $e) {
        echo "{$e}\n";
        return false;
    }
    //remove p2u files
    $tree = array_filter($tree, function ($it) {
        global $config;
        $check = substr($it, strlen($config['artdir']));
        return !preg_match("@^p2u/.*@", $check);
    });

    if($search != null) {
        $search = strtolower($search);
        $tree = array_filter($tree, function ($it) use ($search) {
            global $config;
            $check = substr($it, strlen($config['artdir']));
            $check = preg_replace('/\.txt$/i', '', $check);
            return fnmatch("*$search*", strtolower($check));
        });
    }
    if(!empty($tree))
        return $tree[array_rand($tree)];
    return false;
}

#[Cmd("random")]
#[Options("--flip", "--speed")]
#[Syntax('[search]')]
function randart($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    $chan = $args->chan;
    global $playing;
    if(isset($playing[$chan])) {
        return;
    }

    $speed = null;
    if($req->args->getOpt("--speed")) {
        $speed = $req->args->getOptVal("--speed");
        if(!is_numeric($speed) || $speed < 20 || $speed > 500) {
            $bot->pm($chan, "--speed must be between 20 and 500 (milliseconds between lines)");
            return;
        }
    }
    $opts = $req->args->getOpts();

    $search = '';
    if(isset($req->args['search'])) {
        $search = strtolower($req->args['search']);
    }
    $art = selectRandFile($search);

    if($art !== false)
        playart($bot, $chan, $art, $search, $opts, [], $speed);
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

function playart($bot, $chan, $file, $searched = false, $opts = [], $args = [], $speed = null)
{
    global $playing, $config;
    if (isset($playing[$chan])) {
        return;
    }
    $pump = irctools\loadartfile($file);
    //var_dump($opts);
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
    pumpToChan($chan, $pump, speed: $speed);
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

#[Cmd("a2m", "ans")]
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
            if(!preg_match("@https?://16colo\.rs/.+\.(?:ans|asc|cia)@i", $url)) {
                $bot->pm($chan, "\2a2m Error:\2 Limited to https://16colo.rs/ urls (ans|asc) (https://16colo.rs/pack/impure79/raw/ldn-fatnikon.ans)");
                return;
            }
            //try to change to raw url here
            // https://16colo.rs/pack/croyale01/raw/sp-coc.asc
            // https://16colo.rs/pack/ane-0696/DA-MASK.ANS
            // https://16colo.rs/pack/ane-0696/data/DA-MASK.ANS
            // https://16colo.rs/pack/ciapak12/raw/DA-NXS.CIA
            if(!preg_match("@https?://16colo\.rs/pack/[^/]+/raw/.+\.(?:ans|asc|cia)@i", $url)) {
                if(!preg_match("@https?://16colo\.rs/pack/([^/]+)/(.+\.(?:ans|asc|cia))@i", $url, $m)) {
                    $bot->pm($chan, "\2a2m Error:\2 url seems wrong");
                    return;
                }
                try {
                    $data = yield async_get_contents("https://16colo.rs/pack/$m[1]/data/$m[2]");
                    $json = json_decode($data);
                    if (isset($json->sauce->tinfo1)) {
                        $width = $json->sauce->tinfo1;
                    }
                } catch (\Exception $e) {
                }
                $url = "https://16colo.rs/pack/$m[1]/raw/$m[2]";
            }
            $body = yield async_get_contents($url);
            if(!is_dir('ans'))
                mkdir('ans');
            // perhaps in future we try to find proper names and keep files around.
            $file = "ans/" . uniqid() . ".ans";
            file_put_contents($file, $body);
            if(!isset($width))
                $width = intval($req->args->getOptVal("--width"));
            if(!$width)
                $width = 80;
            list($rc, $out, $err) = quietExec("$a2m -w $width $file");
            if($rc != 0) {
                $bot->pm($chan, "\2a2m Error:\2 " . trim($err));
                unlink($file);
                return;
            }
            pumpToChan($chan, explode("\n", rtrim($out)));
            unlink($file);
        } catch (\async_get_exception $error) {
            $bot->pm($chan, "\a2m:\2 {$error->getIRCMsg()}");
        } catch (\Exception $error) {
            echo $error->getMessage();
            $bot->pm($chan, "\2a2m:\2 {$error->getMessage()}");
            return;
        }
    });
}

