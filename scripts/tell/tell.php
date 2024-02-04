<?php
namespace scripts\tell;
/*
 * script for our .tell nick blah blah and .ask nick blah blah commands
 */

use Doctrine\Common\Collections\Criteria;
use Irc\Exception;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Desc;
use knivey\cmdr\attributes\Syntax;

use Khill\Duration\Duration;

use scripts\script_base;
use function Symfony\Component\String\u;

class tell extends script_base {
    //#[Cmd("gtell", "gask", "ginform")]
    //#[Syntax("<nick> <msg>...")]
    function gtell($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        global $config;

        if (strtolower($bot->getNick()) == strtolower($cmdArgs['nick'])) {
            $bot->pm($args->chan, "Ok I'll pass that off to /dev/null");
            return;
        }
        $max = $config['tell_max_inbox'] ?? 10;
        if (($this->countMsgs($cmdArgs['nick'], true) >= $max) && !strcasecmp($cmdArgs['nick'], 'terps')) {
            $bot->pm($args->chan, "Sorry, {$cmdArgs[0]}'s inbox is stuffed full :( (limit of $max messages)");
            return;
        }

        //TODO fix this to use the bots actual set trigger
        $action = explode(" ", $args->text)[0];
        $action = str_replace(".", "", $action);

        $this->addMsg($cmdArgs['nick'], $args->text, $args->nick, $args->chan, true);
        $bot->pm($args->chan, $this->actionMsg($action, $cmdArgs['nick']));
    }

    #[Cmd("tell", "ask", "inform", "pester")]
    #[Desc("pass nick a message when they chat next")]
    #[Syntax("<nick> <msg>...")]
    function tell($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
        global $config;
        try {
            if (strtolower($bot->getNick()) == strtolower($cmdArgs['nick'])) {
                $bot->pm($args->chan, "Ok I'll pass that off to /dev/null");
                return;
            }
            $max = $config['tell_max_inbox'] ?? 10;
            if ($this->countMsgs($cmdArgs['nick']) >= $max) {
                $bot->pm($args->chan, "Sorry, {$cmdArgs[0]}'s inbox is stuffed full :( (limit of $max messages)");
                return;
            }

            //TODO fix this to use the bots actual set trigger
            $action = explode(" ", $args->text)[0];
            $action = str_replace(".", "", $action);

            $this->addMsg($cmdArgs['nick'], $args->text, $args->nick, $args->chan);
            $bot->pm($args->chan, $this->actionMsg($action, $cmdArgs['nick']));
        } catch (\Exception $e) {
            $bot->msg($args->chan, "Error with adding tell :(");
            $this->logger->error($e);
        }
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

        switch (strtolower($action)) {
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

    function countMsgs($nick, $global = false): int {
        global $entityManager;
        $nick = u($nick)->lower();
        $criteria = Criteria::create();
        $criteria->where(Criteria::expr()->eq("sent", false));
        $criteria->andWhere(Criteria::expr()->eq("target", $nick));
        $criteria->andWhere(Criteria::expr()->eq("network", $this->network));
        if($global)
            $criteria->orWhere(Criteria::expr()->eq("global", true));
        return $entityManager->getRepository(entities\tell::class)->matching($criteria)->count();
    }

    function addMsg($nick, $msg, $from, $chan, $global = false) {
        global $entityManager;
        $tell = new entities\tell();
        $tell->sender = $from;
        $tell->msg = $msg;
        $tell->target = u($nick)->lower();
        $tell->network = $this->network;
        $tell->chan = $chan;
        $tell->global = $global;
        $entityManager->persist($tell);
        $entityManager->flush();
        $this->logger->info("msg added to db");
    }

    function init():void {
        $this->client->on('chat', function ($args, \Irc\Client $bot) {
            global $config, $entityManager;
            $nick = u($args->from)->lower();
            //TODO possibly cache this
            $msgs = $entityManager->getRepository(entities\tell::class)->findBy(["sent"=>false,"target"=>$nick]);
            if(!is_array($msgs) || count($msgs) == 0)
                return;
            $max = $config['tell_max_tochan'] ?? 5;
            $cnt = 0;
            foreach ($msgs as $msg) {
                if(!$msg->global && $msg->network->id != $this->network->id)
                    continue;
                try {
                    $seconds = time() - $msg->created->getTimestamp();
                    $duration = (new Duration($seconds))->humanize();

                } catch(\Exception $e) {
                    $duration = $msg->created->format("%D %T");
                }
                $sendMsg = "{$duration} ago ";
                if(strtolower($args->chan) != strtolower($msg->chan))
                    $sendMsg .= "in {$msg->chan} ";
                if($msg->network->id != $this->network->id)
                    $sendMsg .= "on {$msg->network} ";
                $sendMsg .= " <{$msg->sender}> {$msg->msg}";
                //TODO if we can't send to channel (+m or +b), PM them
                if(++$cnt > $max) {
                    $bot->pm($msg->target, $sendMsg);
                } else {
                    $bot->pm($args->chan, $sendMsg);
                }
                if($cnt == $max && count($msgs) > $max) {
                    $bot->pm($args->chan, (count($msgs) - $max) . " further messages will be sent privately");
                }
                $msg->sent = true;
                $entityManager->persist($msg);
                $entityManager->flush();
            }
        });
    }
}