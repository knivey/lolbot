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
use knivey\cmdr\attributes\Desc;
use knivey\cmdr\attributes\Option;
use knivey\cmdr\attributes\Options;
use knivey\cmdr\attributes\Syntax;
use knivey\irctools;
use knivey\tools;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response as HttpResponse;
use Amp\Http\Server\Request as HttpRequest;
use Amp\Http\Status;
use Symfony\Component\Yaml\Yaml;
use function Amp\call;

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

function asciipost_to_array(string $msg): array {
    $msg = str_replace("\r", "\n", $msg);
    $msg = explode("\n", $msg);
    return array_filter($msg);
}

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
    $msg = asciipost_to_array($msg);
    if(count($msg) > 9000)
        return new HttpResponse(Status::FORBIDDEN, [
            "content-type" => "text/plain; charset=utf-8"
        ], "Too many lines\n");
    array_unshift($msg, "Pump brought to you by {$pumpInfo['nick']}");
    pumpToChan($pumpInfo['chan'], $msg);
    unset($allowedPumps[$args['key']]);
    return new HttpResponse(Status::OK, ['content-type' => 'text/plain'], "PUMPED!\n");
}));

$restRouter->addRoute('POST', '/record2/{key}/{filename}', new CallableRequestHandler(function (HttpRequest $request) {
    global $config;
    $keys = Yaml::parseFile('recording_keys.yaml');
    $args = $request->getAttribute(Router::class);
    if(!isset($args['key']) || !isset($keys[$args['key']])) {
        return new HttpResponse(Status::FORBIDDEN, [
            "content-type" => "text/plain; charset=utf-8"
        ], "Invalid key\n");
    }
    $user = $keys[$args['key']];
    if(!isset($args['filename'])) {
        return new HttpResponse(Status::FORBIDDEN, [
            "content-type" => "text/plain; charset=utf-8"
        ], "Missing required filename\n");
    }

    $file = $args['filename'];

    try {
        $msg = yield buffer($request->getBody(), 1024 * 9000);
    } catch (\BufferOverFlow $e) {
        return new HttpResponse(Status::FORBIDDEN, [
            "content-type" => "text/plain; charset=utf-8"
        ], "{$e->getMessage()}\n");
    }
    $msg = asciipost_to_array($msg);
    if(count($msg) > 9000)
        return new HttpResponse(Status::FORBIDDEN, [
            "content-type" => "text/plain; charset=utf-8"
        ], "Too many lines\n");

    $dir = "${config['artdir']}h4x/{$user}";
    if(file_exists($dir) && !is_dir($dir)) {
        return new HttpResponse(Status::INTERNAL_SERVER_ERROR, [
            "content-type" => "text/plain; charset=utf-8"
        ], "dir for recordings is not valid plz tell admin to fix\n");
    }
    if(!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
    $cnt = 0;
    while((file_exists("$dir/{$file}.txt") && $cnt == 0) || file_exists("$dir/{$file}-$cnt.txt")) {
        $cnt++;
    }
    if($cnt > 0)
        $file = "$file-$cnt";
    file_put_contents("$dir/{$file}.txt", implode("\n", $msg));
    return new HttpResponse(Status::OK, ['content-type' => 'text/plain'], "h4x/{$user}/$file.txt\n");
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
    $msg = asciipost_to_array($msg);

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
    pumpToChan($token->chan, ["{$token->nick} has posted a new art @{$token->file}"]);
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
#[Desc("Gets a URL you can send a HTTP POST to play art in the channel")]
#[CallWrap("\Amp\asyncCall")]
function getpumper($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
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
#[Desc("Record a new art, use this, paste the art to the chat then type @end when finished")]
#[Option(["--post", "--url"], "Get a URL to POST the art data to instead of pasting it to the channel")]
#[Syntax('<filename>')]
#[CallWrap('\Amp\asyncCall')]
function record($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
    $nick = $args->nick;
    $chan = $args->chan;
    $host = $args->host;
    $file = $cmdArgs['filename'];
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

    $files = getFinder()->path("@^h4x/" . preg_quote($nick, "@") . "/". preg_quote($file, "@") ."\.txt$@i");
    if($files->hasResults()) {
        //should just be one file
        $existing = implode(', ', array_map(function ($file) {
            return substr($file->getRelativePathname(), 0, -4);
        }, iterator_to_array($files, false)));
        $bot->pm($chan, "$existing already exists, \x0300,04\x02Existing arts files can no longer be recorded over due to abuse >:(\x02\x03 you can ask slime, jewbird or sansGato to delete the file or pick another name");
        return;
    }
    $files = getFinder()->name("@^" . preg_quote($file, "@") . "\.txt$@i");
    if($files->hasResults()) {
        $files = implode(', ', array_map(function ($file) {
            return substr($file->getRelativePathname(), 0, -4);
        }, iterator_to_array($files, false)));
        $bot->pm($chan, "Warning: That file name has been used by $files, to playback may require a full path or you can @record again to use a new name");
    }

    if($cmdArgs->optEnabled('--post') || $cmdArgs->optEnabled('--url')) {
        try {
            $url = yield requestRecordUrl($nick, $chan, $file, 60);
        } catch (\Exception $e) {
            $bot->notice($nick, "Problem encountered: {$e->getMessage()}");
            return;
        }
        $bot->notice($nick, "  $url   This is valid for one recording, valid for 60 minutes unless another recording url is requested");
        return;
    }

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
#[Desc("Finish recording art")]
function endart($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
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
#[Desc("Cancel recording art")]
function cancel($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
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

        $tryEdit = function ($ent) use ($bot, $chan, $opts) {
            global $config;
            if(array_key_exists('--edit', $opts) || array_key_exists('--asciibird', $opts)) {
                $relPath = substr($ent, strlen($config['artdir']));
                $bot->pm($chan, "https://asciibird.birdnest.live/?haxAscii=$relPath");
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
        //$bot->pm($chan, "that art not found");
    });
}

$trashLimit = [];
$trashLimitWarns = [];
#[Cmd("trash")]
#[Desc("Move a recorded art to trash, requires special access.")]
#[Syntax("<file>")]
function trash($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
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
    $file = $cmdArgs['file'];
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
#[Desc("Search for art by mathcing against directorys/names")]
#[Option(["--max"], "Max results to show")]
#[Option(["--details"], "Show more details in the results")]
#[Option(["--dates"], "Show dates instead of relative times")]
#[Option(["--play"], "Play all the files found")]
#[Option("--maxlines", "When using --play any result over this limit (default 100) is skipped")]
#[Syntax('<query>')]
function searchart($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
    $nick = $args->nick;
    $chan = $args->chan;
    $file = $cmdArgs['query'];
    global $config;
    $max = $config['art_search_max'] ?? 100;
    if($cmdArgs->optEnabled("--max")) {
        $max = $cmdArgs->getOpt("--max");
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
    \Amp\asyncCall(function () use ($bot, $chan, $file, $max, $cmdArgs) {
        global $playing;
        if (isset($playing[$chan])) {
            return;
        }
        $finder = getFinder();
        $finder->path(tools\globToRegex("*$file*.txt") . 'i');
        $finder->sortByModifiedTime();
        $out = [];
        if($cmdArgs->optEnabled("--play")) {
            $maxlines = $cmdArgs->getOpt("--maxlines");
            if($maxlines === false)
                $maxlines = 100;
            if($maxlines <= 0) {
                $bot->msg($chan, "--maxlines must be positive number");
                return;
            }
            foreach($finder as $file) {
                if($maxlines && mb_substr_count($file->getContents(), "\n")+1 > $maxlines) {
                    continue;
                }
                $ago = (new Carbon($file->getMTime()))->diffForHumans(Carbon::now(), CarbonInterface::DIFF_RELATIVE_TO_NOW, true, 2);
                $name = substr($file->getRelativePathname(), 0, -4);
                $len = strlen("-----------------------------------------------------------------------------------");
                if(strlen("||||| $ago  $name |||||") < $len)
                    $pads = str_repeat("|", $len - strlen("||||| $ago  $name |||||"));
                $out[] = "\x02\x0300,12-----------------------------------------------------------------------------------";
                $out[] = "\x02\x0300,12||||| $ago $pads $name |||||";
                $out[] = "\x02\x0300,12-----------------------------------------------------------------------------------";
                $pump = irctools\loadartfile($file->getRealPath());
                foreach (($config['wordwrap_dirs']??[]) as $lwdir) {
                    if(substr_compare(substr($file, strlen($config['artdir'])), $lwdir, 0, strlen($lwdir)) === 0) {
                        $npump = [];
                        foreach($pump as $line) {
                            $npump = array_merge($npump, explode("\n", wordwrap($line, 505 - strlen($bot->getNickHost()), "\n", true)));
                        }
                        $pump = $npump;
                        break;
                    }
                }
                $out = array_merge($out, $pump);
            }
            if(empty($out)) {
                $bot->pm($chan, "no matching art found");
                return;
            }
            pumpToChan($chan, $out);
            return;
        }

        foreach($finder as $f) {
            /** @var $f Symfony\Component\Finder\SplFileInfo */
            if(!$cmdArgs->optEnabled("--details")) {
                $out[] = substr($f->getRelativePathname(), 0, -4);
            } else {
                $lines = mb_substr_count($f->getContents(), "\n")+1;
                if($cmdArgs->optEnabled("--dates"))
                    $ago = Carbon::createFromTimestamp($f->getMTime())->toRssString();
                else
                    $ago = (new Carbon($f->getMTime()))->diffForHumans(Carbon::now(), CarbonInterface::DIFF_RELATIVE_TO_NOW, true, 2);
                $out[] = ["$lines lines ", $ago, substr($f->getRelativePathname(), 0, -4)];
            }
        }
        if(empty($out)) {
            $bot->pm($chan, "no matching art found");
            return;
        }

        if($cmdArgs->optEnabled("--details")) {
            $out = array_map(fn($it) => trim(implode(' ', $it)), tools\multi_array_padding($out));
        }

        $out = preg_replace(tools\globToRegex($file, '/', false) . 'i', "\x0306\$0\x0F", $out);

        if(($cnt = count($out)) > $max) {
            $out = array_slice($out, 0, $max);
            $out[] = "$cnt total matches, only showing $max";
        }

        pumpToChan($chan, $out);
    });
}

#[Cmd("recent")]
#[Desc("Show arts recently recorded defaults to since 8 days ago")]
#[Option(["--play"], "Play each art")]
#[Option("--maxlines", "When using --play any result over this limit (default 100) is skipped")]
#[Syntax('[since]...')]
function recent($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
    global $config;
    $since = $cmdArgs['since'] ?? '8 days ago';
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
    if($cmdArgs->optEnabled("--play")) {
        if($finder->count() > 500) {
            $bot->pm($args->chan, "thats too many arts to play :(");
            return;
        }
        $maxlines = $cmdArgs->getOpt("--maxlines");
        if($maxlines === false)
            $maxlines = 100;
        if($maxlines <= 0) {
            $bot->msg($args->chan, "--maxlines must be positive number");
            return;
        }
        foreach($finder as $file) {
            if($maxlines && mb_substr_count($file->getContents(), "\n")+1 > $maxlines) {
                continue;
            }
            $ago = (new Carbon($file->getMTime()))->diffForHumans(Carbon::now(), CarbonInterface::DIFF_RELATIVE_TO_NOW, true, 2);
            $name = substr($file->getRelativePathname(), 0, -4);
            $len = strlen("-----------------------------------------------------------------------------------");
            if(strlen("||||| $ago  $name |||||") < $len)
                $pads = str_repeat("|", $len - strlen("||||| $ago  $name |||||"));
            $out[] = "\x02\x0300,12-----------------------------------------------------------------------------------";
            $out[] = "\x02\x0300,12||||| $ago $pads $name |||||";
            $out[] = "\x02\x0300,12-----------------------------------------------------------------------------------";
            $pump = irctools\loadartfile($file->getRealPath());
            foreach (($config['wordwrap_dirs']??[]) as $lwdir) {
                if(substr_compare(substr($file, strlen($config['artdir'])), $lwdir, 0, strlen($lwdir)) === 0) {
                    $npump = [];
                    foreach($pump as $line) {
                        $npump = array_merge($npump, explode("\n", wordwrap($line, 505 - strlen($bot->getNickHost()), "\n", true)));
                    }
                    $pump = $npump;
                    break;
                }
            }
            $out = array_merge($out, $pump);
        }
        pumpToChan($args->chan, $out);
        return;
    }


    $table = [];
    foreach($finder as $file) {
        $lines = mb_substr_count($file->getContents(), "\n")+1;
        $ago = (new Carbon($file->getMTime()))->diffForHumans(Carbon::now(), CarbonInterface::DIFF_RELATIVE_TO_NOW, true, 2);
        $table[] = ["$lines lines ", $ago, substr($file->getRelativePathname(), 0, -4)];
    }
    $table = \knivey\tools\multi_array_padding($table);
    $out = array_merge($out, array_map(fn($v) => rtrim(implode($v)), $table));
    pumpToChan($args->chan, $out);
}

function selectRandFile($search = null) : String|false {
    $finder = getFinder();
    if($search != null) {
        $finder->path(tools\globToRegex("*$search*.txt") . 'i');
    }
    $tree = iterator_to_array($finder, false);
    if(!empty($tree))
        return $tree[array_rand($tree)]->getRealPath();
    return false;
}

#[Cmd("random")]
#[Desc("Play a random art, if given a search it will pick at random from the results")]
#[Option("--flip", "play the art upside down")]
#[Option("--speed", "set the playback speed, delay between lines in ms")]
#[Syntax('[search]')]
function randart($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
    $chan = $args->chan;
    global $playing;
    if(isset($playing[$chan])) {
        return;
    }

    $speed = null;
    if($cmdArgs->optEnabled("--speed")) {
        $speed = $cmdArgs->getOpt("--speed");
        if(!is_numeric($speed) || $speed < 20 || $speed > 500) {
            $bot->pm($chan, "--speed must be between 20 and 500 (milliseconds between lines)");
            return;
        }
    }
    $opts = $cmdArgs->getOpts();

    $search = '';
    if(isset($cmdArgs['search'])) {
        $search = strtolower($cmdArgs['search']);
    }
    $art = selectRandFile($search);

    if($art !== false)
        playart($bot, $chan, $art, $search, $opts, [], $speed);
    else
        $bot->pm($chan, "no matching art found");
}

#[Cmd("stop")]
#[Desc("Stops art playback")]
function stop($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
    $nick = $args->nick;
    $chan = $args->chan;
    global $recordings, $playing;
    if(isset($playing[$chan])) {
        $playing[$chan] = [];
        $bot->pm($chan, 'stopped');
    } else {
        if(isset($recordings[$nick])) {
            endart($args, $bot, $cmdArgs);
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
    foreach (($config['wordwrap_dirs']??[]) as $lwdir) {
        if(substr_compare(substr($file, strlen($config['artdir'])), $lwdir, 0, strlen($lwdir)) === 0) {
            $npump = [];
            foreach($pump as $line) {
                $npump = array_merge($npump, explode("\n", wordwrap($line, 505 - strlen($bot->getNickHost()), "\n", true)));
            }
            $pump = $npump;
            break;
        }
    }

    //var_dump($opts);
    if(isset($opts['--flip'])) {
        $pump = array_reverse($pump);
        //could be some dupes
        $find    = [
            "/", "\\", "╱", "╲",
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
            "\\", "/", "╲", "╱",
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

    $pmsg = "Playing " . substr($file, strlen($config['artdir']));
    if($searched) {
        $pmsg = preg_replace(tools\globToRegex($searched, '/', false) . 'i', "\x0306\$0\x0F", $pmsg);
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
#[Desc("Convert ansi from 16colo.rs to mirc art")]
#[Syntax('<url>')]
#[Option('--width', "force a width to convert at, otherwise we try to detect it from the website")]
function a2m($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    global $config;
    $chan = $args->chan;
    if(!isset($config['a2m'])) {
        $bot->pm($chan, "a2m not setup in config");
        return;
    }
    \Amp\asyncCall(function () use ($bot, $chan, $cmdArgs) {
        global $playing, $config;
        try {
            $a2m = $config['a2m'];
            $url = $cmdArgs['url'];
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
                $width = intval($cmdArgs->getOpt("--width"));
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

