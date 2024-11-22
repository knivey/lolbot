<?php

namespace scripts\seen;
/*
 * script for our .seen nick
 * tracks when users were last chatting
 */

use Carbon\CarbonInterface;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Desc;
use knivey\cmdr\attributes\Syntax;
use Carbon\Carbon;
use scripts\script_base;
use function Symfony\Component\String\u;

class seen extends script_base
{
    #[Cmd("seen")]
    #[Desc("check when bot last saw someone chat")]
    #[Syntax("<nick>")]
    function seen($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        global $entityManager;
        $nick = u($cmdArgs['nick'])->lower();
        if ($nick == u($bot->getNick())->lower()) {
            $bot->pm($args->chan, "I'm here bb");
            return;
        }
        $this->saveSeens();

        $seen = $entityManager->getRepository(entities\seen::class)->findOneBy([
            "network" => $this->network,
            "nick" => $nick
        ]);
        if (!$seen) {
            $bot->pm($args->chan, "I've never seen {$cmdArgs['nick']} in my whole life");
            return;
        }
        $entityManager->refresh($seen);
        try {
            $ago = (new Carbon($seen->time))->diffForHumans(Carbon::now(), CarbonInterface::DIFF_RELATIVE_TO_NOW, true, 3);
        } catch (\Exception $e) {
            echo $e->getMessage();
            $ago = "??? ago";
        }
        if ($args->chan != $seen->chan) {
            $bot->pm($args->chan, "{$seen->orig_nick} was last active in another channel $ago");
            return;
        }
        $n = "<{$seen->orig_nick}>";
        if ($seen->action == "action") {
            $n = "* {$seen->orig_nick}";
        }
        if ($seen->action == "notice") {
            $n = "[{$seen->orig_nick}]";
        }
        if(is_string($seen->text))
            $text = $seen->text;
        else
            $text = stream_get_contents($seen->text);
        $bot->pm($args->chan, "seen {$ago}: $n {$text}");
    }



    private $updates = [];

    function updateSeen(string $action, string $chan, string $nick, string $text)
    {
        $orig_nick = $nick;
        $nick = strtolower($nick);
        $chan = strtolower($chan);

        $ent = new entities\seen();
        $ent->nick = $nick;
        $ent->orig_nick = $orig_nick;
        $ent->chan = $chan;
        $ent->text = $text;
        $ent->action = $action;
        $ent->network = $this->network;
        //Don't save yet, massive floods will destroy us with so many writes
        $this->updates[$nick] = $ent;
    }

    function saveSeens(): void
    {
        global $entityManager;
        foreach ($this->updates as $ent) {
            $previous = $entityManager->getRepository(entities\seen::class)->findOneBy([
                "network" => $this->network,
                "nick" => $ent->nick
            ]);
            if ($previous) {
                $previous->nick = $ent->nick;
                $previous->orig_nick = $ent->orig_nick;
                $previous->chan = $ent->chan;
                $previous->text = $ent->text;
                $previous->action = $ent->action;
                $previous->time = $ent->time;
                $entityManager->persist($previous);
            } else {
                $entityManager->persist($ent);
            }
        }
        $entityManager->flush();
        $this->updates = [];
    }

    function init(): void
    {
        \Revolt\EventLoop::repeat(15, $this->saveSeens(...));

        $this->client->on('notice', function ($args, \Irc\Client $bot) {
            if (!$bot->isChannel($args->to))
                return;
            //ignore ctcp replies to channel
            if (preg_match("@^\x01.*$@i", $args->text))
                return;
            $this->updateSeen('notice', $args->to, $args->from, $args->text);
        });

        $this->client->on('chat', function ($args, \Irc\Client $bot) {
            if (preg_match("@^\x01ACTION ([^\x01]+)\x01?$@i", $args->text, $m)) {
                $this->updateSeen('action', $args->chan, $args->from, $m[1]);
                return;
            }
            $this->updateSeen('privmsg', $args->chan, $args->from, $args->text);
        });
    }
}