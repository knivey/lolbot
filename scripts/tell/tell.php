<?php
namespace knivey\lolbot\scripts\tell;
/*
 * script for our .tell nick blah blah and .ask nick blah blah commands
 */

use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;

use Khill\Duration\Duration;

use \RedBeanPHP\R as R;
global $config;
if(isset($config['telldb'])) {
    if(!file_exists($config['telldb']))
        echo "telldb does not exists yet\n";
    R::addDatabase('telldb', "sqlite:{$config['telldb']}");
} else {
    $disabled=true;
}

#[Cmd("tell", "ask", "inform")]
#[Syntax("<nick> <msg>...")]
function tell($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    global $disabled;
    if($disabled) {
        $bot->pm($args->chan, "telldb not configured");
        return;
    }
    addMsg($req->args['nick'], $args->text, $args->nick, $bot->getOption('NETWORK', 'UnknownNet'), $args->chan);
    $bot->pm($args->chan, "Ok, I'll tell {$req->args[0]} that next time I see them.");
}

function addMsg($nick, $msg, $from, $network, $chan) {
    R::selectDatabase('telldb');
    $msgb = R::dispense("msg");
    $msgb->date = R::isoDateTime();
    $msgb->from = $from;
    $msgb->msg = $msg;
    $msgb->to = strtolower($nick);
    $msgb->sent = 0;
    $msgb->network = $network;
    $msgb->chan = $chan;
    R::store($msgb);
    echo "msg added to db\n";
}

function initTell($bot) {
    global $disabled;
    if($disabled)
        return;
    $bot->on('chat', function ($args, \Irc\Client $bot) {
        global $config;
        $nick = strtolower($args->from);
        $chan = $args->chan;
        R::selectDatabase('telldb');
        $msgs = R::findAll("msg", " `to` = ? AND `sent` = 0 ", [$nick]);
        if(!is_array($msgs) || count($msgs) == 0)
            return;
        $max = $config['tell_max'] ?? 5;
        $cnt = 0;
        foreach ($msgs as &$msg) {
            try {
                $seconds = time() - (new \DateTime($msg->date))->getTimestamp();
                $duration = (new Duration($seconds))->humanize();

            } catch(\Exception $e) {
                $duration = $msg->date;
            }
            $sendMsg = "{$duration} ago in {$msg->chan} on {$msg->network}: <{$msg->from}> {$msg->msg}";
            if(++$cnt > $max) {
                $bot->pm($msg->to, $sendMsg);
            } else {
                $bot->pm($chan, $sendMsg);
            }
            if($cnt == $max && count($msgs) > $max) {
                $bot->pm($chan, (count($msgs) - $max) . " further messages will be sent privately");
            }
            $msg->sent = 1;
            R::store($msg);
        }
    });
}