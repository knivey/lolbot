<?php
namespace scripts\linktitles;

use Amp\Http\Client\Cookie\CookieInterceptor;
use Amp\Http\Client\Cookie\InMemoryCookieJar;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Options;
use knivey\cmdr\attributes\Syntax;



// db is used for ignoring urls from userhosts or per channel URL regex ignores
$dbfile = "linktitles.db";
/*
 * Just keeping things relatively simple, no migration tool
 */
if(!file_exists($dbfile)) {
    $url_pdo = new \PDO("sqlite:$dbfile", null, null,
        [\PDO::ATTR_PERSISTENT => true, \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $url_pdo->query("CREATE table chan_re(
        id integer primary key autoincrement,
        regex text unique not null,
        chan text not null collate nocase,
        nick text not null collate nocase,
        host text not null,
        auth text collate nocase
        );");
    $url_pdo->query("CREATE table userhosts(
        id integer primary key autoincrement,
        ignore_re text not null,
        nick text not null collate nocase,
        host text not null,
        auth text collate nocase
        );");
} else {
    $url_pdo = new \PDO("sqlite:$dbfile", null, null, [\PDO::ATTR_PERSISTENT => true]);
}
/** @var $url_pdo \PDO */

//feature requested by terps
//sends all urls into a log channel for easier viewing url history
//TODO take url as param to highlight it here
/**
 * @param $bot
 * @param $nick
 * @param $chan
 * @param $line
 * @param string|array $title
 * @return void
 */
function logUrl($bot, $nick, $chan, $line, string|array $title) {
    global $config;
    if(!isset($config['url_log_chan']))
        return;
    $logChan = $config['url_log_chan'];
    static $max = 0;
    $max = max(strlen($chan), $max);
    $chan = str_pad($chan, $max);
    $bot->pm($logChan, "$chan | <$nick> $line");
    if(is_string($title))
        $title = [$title];
    foreach ($title as $msg)
        $bot->pm($logChan, "  $msg");
}

$link_history = [];
$link_ratelimit = 0;
function linktitles(\Irc\Client $bot, $nick, $chan, $host, $text)
{
    global $link_history, $link_ratelimit, $eventDispatcher;
    foreach (explode(' ', $text) as $word) {
        if (filter_var($word, FILTER_VALIDATE_URL) === false) {
            continue;
        }
        if (urlIsIgnored($chan, $nick, $host, $word))
            continue;

        if (($link_history[$chan] ?? "") == $word) {
            continue;
        }
        $link_history[$chan] = $word;

        if (time() < $link_ratelimit) {
            logUrl($bot, $nick, $chan, $text, "Err: Rate limit exceeded");
            return;
        }
        $link_ratelimit = time() + 2;

        $urlEvent = new UrlEvent();
        $urlEvent->url = $word;
        $urlEvent->chan = $chan;
        $urlEvent->nick = $nick;
        $urlEvent->text = $text;
        $eventDispatcher->dispatch($urlEvent);

        yield \Amp\Promise\any($urlEvent->promises);
        if($urlEvent->handled) {
            $urlEvent->sendReplies($bot, $chan);
            $urlEvent->doLog($bot);
            continue;
        }

        try {
            $cookieJar = new InMemoryCookieJar;
            $client = (new HttpClientBuilder)
                ->interceptNetwork(new CookieInterceptor($cookieJar))
                ->build();
            $req = new Request($word);
            $req->setHeader("User-Agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/78.0.3904.108 Safari/537.36");
            $req->setHeader("Accept", "text/html, application/xhtml+xml, application/xml;q=0.9, */*;q=0.8");
            $req->setHeader("Accept-Language", "en-US, en;q=0.9");
            $req->setTransferTimeout(4000);
            $req->setBodySizeLimit(1024 * 1024 * 8);
            /** @var Response $response */
            $response = yield $client->request($req);
            $body = yield $response->getBody()->buffer();
            if ($response->getStatus() != 200) {
                logUrl($bot, $nick, $chan, $text, "Err: {$response->getStatus()} {$response->getReason()}");
                continue;
            }

            if(preg_match("@^image/(.*)$@i", $response->getHeader("content-type"), $m)) {
                $size = $response->getHeader("content-length");
                $size = \knivey\tools\convert($size);
                $d = getimagesizefromstring($body);
                if(!$d) {
                    $out = "[ $m[1] image $size ]";
                } else {
                    $out = "[ $m[1] image $size $d[0]x$d[1] ]";
                }
                $bot->pm($chan, "  $out");
                logUrl($bot, $nick, $chan, $text, $out);
                continue;
            }
            if(preg_match("@^video/(.*)$@i", $response->getHeader("content-type"), $m)) {
                $size = $response->getHeader("content-length");
                $size = \knivey\tools\convert($size);

                if(!`which mediainfo`) {
                    echo "mediainfo not found, only giving basic url info\n";
                    $out = "[ $m[1] {$response->getHeader("content-type")} ]";
                    $bot->pm($chan, "  $out");
                    logUrl($bot, $nick, $chan, $text, $out);
                    continue;
                }
                $fn = "tmp_" . bin2hex(random_bytes(8)) . ".{$m[1]}";
                file_put_contents($fn, $body);
                $mi = simplexml_load_string(`mediainfo $fn --Output=XML`);
                unlink($fn);

                if(!isset($mi->media) || !isset($mi->media->track)) {
                    echo "linktitles video error\n";
                    var_dump($mi);
                }
                $vt = null;
                $at = null;
                foreach($mi->media->track as $track) {
                    if($track['type'] == 'Video')
                        $vt = $track;
                    if($track['type'] == 'Audio')
                        $at = $track;
                }
                $videoFormat = $vt->Format;
                if(isset($vt->FrameRate))
                    $frameRate = round((float)$vt->FrameRate) . 'fps';
                else
                    $frameRate = $vt->FrameRate_Mode ?? '?';


                $resX = $vt->Width ?? '?';
                $resY = $vt->Height ?? '?';

                if(isset($vt->Duration)) {
                    $dur = Duration_toString(round((float)$vt->Duration)) . ' long';
                } else {
                    $dur = 'unknown duration';
                }

                if($at == null) {
                    $audio = "No audio track";
                } else {
                    $audio = "{$at->Format} audio";
                }


                $out = "[ $dur $m[1] video ({$videoFormat}) $size {$resX}x{$resY} @ {$frameRate}, $audio ]";
                $bot->pm($chan, "  $out");
                logUrl($bot, $nick, $chan, $text, $out);
                continue;
            }

            if(!preg_match("/<title[^>]*>([^<]+)<\/title>/im", $body, $m)) {
                logUrl($bot, $nick, $chan, $text, "Err: No <title>");
                continue;
            }

            $title = strip_tags($m[1]);
            $title = html_entity_decode($title,  ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $title = htmlspecialchars_decode($title);
            $title = str_replace("\n", " ", $title);
            $title = str_replace("\r", " ", $title);
            $title = str_replace("\x01", "[CTCP]", $title);
            $title = substr(trim($title), 0, 300);
            $bot->pm($chan, "  [ $title ]");
            logUrl($bot, $nick, $chan, $text, "[ $title ]");
        } catch (\Exception $error) {
            logUrl($bot, $nick, $chan, $text, "Err: {$error->getMessage()}");
            echo "Link titles exception: {$error->getMessage()}\n";
        }
    }
}





function urlIsIgnored($chan, $nick, $host, $url): bool {
    /** @var \PDO $url_pdo */
    global $url_pdo;
    $stmt = $url_pdo->prepare("select regex from chan_re where chan = :chan;");
    if(!$stmt->execute([":chan" => $chan])) {
        echo "URL chan ignore list fetch FAILED\n";
    } else {
        $iggys = $stmt->fetchAll();
        foreach ($iggys as $iggy) {
            if (preg_match($iggy['regex'], $url)) {
                return true;
            }
        }
    }
    $stmt = $url_pdo->prepare("select * from userhosts;");
    if(!$stmt->execute()) {
        echo "URL userhost ignore list fetch FAILED\n";
        return false;
    }
    $iggys = $stmt->fetchAll();
    foreach ($iggys as $iggy) {
        $n = \knivey\tools\globToRegex($iggy['nick']) . 'i';
        $h = \knivey\tools\globToRegex($iggy['host']) . 'i';
        //$a = \knivey\tools\globToRegex($iggy['auth']) . 'i';
        if(preg_match($iggy['ignore_re'], $url) && preg_match($n, $nick) && preg_match($h, $host) /* && preg_match($auth, $a) */) {
            return true;
        }
    }
    return false;
}

function fmtRow($row) {
    $out = '';
    foreach ($row as $k => $v) {
        if(is_integer($k))
            continue;
        $out .= "\x02$k:\x02 $v ";
    }
    return substr($out, 0, -1);
}

#[Cmd("urlignore")]
#[Syntax('[regex_or_id]')]
#[CallWrap("Amp\asyncCall")]
#[Options("--list", "--del")]
function urlignore($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    global $url_pdo;

    try {
        $access = yield getUserChanAccess($args->nick, $args->chan, $bot);
        if ($access < 200) {
            $bot->notice($args->nick, "you need at least 200 chanserv access");
            return;
        }
    } catch (\Exception $e) {
        $bot->notice($args->nick, "couldn't get your chanserv access, try later");
    }

    /** @var $url_pdo \PDO */
    if($req->args->getOpt("--list") || ! isset($req->args[0])) {
        try {
            $stmt = $url_pdo->prepare("select * from chan_re where chan = :chan;");
            $stmt->execute([":chan" => $args->chan]);
            if(!$stmt->execute([":chan" => $args->chan])) {
                $bot->notice($args->nick, "Failed getting ignore list");
                return;
            }
            $iggys = $stmt->fetchAll();
            $bot->notice($args->nick, "Showing " . count($iggys) . " ignores for {$args->chan}");
            foreach ($iggys as $row) {
                $bot->notice($args->nick, fmtRow($row));
            }
        } catch (\Exception $e) {
            $bot->notice($args->nick, "Encountered an exception: {$e->getMessage()}");
        }
        return;
    }
    if($req->args->getOpt("--del")) {
        try {
            $stmt = $url_pdo->prepare("select id from chan_re where id = :id and chan = :chan;");
            $stmt->execute([":id" => $req->args[0], ":chan" => $args->chan]);
            if(count($stmt->fetchAll()) == 0) {
                $bot->notice($args->nick, "No ignores removed, check the provided ID is correct and is from this chan.");
                return;
            }
            $stmt = $url_pdo->prepare("delete from chan_re where id = :id and chan = :chan;");
            if($stmt->execute([":id" => $req->args[0], ":chan" => $args->chan])) {
                $bot->notice($args->nick, "Ignore ID {$req->args[0]} removed");
            } else {
                $bot->notice($args->nick, "No ignores removed, check the provided ID is correct and is from this chan.");
            }
        } catch (\Exception $e) {
            $bot->notice($args->nick, "Encountered an exception: {$e->getMessage()}");
        }
        return;
    }
    $re = "@{$req->args[0]}@i";
    if(preg_match($re, "") === false) {
        $bot->notice($args->nick, "You haven't provided a valid regex, note delimiters are added for you and its @");
        return;
    }
    try {
        $auth = null;
        try {
            $auth = yield getUserAuthServ($args->nick, $bot);
        } catch (\Exception $e) {
        }
        $stmt = $url_pdo->prepare("insert into chan_re (regex,chan,nick,host,auth) values(:regex,:chan,:nick,:host,:auth);");
        if($stmt->execute([
            ":regex" => $re,
            ":chan" => $args->chan,
            ":nick" => $args->nick,
            ":host" => $args->fullhost,
            ":auth" => $auth,
            ])) {
            $bot->notice($args->nick, "Ignore added.");
        }
    } catch (\Exception $e) {
        $bot->notice($args->nick, "Encountered an exception: {$e->getMessage()}");
    }
}

