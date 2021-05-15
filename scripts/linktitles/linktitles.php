<?php

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Options;
use knivey\cmdr\attributes\Syntax;

require_once 'github.php';

// db is used for ignoring urls from userhosts or per channel URL regex ignores
$dbfile = "linktitles.db";
/*
 * Just keeping things relatively simple, no migration tool
 */
if(!file_exists($dbfile)) {
    $url_pdo = new \PDO("sqlite:$dbfile", null, null,
        [\PDO::ATTR_PERSISTENT => true, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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

$link_history = [];
$link_ratelimit = 0;
function linktitles(\Irc\Client $bot, $chan, $text)
{
    global $link_history, $link_ratelimit;
    foreach(explode(' ', $text) as $word) {
        if (filter_var($word, FILTER_VALIDATE_URL) === false) {
            continue;
        }
        //Skip youtubes
        $URL = '/^((?:https?:)?\/\/)?((?:www|m)\.)?((?:youtube\.com|youtu.be))(\/(?:[\w\-]+\?v=|embed\/|v\/)?)([\w\-]+)(\S+)?$/';
        if (preg_match($URL, $word)) {
            continue;
        }

        if(urlIsIgnored($chan, $word))
            continue;

        if(($link_history[$chan] ?? "") == $word) {
            continue;
        }
        $link_history[$chan] = $word;

        if(time() < $link_ratelimit) {
            return;
        }
        $link_ratelimit = time() + 2;

        //Handle github user or project
        if(preg_match("@^https?://(?:www\.)?github\.com/([^/]+)(?:/([^/]+))?/?$@i", $word, $m)) {
            $user = $m[1];
            $repo = $m[2] ?? null;
            if($out = yield from github($user, $repo)) {
                $bot->pm($chan, $out);
                continue;
            }
        }

        try {
            $client = HttpClientBuilder::buildDefault();
            $req = new Request($word);
            $req->setTransferTimeout(4000);
            $req->setBodySizeLimit(1024 * 1024 * 8);
            /** @var Response $response */
            $response = yield $client->request($req);
            $body = yield $response->getBody()->buffer();
            if ($response->getStatus() != 200) {
                $bot->pm($chan, "LinkTitles Error (" . $response->getStatus() . ") " . substr($body, 0, 200));
                //var_dump($body);
                return;
            }

            if(!preg_match("/<title[^>]*>([^<]+)<\/title>/im", $body, $m)) {
                continue;
            }

            $title = strip_tags($m[1]);
            $title = html_entity_decode($title,  ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $title = htmlspecialchars_decode($title);
            $title = str_replace("\n", " ", $title);
            $title = str_replace("\r", " ", $title);
            $title = str_replace("\x01", "[CTCP]", $title);
            $title = substr(trim($title), 0, 300);
            $bot->pm($chan, "[ $title ]");
        } catch (\Exception $error) {
            echo "Link titles exception: $error\n";
        }
    }
}

function urlIsIgnored($chan, $url): bool {
    global $url_pdo;
    $stmt = $url_pdo->prepare("select regex from chan_re where chan = :chan;");
    $stmt->execute([":chan" => $chan]);
    if(!$stmt->execute([":chan" => $chan])) {
        echo "URL ignore list fetch FAILED\n";
        return false;
    }
    $iggys = $stmt->fetchAll();
    foreach ($iggys as $iggy) {
        if(preg_match($iggy['regex'], $url)) {
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
            ;
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

