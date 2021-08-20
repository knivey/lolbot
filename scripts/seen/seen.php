<?php

namespace scripts\seen;
/*
 * script for our .seen nick
 * tracks when users were last chatting
 */

use Carbon\CarbonInterface;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;
use Carbon\Carbon;
use \RedBeanPHP\R as R;


global $config;
$seendb = 'seendb-' . uniqid();
$dbfile = $config['seendb'] ?? "seen.db";
R::addDatabase($seendb, "sqlite:{$dbfile}");

#[Cmd("seen")]
#[Syntax("<nick>")]
function seen($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    global $seendb;
    $nick = strtolower($req->args['nick']);
    if($nick == strtolower($bot->getNick())) {
        $bot->pm($args->chan, "I'm here bb");
        return;
    }
    R::selectDatabase($seendb);
    $seen = R::findOne("seen", " `nick` = ? ", [$nick]);
    if($seen == null) {
        $bot->pm($args->chan, "I've never seen $nick in my whole life");
        return;
    }
    try {
        $ago = (new Carbon($seen->time))->diffForHumans(Carbon::now(), CarbonInterface::DIFF_RELATIVE_TO_NOW, false, 3);
    } catch (\Exception $e) {
        echo $e->getMessage();
        $ago = "??? ago";
    }
    if($args->chan != $seen->chan) {
        $bot->pm($args->chan, "{$seen->orig_nick} was last active in another channel $ago");
        return;
    }
    $n = "<{$seen->orig_nick}>";
    if($seen->action == "action") {
        $n = "* {$seen->orig_nick}";
    }
    if($seen->action == "notice") {
        $n = "[{$seen->orig_nick}]";
    }
    $bot->pm($args->chan, "seen {$ago}: $n {$seen->text}");
}


function updateSeen(string $action, string $chan, string $nick, string $text) {
    global $seendb;
    $orig_nick = $nick;
    $nick = strtolower($nick);
    $chan = strtolower($chan);
    R::selectDatabase($seendb);
    //clean out the old entries for this nick
    $previous = R::findAll("seen", " `nick` = ? ", [$nick]);
    if(is_array($previous)) {
        R::trashAll($previous);
    }
    // add the new
    $ent = R::dispense("seen");
    $ent->nick = $nick;
    $ent->orig_nick = $orig_nick;
    $ent->chan = $chan;
    $ent->text = $text;
    $ent->action = $action;
    $ent->time = R::isoDateTime();
    R::store($ent);
}

function initSeen($bot) {
    $bot->on('notice', function ($args, \Irc\Client $bot){
        if (!$bot->isChannel($args->to))
            return;
        //ignore ctcp replies to channel
        if(preg_match("@^\x01.*$@i", $args->text))
            return;
        updateSeen('notice', $args->to, $args->from, $args->text);
    });

    $bot->on('chat', function ($args, \Irc\Client $bot) {
        if(preg_match("@^\x01ACTION ([^\x01]+)\x01?$@i", $args->text, $m)) {
            updateSeen('action', $args->chan, $args->from, $m[1]);
            return;
        }
        updateSeen('privmsg', $args->chan, $args->from, $args->text);
    });
}