<?php
namespace scripts\remindme;

use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;

use Khill\Duration\Duration;

use \RedBeanPHP\R as R;
global $config;
const REMINDERDB = "reminderdb";
$dbfile = $config[REMINDERDB] ?? "reminder.db";
R::addDatabase(REMINDERDB, "sqlite:{$dbfile}");

#[Cmd("in", "remindme")]
#[Syntax("<time> <msg>...")]
function in($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    \Amp\asyncCall(function () use ($args, $bot, $req) {
        $duration = new Duration($req->args['time']);
        //Because the Duration lib is PECULIAR it resets its value to 0 after doing outputs
        //And also lingers data in the output, really i need to find another lib
        $in = $duration->toSeconds();

        if($in < 15) {
            $bot->pm($args->chan, "Give me a proper duration of at least 15 seconds with no spaces (Ex: 1h10m15s or 1:10:15)");
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

        $bot->pm($args->chan, "Ok, I'll remind you in " . (new Duration($in))->humanize());
        yield from sendDelayed($bot, $r, $in);
    });
}

/* TODO, this actually needs to parse more than one word(arg) for the timestamp so its a bit complicated to do
Also need to consider user timezones
#[Cmd("at")]
#[Syntax("<time> <msg>...")]
function at($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    try {
        $dt = new \DateTime($req->args['time']);
    } catch (\Exception $e) {
        $bot->pm($args->chan, "That date time doesnt seem valid");
        return;
    }
    if($dt->getTimestamp() <= time() + 15) {
        $bot->pm($args->chan, "Give me a time at least 15 seconds from now");
        return;
    }
    $in = $dt->getTimestamp() - time();
    $duration = new Duration($in);
    R::selectDatabase(REMINDERDB);
    $r = R::dispense("reminder");
    $r->nick = $args->nick;
    $r->chan = $args->chan;
    $r->at = $dt->getTimestamp();
    $r->sent = 0;
    $r->msg = $req->args['msg'];
    R::store($r);
    $bot->pm($args->chan, "Ok, I'll remind you in " . $duration->humanize());
    sendDelayed($bot, $r, $in);
}
*/
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
                $ago = (new Duration(time() - $r->at))->humanize();
                $bot->pm($r->chan, "[REMINDER: {$r->nick} (late by $ago)] {$r->msg}");
                $r->sent = 1;
                R::store($r);
            } else {
                yield from sendDelayed($bot, $r, $r->at - time());
            }
        }
    });
}
