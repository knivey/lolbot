<?php
namespace scripts\remindme;

require_once "library/Duration.inc";

use Carbon\Carbon;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;

use \RedBeanPHP\R as R;
use function knivey\tools\makeArgs;

global $config;
const REMINDERDB = "reminderdb";
$dbfile = $config[REMINDERDB] ?? "reminder.db";
R::addDatabase(REMINDERDB, "sqlite:{$dbfile}");

#[Cmd("in", "remindme")]
#[Syntax("<time> <msg>...")]
function in($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    \Amp\asyncCall(function () use ($args, $bot, $req) {
        $in = string2Seconds($req->args['time']);
        if(is_string($in)) {
            $bot->pm($args->chan, "Error: $in, Give me a proper duration of at least 15 seconds with no spaces using yMwdhms (Ex: 1h10m15s)");
            return;
        }
        if($in < 15) {
            $bot->pm($args->chan, "Give me a proper duration of at least 15 seconds with no spaces (Ex: 1h10m15s)");
            return;
        }

        R::selectDatabase(REMINDERDB);
        $r = R::dispense("reminder");
        $r->nick = $args->nick;
        $r->chan = $args->chan;
        $r->at = time() + $in;
        $r->sent = 0;
        $r->msg = $req->args['msg'];
        R::store($r);

        $bot->pm($args->chan, "Ok, I'll remind you in " . Duration_toString($in));
        yield from sendDelayed($bot, $r, $in);
    });
}

/*
 * Because cmdr doesnt yet support it, using \knivey\tools\makeArgs which makes args using "arg one" arg2 "arg\"3" etc
 */
// TODO let users save a timezone so they dont have always include it here
#[Cmd("at", "on")]
#[Syntax("<timemsg>...")]
function at($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    $r = makeArgs($req->args['timemsg']);
    if(!is_array($r) || count($r) < 2) {
        $bot->pm($args->chan, "Syntax: <datetime> <msg>  If datetime is more than one word put it inside quotes, you should include your timezone");
        $bot->pm($args->chan, "Example: .on \"next Friday EDT\" watch new JRH  <- Will trigger at 00:00");
        $bot->pm($args->chan, "Example: .at \"11pm EDT\" eat ice cream");
        return;
    }
    $time = array_shift($r);
    $msg = implode(' ', $r);

    try {
        $dt = new Carbon($time);
    } catch (\Exception $e) {
        $bot->pm($args->chan, "That date time ($time) isn't understood");
        return;
    }
    if($dt->getTimestamp() <= time() + 15) {
        $bot->pm($args->chan, "Give me a time at least 15 seconds in the future");
        return;
    }
    $in = $dt->getTimestamp() - time();
    R::selectDatabase(REMINDERDB);
    $r = R::dispense("reminder");
    $r->nick = $args->nick;
    $r->chan = $args->chan;
    $r->at = $dt->getTimestamp();
    $r->sent = 0;
    $r->msg = $msg;
    R::store($r);

    $fromNow = $dt->shortRelativeToNowDiffForHumans(Carbon::now(), 10);
    $bot->pm($args->chan, "Ok, I'll remind you on " . $dt->toCookieString() . " ($fromNow)");
    sendDelayed($bot, $r, $in);
}

function sendDelayed(\Irc\Client $bot, $r, $seconds) {
    yield \Amp\delay($seconds * 1000);
    //if bot somehow isnt connected keep retrying
    while (!$bot->isEstablished()) {
        yield \Amp\delay(10000);
    }
    $bot->pm($r->chan, "[REMINDER: {$r->nick}] {$r->msg}");
    $r->sent = 1;
    R::selectDatabase(REMINDERDB);
    R::store($r);
}


function initRemindme($bot) {
    static $inited = false;
    if($inited)
        return;
    $inited = true;
    \Amp\asyncCall(function () use ($bot) {
        //A bit of a hack here so we give the bot time to join channels etc
        yield \Amp\delay(5000);
        R::selectDatabase(REMINDERDB);
        //load our reminders from db and call sendDelayed on all
        $rs = R::findAll("reminder", " `sent` = 0 ");
        foreach ($rs as $r) {
            //whoops already passed while bot was down
            if ($r->at <= time()) {
                while (!$bot->isEstablished()) {
                    yield \Amp\delay(10000);
                }
                $ago = Duration_toString(time() - $r->at);
                $bot->pm($r->chan, "[REMINDER: {$r->nick} (late by $ago)] {$r->msg}");
                $r->sent = 1;
                R::store($r);
            } else {
                yield from sendDelayed($bot, $r, $r->at - time());
            }
        }
    });
}
