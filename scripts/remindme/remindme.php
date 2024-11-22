<?php
namespace scripts\remindme;

use Carbon\Carbon;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Desc;
use knivey\cmdr\attributes\Syntax;

use scripts\remindme\entities\reminder;
use scripts\script_base;
use function knivey\tools\makeArgs;

class remindme extends script_base
{
    private $cmdLimit = [];
    private $limitWarns = [];

    #[Cmd("in", "remindme")]
    #[Syntax("<time> <msg>...")]
    #[Desc("sets a reminder for your after time. time is formatted like 5m30s supports: 1y2M3d4h5m6s")]
    function in($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        global $entityManager;
        $host = $args->host;
        if (isset($this->cmdLimit[$host]) && $this->cmdLimit[$host] > time()) {
            if (!isset($this->limitWarns[$host]) || $this->limitWarns[$host] < time() - 2) {
                $bot->pm($args->chan, "You're going too fast, wait awhile");
                $this->limitWarns[$host] = time();
            }
            return;
        }
        $this->cmdLimit[$host] = time() + 2;
        unset($this->limitWarns[$host]);

        $in = \string2Seconds($cmdArgs['time']);
        if (is_string($in)) {
            $bot->pm($args->chan, "Error: $in, Give me a proper duration of at least 15 seconds with no spaces using yMwdhms (Ex: 1h10m15s)");
            return;
        }
        if ($in < 15) {
            $bot->pm($args->chan, "Give me a proper duration of at least 15 seconds with no spaces (Ex: 1h10m15s)");
            return;
        }
        if ($in > \string2Seconds("69y")) {
            $bot->pm($args->chan, "Yeah sure I'll totally remind you in " . \Duration_toString($in) . " ;-)");
            return;
        }

        $r = new reminder();
        $r->nick = $args->nick;
        $r->chan = $args->chan;
        $r->at = time() + $in;
        $r->msg = $cmdArgs['msg'];
        $r->network = $this->network;
        $entityManager->persist($r);
        $entityManager->flush();

        $bot->pm($args->chan, "Ok, I'll remind you in " . \Duration_toString($in));
        $this->sendDelayed($bot, $r, $in);
    }

    /*
     * Because cmdr doesnt yet support it, using \knivey\tools\makeArgs which makes args using "arg one" arg2 "arg\"3" etc
     */
    // TODO let users save a timezone so they dont have always include it here
    #[Cmd("at", "on")]
    #[Syntax("<timemsg>...")]
    #[Desc("Remind you at a certain date time, the date time must be in quotes")]
    function at($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        global $entityManager;
        $r = makeArgs($cmdArgs['timemsg']);
        if (!is_array($r) || count($r) < 2) {
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
        if ($dt->getTimestamp() <= time() + 15) {
            $bot->pm($args->chan, "Give me a time at least 15 seconds in the future");
            return;
        }
        $in = $dt->getTimestamp() - time();
        $r = new reminder();
        $r->nick = $args->nick;
        $r->chan = $args->chan;
        $r->at = $dt->getTimestamp();
        $r->sent = false;
        $r->msg = $msg;
        $r->network = $this->network;
        $entityManager->persist($r);
        $entityManager->flush();

        $fromNow = $dt->shortRelativeToNowDiffForHumans(Carbon::now(), 10);
        $bot->pm($args->chan, "Ok, I'll remind you on " . $dt->toCookieString() . " ($fromNow)");
        $this->sendDelayed($bot, $r, $in);
    }

    function sendDelayed(\Irc\Client $bot, $r, $seconds)
    {
        \Amp\async(function () use ($bot, $r, $seconds) {
            global $entityManager;
            if ($seconds > 0)
                \Amp\delay($seconds);
            //if bot somehow isnt connected keep retrying
            while (!$bot->isEstablished()) {
                \Amp\delay(10);
            }
            $bot->pm($r->chan, "[REMINDER: {$r->nick}] {$r->msg}");
            $r->sent = true;
            $entityManager->persist($r);
            $entityManager->flush();
        });
    }


    function init(): void
    {
        static $inited = false;
        if ($inited)
            return;
        $inited = true;
        echo "Initializing remindme...\n";
        \Amp\async(function () {
            global $entityManager;
            while (!$this->client->isEstablished()) {
                \Amp\delay(10);
            }
            //A bit of a hack here so we give the bot time to join channels etc
            \Amp\delay(5);
            //load our reminders from db and call sendDelayed on all
            $rs = $entityManager->getRepository(reminder::class)->findBy(["network"=>$this->network, "sent"=>false]);
            echo "remindme has " . count($rs) . " reminders loaded from db\n";
            foreach ($rs as $r) {
                //whoops already passed while bot was down
                if ($r->at <= time()) {
                    $ago = \Duration_toString(time() - $r->at);
                    $this->client->pm($r->chan, "[REMINDER: {$r->nick} (late by $ago)] {$r->msg}");
                    $r->sent = true;
                    $entityManager->persist($r);
                    $entityManager->flush();
                } else {
                    $this->sendDelayed($this->client, $r, $r->at - time());
                }
            }
        });
    }
}