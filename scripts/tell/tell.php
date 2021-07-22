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
    global $disabled, $config;
    if($disabled) {
        $bot->pm($args->chan, "telldb not configured");
        return;
    }
    if(strtolower($bot->getNick()) == strtolower($req->args['nick'])) {
        $bot->pm($args->chan, "Ok I'll pass that off to /dev/null");
        return;
    }
    $max = $config['tell_max_inbox'] ?? 10;
    if(countMsgs($req->args['nick']) >= $max) {
        $bot->pm($args->chan, "Sorry, {$req->args[0]}'s inbox is stuffed full :( (limit of $max messages)");
        return;
    }
    addMsg($req->args['nick'], $args->text, $args->nick, $bot->getOption('NETWORK', 'UnknownNet'), $args->chan);
    $bot->pm($args->chan, "Ok, I'll tell {$req->args[0]} that next time I see them.");
}

function countMsgs($nick) {
    $nick = strtolower($nick);
    R::selectDatabase('telldb');
    $msgs = R::findAll("msg", " `to` = ? AND `sent` = 0 ", [$nick]);
    return count($msgs);
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
        $max = $config['tell_max_tochan'] ?? 5;
        $cnt = 0;
        foreach ($msgs as &$msg) {
            try {
                $seconds = time() - (new \DateTime($msg->date))->getTimestamp();
                $duration = (new Duration($seconds))->humanize();

            } catch(\Exception $e) {
                $duration = $msg->date;
            }
            $sendMsg = "{$duration} ago ";
            $net = $bot->getOption('NETWORK', 'UnknownNet');
            if(strtolower($args->chan) != strtolower($msg->chan))
                $sendMsg .= "in {$msg->chan} ";
            if(strtolower($net) != strtolower($msg->net))
                $sendMsg .= "on {$msg->network} ";
            $sendMsg .= " <{$msg->from}> {$msg->msg}";
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