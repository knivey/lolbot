<?php
namespace scripts\alias;

use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Desc;
use knivey\cmdr\attributes\Option;
use knivey\cmdr\attributes\Syntax;
use scripts\script_base;
use function Symfony\Component\String\u;

class alias extends script_base
{

    public function init(): void
    {
        global $entityManager;
        $this->repo = $entityManager->getRepository(entities\alias::class);
    }

    #[Cmd("alias")]
    #[Desc("Add a new alias for the channel (like a command), available variables: $0 $0- (thru $9) \$nick \$chan \$target")]
    #[Syntax("<name> <value>...")]
    #[Option("--me", "Make the alias reply with /me")]
    #[Option("--act", "same as --me")]
    #[Option("--cmd", "make this an alias for calling bot commands ex: --cmd=ruby (cannot use with --act)")]
    public function alias(object $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
    {
        global $entityManager;
        [$rpl] = \makeRepliers($args, $bot, "alias");

        try {
            $alias = $this->repo->findOneBy([
                "nameLowered" => u($cmdArgs['name'])->lower(),
                "chanLowered" => u($args->chan)->lower(),
                "network" => $this->network
            ]);

            $msg = '';
            if ($alias != null) {
                $msg = "That alias already exists, updating it... ";
            } else {
                $alias = new entities\alias();
            }
            $alias->name = $cmdArgs['name'];
            $alias->nameLowered = u($cmdArgs['name'])->lower();
            $alias->value = $cmdArgs['value'];
            $alias->chan = $args->chan;
            $alias->chanLowered = u($args->chan)->lower();
            $alias->fullhost = $args->fullhost;
            $alias->act = ($cmdArgs->optEnabled('--act') || $cmdArgs->optEnabled('--me'));
            $alias->network = $this->network;
            if ($cmdArgs->optEnabled('--cmd')) {
                $alias->cmd = $cmdArgs->getOpt('--cmd');
            }
            $entityManager->persist($alias);
            $entityManager->flush();
            $rpl("{$msg}alias saved");
        } catch (\Exception $e) {
            $rpl("Error while creating alias");
            $this->logger->error($e);
        }
    }

    #[Cmd("unalias")]
    #[Syntax("<name>")]
    #[Desc("Remove a channel alias")]
    function unalias($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
    {

        global $entityManager;
        list($rpl, $rpln) = makeRepliers($args, $bot, "alias");
        try {
            $alias = $this->repo->findOneBy([
                "nameLowered" => u($cmdArgs['name'])->lower(),
                "chanLowered" => u($args->chan)->lower(),
                "network" => $this->network
            ]);

            if (!$alias) {
                $rpl("That alias not found");
                return;
            }
            $entityManager->remove($alias);
            $entityManager->flush();
            $rpl("Alias removed");
        } catch (\Exception $e) {
            $rpl("Error while removing alias");
            $this->logger->error($e);
        }
    }

    #[Cmd("aliases")]
    #[Desc("List the channel aliases")]
    function aliases($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
    {
        list($rpl, $rpln) = makeRepliers($args, $bot, "alias");
        try {
            $aliases = $this->repo->findBy([
                "network" => $this->network,
                "chanLowered" => u($args->chan)->lower()
            ]);
        } catch (\Exception $e) {
            $rpl("Error while retrieving aliases");
            $this->logger->error($e);
            return;
        }
        if (count($aliases) == 0) {
            $rpl("No aliases set for {$args->chan}");
            return;
        }
        $list = implode(', ', array_map(fn($it) => $it->name, $aliases));
        foreach (explode("\n", wordwrap($list, 300, "\n", true)) as $line)
            $rpl("$line", 'list');
    }

    /**
     * @param object $args
     * @param \Irc\Client $bot
     * @param string $cmd
     * @param array<string> $cmdArgs
     * @return bool
     */
    function handleCmd(object $args, \Irc\Client $bot, string $cmd, array $cmdArgs): bool
    {
        global $entityManager;
        try {
            $alias = $this->repo->findOneBy([
                "nameLowered" => u($cmd)->lower(),
                "chanLowered" => u($args->chan)->lower(),
                "network" => $this->network
            ]);
            if (!$alias)
                return false;
            $entityManager->refresh($alias);
        } catch (\Exception $e) {
            $this->logger->error($e);
            return false;
        }
        $value = $alias->value;
        // Just keeping this very simple atm, may build a proper parser later
        // using str_replace the order is important
        $vars = [
            '$0-' => "$cmd " . implode(" ", $cmdArgs),
            '$1-' => implode(" ", $cmdArgs),
            '$2-' => implode(" ", array_slice($cmdArgs, 1)),
            '$3-' => implode(" ", array_slice($cmdArgs, 2)),
            '$4-' => implode(" ", array_slice($cmdArgs, 3)),
            '$5-' => implode(" ", array_slice($cmdArgs, 4)),
            '$6-' => implode(" ", array_slice($cmdArgs, 5)),
            '$7-' => implode(" ", array_slice($cmdArgs, 6)),
            '$8-' => implode(" ", array_slice($cmdArgs, 7)),
            '$9-' => implode(" ", array_slice($cmdArgs, 8)),

            '$0' => $cmd,
            '$1' => $cmdArgs[0] ?? "",
            '$2' => $cmdArgs[1] ?? "",
            '$3' => $cmdArgs[2] ?? "",
            '$4' => $cmdArgs[3] ?? "",
            '$5' => $cmdArgs[4] ?? "",
            '$6' => $cmdArgs[5] ?? "",
            '$7' => $cmdArgs[6] ?? "",
            '$8' => $cmdArgs[7] ?? "",
            '$9' => $cmdArgs[8] ?? "",
            //if any of these have $whatever that matches something that follows its gonna be replaceable by what follows
            '$nick' => $args->nick,
            '$chan' => $args->chan,
            '$target' => count($cmdArgs) > 0 ? implode(' ', $cmdArgs) : $args->nick,
        ];
        $value = str_replace(array_keys($vars), $vars, $value);

        if (isset($alias->cmd)) {
            if (!$this->router->cmdExists($alias->cmd)) {
                $bot->msg($args->chan, "Error with alias, bot command {$alias->cmd} not found");
                return true;
            }
            try {
                $this->router->call($alias->cmd, $value, $args, $bot);
            } catch (\Exception $e) {
                $bot->notice($args->from, $e->getMessage());
            }
            return true;
        }

        if ($alias->act) {
            $bot->msg($args->chan, "\x01ACTION $value\x01");
        } else {
            $bot->msg($args->chan, "$value");
        }
        return true;
    }
}