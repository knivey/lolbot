<?php
namespace scripts\lastfm;

require_once 'library/Duration.inc';

use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Desc;
use knivey\cmdr\attributes\Options;
use knivey\cmdr\attributes\Syntax;
use \RedBeanPHP\R as R;


global $config;
$db = 'lastfm-' . uniqid();
$dbfile = $config['lastfmdb'] ?? "lastfm.db";
R::addDatabase($db, "sqlite:{$dbfile}");

#[Cmd("setlastfm")]
#[Syntax('<username>')]
#[Desc("Set your last.fm name for the bot to remember for np")]
#[CallWrap("Amp\asyncCall")]
function setlastfm($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    global $config, $db;
    $key = $config['lastfm'] ?? false;
    if(!$key) {
        echo "lastfm key not set on config\n";
        return;
    }
    $user = urlencode($cmdArgs['username']);
    $url = "http://ws.audioscrobbler.com/2.0/?method=user.getrecenttracks&user=$user&api_key=$key&format=json&limit=1";
    try {
        $body = yield async_get_contents($url);
    } catch (\Exception $error) {
        echo $error->getMessage();
        $bot->pm($args->chan, "\2setlastfm:\2 couldn't lookup that username");
        return;
    }
    R::selectDatabase($db);
    $ent = R::findOneOrDispense("lastfm", " `host` = ? ", [$args->identhost]);
    $ent->username = $cmdArgs['username'];
    $ent->host = $args->identhost;
    R::store($ent);
    $bot->pm($args->chan, "\2setlastfm:\2 username saved for your host");
}

#[Cmd("np")]
#[Desc("Show your nowplaying status from last.fm")]
#[CallWrap("Amp\asyncCall")]
function np($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    global $config, $db;
    $key = $config['lastfm'] ?? false;
    if(!$key) {
        echo "lastfm key not set on config\n";
        return;
    }

    if (isset($cmdArgs['user'])) {
        $user = $cmdArgs['user'];
    } else {
        R::selectDatabase($db);
        $user = R::findOne("lastfm", " `host` = ? ", [$args->identhost]);
        if($user) {
            $user = $user->username;
        } else {
            $user = $args->nick;
        }
    }
    $user = urlencode($user);
    $url = "http://ws.audioscrobbler.com/2.0/?method=user.getrecenttracks&user=$user&api_key=$key&format=json&limit=1";
    try {
        $body = yield async_get_contents($url);
    } catch (\async_get_exception $error) {
        echo $error;
        $bot->pm($args->chan, "\2lastfm:\2 {$error->getIRCMsg()}");
        return;
    } catch (\Exception $error) {
        echo $error->getMessage();
        $bot->pm($args->chan, "\2lastfm:\2 {$error->getMessage()}");
        return;
    }
    $res = json_decode($body, true);
    if(!isset($res['recenttracks']['track'][0])) {
        $bot->pm($args->chan, "Failed to find any recent tracks.");
        return;
    }
    $track = $res['recenttracks']['track'][0];
    $title = $track['name'] ?? 'No Title';
    $artist = $track['artist']['#text'] ?? 'Unknown Artist';
    $album = $track['album']['#text'] ?? 'Unknown Album';
    if(isset($track['date']['uts'])) {
        $bot->pm($args->chan, "{$args->nick} np: not currently scrobbling");
        return;
    }
    $bot->pm($args->chan, "{$args->nick} np: $title - $album - $artist");
}

#[Cmd("lastfm")]
#[Syntax('[user]')]
#[Desc("Lookup a user on last.fm")]
#[CallWrap("Amp\asyncCall")]
#[Options("--info")]
function lastfm($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    global $config, $db;
    $key = $config['lastfm'] ?? false;
    if(!$key) {
        echo "lastfm key not set on config\n";
        return;
    }

    if (isset($cmdArgs['user'])) {
        $user = $cmdArgs['user'];
    } else {
        R::selectDatabase($db);
        $user = R::findOne("lastfm", " `host` = ? ", [$args->identhost]);
        if($user) {
            $user = $user->username;
        } else {
            $user = $args->nick;
        }
    }

    $user = urlencode($user);
    $url = "http://ws.audioscrobbler.com/2.0/?method=user.getrecenttracks&user=$user&api_key=$key&format=json&limit=1";
    try {
        $body = yield async_get_contents($url);
    } catch (\async_get_exception $error) {
        echo $error;
        $bot->pm($args->chan, "\2lastfm:\2 {$error->getIRCMsg()}");
        return;
    } catch (\Exception $error) {
        echo $error->getMessage();
        $bot->pm($args->chan, "\2lastfm:\2 {$error->getMessage()}");
        return;
    }
    $res = json_decode($body, true);
    if(!isset($res['recenttracks']['track'][0])) {
        $bot->pm($args->chan, "Failed to find any recent tracks.");
        if($cmdArgs->getOpt("--info")) {
            goto findinfo;
        }
        return;
    }
    //Fix case :)
    $user = $res['recenttracks']['@attr']['user'] ?? $user;
    $track = $res['recenttracks']['track'][0];
    $title = $track['name'] ?? 'No Title';
    $artist = $track['artist']['#text'] ?? 'Unknown Artist';
    $album = $track['album']['#text'] ?? 'Unknown Album';
    $time = 'scrobbling now';
    if(isset($track['date']['uts'])) {
        $ago = time() - $track['date']['uts'];
        $dur = Duration_int2array($ago);
        $ago = Duration_array2string(array_slice($dur, 0, 3), 1);
        $time = "last scrobbled $ago ago";
    }
    $bot->pm($args->chan, "\2last.fm:\2 $user $time: $title - $album - $artist");

    if(!$cmdArgs->getOpt("--info")) {
        return;
    }

    findinfo:
    $url = "http://ws.audioscrobbler.com/2.0/?method=user.getinfo&user=$user&api_key=$key&format=json&limit=1";
    try {
        $body = yield async_get_contents($url);
    } catch (\async_get_exception $error) {
        echo $error;
        $bot->pm($args->chan, "\2lastfm:\2 {$error->getIRCMsg()}");
        return;
    } catch (\Exception $error) {
        echo $error->getMessage();
        $bot->pm($args->chan, "\2lastfm:\2 {$error->getMessage()}");
        return;
    }
    $res = json_decode($body, true);

    $res = $res['user'];
    $regged = strftime("%c", $res['registered']['unixtime']);
    $bot->pm($args->chan, "\2`-userinfo:\2 {$res['url']} ({$res['realname']}) PlayCount: {$res['playcount']} Regged: $regged Country: ".
        "{$res['country']} Age: {$res['age']} GENDER: {$res['gender']}");
}
