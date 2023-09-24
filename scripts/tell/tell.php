<?php
namespace scripts\tell;
/*
 * script for our .tell nick blah blah and .ask nick blah blah commands
 */

use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Desc;
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

// if we are allowing multiple networks to share a telldb to send between them
$multiNet = $config['tell_multinet'] ?? false;

if ($multiNet) {
    R::selectDatabase('telldb');
    R::exec("PRAGMA synchronous=FULL;");

    #[Cmd("gtell", "gask", "ginform")]
    #[Syntax("<nick> <msg>...")]
    function gtell($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        global $disabled, $config;
        if ($disabled) {
            $bot->pm($args->chan, "telldb not configured");
            return;
        }
        if (strtolower($bot->getNick()) == strtolower($cmdArgs['nick'])) {
            $bot->pm($args->chan, "Ok I'll pass that off to /dev/null");
            return;
        }
        $max = $config['tell_max_inbox'] ?? 10;
        $net = $bot->getOption('NETWORK', 'UnknownNet');
        if ((countMsgs($cmdArgs['nick'], $net, true) >= $max) && !strcasecmp($cmdArgs['nick'], 'terps')) {
            $bot->pm($args->chan, "Sorry, {$cmdArgs[0]}'s inbox is stuffed full :( (limit of $max messages)");
            return;
        }

        $action = explode(" ", $args->text)[0];
        $action = str_replace(".", "", $action);

        addMsg($cmdArgs['nick'], $args->text, $args->nick, $net, $args->chan);
        $bot->pm($args->chan, actionMsg($action, $cmdArgs['nick']));
    }
}

#[Cmd("tell", "ask", "inform", "pester")]
#[Desc("pass nick a message when they chat next")]
#[Syntax("<nick> <msg>...")]
function tell($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
    global $disabled, $config, $multiNet;
    if($disabled) {
        $bot->pm($args->chan, "telldb not configured");
        return;
    }
    if(strtolower($bot->getNick()) == strtolower($cmdArgs['nick'])) {
        $bot->pm($args->chan, "Ok I'll pass that off to /dev/null");
        return;
    }
    $net = $bot->getOption('NETWORK', 'UnknownNet');
    $max = $config['tell_max_inbox'] ?? 10;
    if(countMsgs($cmdArgs['nick'], $net) >= $max) {
        $bot->pm($args->chan, "Sorry, {$cmdArgs[0]}'s inbox is stuffed full :( (limit of $max messages)");
        return;
    }

    $action = explode(" ", $args->text)[0];
    $action = str_replace(".", "", $action);

    addMsg($cmdArgs['nick'], $args->text, $args->nick, $net, $args->chan, $net);
    if($multiNet)
        $bot->pm($args->chan, actionMsg($action, $cmdArgs['nick']));
    else
        $bot->pm($args->chan, actionMsg($action, $cmdArgs['nick']));
}

function actionMsg($action, $nick) {

    $messages = [
       "tell" => [
           "I'll definitely tell $nick about that",
           "I'll make sure to let $nick know about that",
           "I'll be sure to relay that message to $nick",
           "U dare tell me to tell $nick about that? Ok I will",
           "Telling me to tell $nick? Aite",
           "Yeah OK, I'll 'totally tell' $nick about that",

       ],
       "inform" => [
           "$nick will be informed of dat next time they chat",
           "$nick will be kept in the loop regarding that",
           "$nick will be made aware of that information",
           "I will inform the shit outta $nick regarding this very important thing",
           "I am informed to inform $nick and I will carry out this duty",
           "How dare u inform me to inform $nick? Fine! I will!",
       ],
       "ask" => [
           "I'll be sure to ask $nick about that!",
           "I'll make sure to get that information from $nick",
           "I'll be sure to find out the answer to that question from $nick",
           "U dare ask me to ask $nick about that? Ok I will",
           "I will not enjoy asking, but I will ask $nick about that",
       ],
       "pester" => [
           "Leave it to me, I'll pester the crap out of $nick regarding that",
           "I'll be sure to annoy $nick until they give me the information I need",
           "I'll be relentless in my pursuit of information from $nick",
           "I will not rest until $nick is pestered about that",
           "U pester me to pester $nick? Ok I will",
           "I accept this burden of pestering $nick",
       ],
    ];

    switch ($action) {
    case "tell":
        return $messages['tell'][array_rand($messages['tell'])];
    case "inform":
        return $messages['inform'][array_rand($messages['inform'])];
    case "ask":
        return $messages['ask'][array_rand($messages['ask'])];
    case "pester":
        return $messages['pester'][array_rand($messages['pester'])];
    default:
        return "uhhh";
    }
}

function countMsgs($nick, $net, $global = false) {
    $nick = strtolower($nick);
    R::selectDatabase('telldb');
    $msgs = R::findAll("msg", " `to` = ? AND `sent` = 0 ", [$nick]);
    $cnt = 0;
    if(!$global) {
        //doing it this way because schema may be old
        foreach ($msgs as $msg) {
            if(!isset($msg->to_net) || $msg->to_net == "") {
                $cnt++;
                continue;
            }
            if($msg->to_net == $net)
                $cnt++;
        }
    } else {
        foreach ($msgs as $msg) {
            if(isset($msg->to_net) && $msg->to_net != "" && $msg->to_net != $net) {
                continue;
            }
            $cnt++;
        }
    }
    return $cnt;
}

function addMsg($nick, $msg, $from, $network, $chan, $toNet = null) {
    R::selectDatabase('telldb');
    $msgb = R::dispense("msg");
    $msgb->date = R::isoDateTime();
    $msgb->from = $from;
    $msgb->msg = $msg;
    $msgb->to = strtolower($nick);
    $msgb->sent = 0;
    $msgb->network = $network;
    $msgb->chan = $chan;
    $msgb->to_net = $toNet;
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
            $net = $bot->getOption('NETWORK', 'UnknownNet');
            if(isset($msg->to_net) && $msg->to_net != "" && $msg->to_net != $net)
                continue;
            try {
                $seconds = time() - (new \DateTime($msg->date))->getTimestamp();
                $duration = (new Duration($seconds))->humanize();

            } catch(\Exception $e) {
                $duration = $msg->date;
            }
            $sendMsg = "{$duration} ago ";
            if(strtolower($args->chan) != strtolower($msg->chan))
                $sendMsg .= "in {$msg->chan} ";
            if(strtolower($net) != strtolower($msg->network))
                $sendMsg .= "on {$msg->network} ";
            $sendMsg .= " <{$msg->from}> {$msg->msg}";
            //TODO if we can't send to channel (+m or +b), PM them
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
