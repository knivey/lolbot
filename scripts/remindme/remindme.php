<?php

namespace scripts\remindme;

use Carbon\Carbon;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Desc;
use knivey\cmdr\attributes\Option;
use knivey\cmdr\attributes\Syntax;
use scripts\remindme\entities\reminder;
use scripts\script_base;

use function knivey\tools\makeArgs;

class remindme extends script_base
{
    /** @var array<string, int> */
    private array $cmdLimit = [];
    /** @var array<string, int> */
    private array $limitWarns = [];

    #[Cmd("in", "remindme")]
    #[Syntax("<time> <msg>...")]
    #[Desc("sets a reminder for your after time. time is formatted like 5m30s supports: 1y2M3d4h5m6s")]
    public function in(object $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
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
    public function at(object $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
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

    #[Cmd("reminders")]
    #[Syntax("[filter]...")]
    #[Desc("Show your pending reminders on this channel")]
    #[Option("--all", "Show all users' reminders")]
    #[Option("--sort", "Sort by due or created (default: due)")]
    #[Option("--page", "Page number to show (default: 1)")]
    #[Option("--sent", "Show sent reminders instead of pending")]
    public function reminders(object $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
    {
        global $entityManager;

        $showSent = $cmdArgs->optEnabled('--sent');
        $showAll = $cmdArgs->optEnabled('--all');
        $pageSize = 10;
        $pageNum = 1;
        if ($cmdArgs->optEnabled('--page')) {
            $pageNum = max(1, (int) $cmdArgs->getOpt('--page'));
        }

        $sortBy = 'due';
        if ($cmdArgs->optEnabled('--sort')) {
            $sortBy = strtolower($cmdArgs->getOpt('--sort'));
            if (!in_array($sortBy, ['due', 'created'])) {
                $bot->pm($args->chan, "Invalid sort option, use due or created");
                return;
            }
        }

        $repo = $entityManager->getRepository(reminder::class);
        $qb = $repo->createQueryBuilder('r');
        $qb->where('r.network = :network')
           ->andWhere('r.sent = :sent')
           ->andWhere('r.chan = :chan')
           ->setParameter('network', $this->network)
           ->setParameter('sent', $showSent)
           ->setParameter('chan', $args->chan);

        if (!$showAll) {
            $qb->andWhere('LOWER(r.nick) = LOWER(:nick)')
               ->setParameter('nick', $args->nick);
        }

        if (isset($cmdArgs['filter']) && $cmdArgs['filter'] !== '') {
            $filter = str_replace('*', '%', $cmdArgs['filter']);
            $qb->andWhere('LOWER(r.msg) LIKE LOWER(:filter)')
               ->setParameter('filter', $filter);
        }

        $sortField = $sortBy === 'created' ? 'r.created' : 'r.at';
        $sortDir = $showSent ? 'DESC' : 'ASC';
        $qb->orderBy($sortField, $sortDir);

        $rs = $qb->getQuery()->getResult();

        if (count($rs) == 0) {
            $noun = $showSent ? "sent reminders" : "pending reminders";
            if ($showAll) {
                $bot->pm($args->chan, "No $noun found");
            } else {
                $bot->pm($args->chan, "You have no $noun");
            }
            return;
        }

        $total = count($rs);
        $pages = (int) ceil($total / $pageSize);
        if ($pageNum > $pages) {
            $pageNum = $pages;
        }
        $offset = ($pageNum - 1) * $pageSize;
        $pageResults = array_slice($rs, $offset, $pageSize);

        $lines = [];
        foreach ($pageResults as $r) {
            $msg = $r->msg;
            if (mb_strlen($msg) > 80) {
                $msg = mb_substr($msg, 0, 80) . '...';
            }

            if ($showSent) {
                $dueStr = "due " . self::shortDuration(time() - $r->at) . " ago";
            } else {
                $dueStr = "due in " . self::shortDuration($r->at - time());
            }

            $createdStr = "";
            if ($r->created !== null) {
                $createdStr = "created " . self::shortDuration(time() - $r->created->getTimestamp()) . " ago";
            }

            $nickStr = $showAll ? "{$r->nick}: " : "";

            $lines[] = ['id' => "[#{$r->id}]", 'due' => $dueStr, 'created' => $createdStr, 'msg' => $nickStr . $msg];
        }

        $maxId = max(array_map(mb_strlen(...), array_column($lines, 'id')));
        $maxDue = max(array_map(mb_strlen(...), array_column($lines, 'due')));
        $hasCreated = count(array_filter(array_column($lines, 'created'))) > 0;
        $maxCreated = 0;
        if ($hasCreated) {
            $maxCreated = max(array_map(mb_strlen(...), array_map(fn($v) => $v === '' ? '0' : $v, array_column($lines, 'created'))));
        }

        foreach ($lines as $line) {
            $idPad = mb_str_pad($line['id'], $maxId);
            $duePad = mb_str_pad($line['due'], $maxDue);
            $createdPad = "";
            if ($hasCreated) {
                $c = $line['created'] !== '' ? mb_str_pad($line['created'], $maxCreated) : str_repeat(' ', $maxCreated);
                $createdPad = "($c) ";
            }

            $bot->pm($args->chan, "{$idPad} {$duePad} {$createdPad}{$line['msg']}");
        }

        if ($pages > 1) {
            $footer = "Page {$pageNum}/{$pages}";
            if ($pageNum < $pages) {
                $nextPage = $pageNum + 1;
                $footer .= " — use --page={$nextPage} for next page";
            }
            $bot->pm($args->chan, $footer);
        }
    }

    private static function shortDuration(int $seconds): string
    {
        $parts = \Duration_int2array($seconds);
        if (!is_array($parts)) {
            return '0s';
        }
        return \Duration_array2string(array_slice($parts, 0, 3, true));
    }

    public function sendDelayed(\Irc\Client $bot, reminder $r, int $seconds): void
    {
        \Amp\async(function () use ($bot, $r, $seconds) {
            global $entityManager;
            if ($seconds > 0) {
                \Amp\delay($seconds);
            }
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


    public function init(): void
    {
        $this->logger->info("Initializing remindme...\n");
        \Amp\async(function () {
            global $entityManager;
            while (!$this->client->isEstablished()) {
                \Amp\delay(10);
            }
            //A bit of a hack here so we give the bot time to join channels etc
            \Amp\delay(5);
            //load our reminders from db and call sendDelayed on all
            $rs = $entityManager->getRepository(reminder::class)->findBy(["network" => $this->network, "sent" => false]);
            $this->logger->info("Network {$this->network} remindme has " . count($rs) . " reminders loaded from db\n");
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
