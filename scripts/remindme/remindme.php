<?php

namespace scripts\remindme;

use Carbon\Carbon;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Desc;
use knivey\cmdr\attributes\Option;
use knivey\cmdr\attributes\Syntax;
use scripts\remindme\entities\reminder;
use scripts\script_base;

class remindme extends script_base
{
    /** @var array<string, int> */
    private array $cmdLimit = [];
    /** @var array<string, int> */
    private array $limitWarns = [];

    #[Cmd("in", "remindme")]
    #[Syntax("<timemsg>...")]
    #[Desc("sets a reminder. time can be a duration (1h30m, 1 hour 15 min, 2 days) or a relative date (next tuesday, tomorrow 3pm, next month, aug 15th, second week of jan)")]
    public function in(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
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

        $parsed = \parseDuration($cmdArgs['timemsg']);
        if ($parsed === null) {
            $bot->pm($args->chan, "Couldn't understand that time. Try: 1h30m, 1 hour 15 min, 2 days, next tuesday, tomorrow 3pm, next month, aug 15");
            return;
        }

        if ($parsed->remainder === '') {
            $bot->pm($args->chan, "You need to tell me what to remind you about!");
            return;
        }

        $in = $parsed->seconds;
        if ($in < 15) {
            $bot->pm($args->chan, "Give me a time at least 15 seconds in the future");
            return;
        }
        if ($in > \string2Seconds("69y")) {
            $bot->pm($args->chan, "Yeah sure I'll totally remind you in " . \Duration_toString($in) . " ;-)");
            return;
        }

        $r = new reminder();
        $r->nick = $args->nick;
        $r->chan = $args->chan;
        $r->at = $parsed->targetTime ?? time() + $in;
        $r->msg = $parsed->remainder;
        $r->network = $this->network;
        $entityManager->persist($r);
        $entityManager->flush();

        if ($parsed->targetTime !== null) {
            $dt = Carbon::createFromTimestamp($parsed->targetTime);
            $fromNow = $dt->shortRelativeToNowDiffForHumans(Carbon::now(), 10);
            $bot->pm($args->chan, "Ok, I'll remind you on " . $dt->toCookieString() . " ($fromNow)");
        } else {
            $bot->pm($args->chan, "Ok, I'll remind you in " . \Duration_toString($in));
        }
        $this->sendDelayed($bot, $r, $in);
    }

    #[Cmd("at", "on")]
    #[Syntax("<timemsg>...")]
    #[Desc("Remind you at a certain date time. Try: .on next friday watch new JRH  or  .at tomorrow 3pm eat ice cream  or  .in aug 15 pay rent")]
    public function at(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
    {
        global $entityManager;

        $parsed = \parseDuration($cmdArgs['timemsg']);
        if ($parsed === null || $parsed->remainder === '') {
            $bot->pm($args->chan, "Syntax: <datetime> <msg>  Try: .on next friday watch new JRH  or  .at tomorrow 3pm eat ice cream  or  .in aug 15 pay rent");
            return;
        }

        if ($parsed->targetTime !== null) {
            $dt = Carbon::createFromTimestamp($parsed->targetTime);
        } else {
            $dt = Carbon::createFromTimestamp(time() + $parsed->seconds);
        }

        $in = $parsed->seconds;
        if ($in < 15) {
            $bot->pm($args->chan, "Give me a time at least 15 seconds in the future");
            return;
        }

        $r = new reminder();
        $r->nick = $args->nick;
        $r->chan = $args->chan;
        $r->at = $dt->getTimestamp();
        $r->sent = false;
        $r->msg = $parsed->remainder;
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
    public function reminders(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
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
