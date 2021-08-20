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
    saveSeens();
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

class seen {
    public $nick;
    public $orig_nick;
    public $chan;
    public $text;
    public $action;
    public $time;
}

$updates = [];

function updateSeen(string $action, string $chan, string $nick, string $text) {
    global $updates;
    $orig_nick = $nick;
    $nick = strtolower($nick);
    $chan = strtolower($chan);

    $ent = new seen();
    $ent->nick = $nick;
    $ent->orig_nick = $orig_nick;
    $ent->chan = $chan;
    $ent->text = $text;
    $ent->action = $action;
    $ent->time = R::isoDateTime();
    //Don't save yet, massive floods will destroy us with so many writes
    $updates[$nick] = $ent;
}

function saveSeens() {
    global $seendb, $updates;
    R::selectDatabase($seendb);
    foreach($updates as $ent) {
        //clean out the old entries for this nick
        $previous = R::findAll("seen", " `nick` = ? ", [$ent->nick]);
        if (is_array($previous)) {
            R::trashAll($previous);
        }
        $seen = R::dispense("seen");
        $seen->nick = $ent->nick;
        $seen->orig_nick = $ent->orig_nick;
        $seen->chan = $ent->chan;
        $seen->text = $ent->text;
        $seen->action = $ent->action;
        $seen->time = $ent->time;
        R::store($seen);
    }
}

function initSeen($bot) {
    \Amp\Loop::repeat(15000, __NAMESPACE__.'\saveSeens');

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