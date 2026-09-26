<?php
namespace scripts\alias;

use Doctrine\ORM\EntityRepository;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Desc;
use knivey\cmdr\attributes\Option;
use knivey\cmdr\attributes\Syntax;
use scripts\script_base;
use function Symfony\Component\String\u;

require_once __DIR__ . '/../../library/paste.php';

/** @var \Doctrine\ORM\EntityManager $entityManager */
global $entityManager;
class alias extends script_base
{
    /**
     * 
     * @var EntityRepository<entities\alias>
     */
    private EntityRepository $repo;

    public function init(): void
    {
        /** @var \Doctrine\ORM\EntityManager */
        global $entityManager;
        $this->repo = $entityManager->getRepository(entities\alias::class);
    }

    #[Cmd("alias")]
    #[Desc("Add a new alias for the channel (like a command), available variables: $0 $0- (thru $9) \$nick \$chan \$target")]
    #[Syntax("<name> <value>...")]
    #[Option("--me", "Make the alias reply with /me")]
    #[Option("--act", "same as --me")]
    #[Option("--cmd", "make this an alias for calling bot commands ex: --cmd=ruby (cannot use with --act)")]
    public function alias(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
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
            } else {
                $alias->cmd = null;
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
    function unalias(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
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
    #[Option("--web", "show detailed aliases on web paste")]
    function aliases(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
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
        $usePaste = $cmdArgs->optEnabled('--web') || count($aliases) > 20;
        global $entityManager;
        $paste = (new \lolbot\config\ServiceLocator($entityManager))->getServiceConfig('paste');
        if ($usePaste && $paste instanceof \lolbot\entities\PasteServiceConfig && $paste->host !== null && $paste->key !== null) {
            try {
                $content = $this->formatAliasesMarkdown($aliases, $args->chan);
                $url = \createPaste($content, "Aliases for {$args->chan}", $paste->host, $paste->key);
                $rpl($url, 'list');
                return;
            } catch (\Throwable $e) {
                echo "Paste error for aliases: " . $e->getMessage() . "\n";
            }
        }
        $list = implode(', ', array_map(fn($it) => $it->name, $aliases));
        foreach (explode("\n", wordwrap($list, 300, "\n", true)) as $line)
            $rpl("$line", 'list');
    }

    /**
     * @param array<entities\alias> $aliases
     */
    function formatAliasesMarkdown(array $aliases, string $chan): string
    {
        $out = "# Aliases for {$chan}\n\n";
        $first = true;
        foreach ($aliases as $alias) {
            if (!$first)
                $out .= "\n---\n\n";
            $first = false;

            $out .= "## `{$alias->name}`\n\n";
            $out .= "- **Set by:** `{$alias->fullhost}`\n";
            $out .= "- **Action:** " . ($alias->act ? "true" : "false") . "\n";
            if ($alias->cmd !== null)
                $out .= "- **Cmd:** `{$alias->cmd}`\n";
            $out .= "\n**Value:**\n```\n{$alias->value}\n```\n\n";
        }
        return $out;
    }

    #[Cmd("showalias", "aliasinfo")]
    #[Syntax("<name>")]
    #[Desc("Show info about an alias")]
    function showaliass(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
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
            $entityManager->refresh($alias);
        } catch (\Exception $e) {
            $this->logger->error($e);
            return;
        }
        $act = $alias->act ? "true" : "false";
        $rpl("\2Name:\2 {$alias->name} \2Last set by:\2 $alias->fullhost \2Action:\2 $act \2Cmd:\2 $alias->cmd");
        $rpl("\2Value:\2 $alias->value");
    }

    /**
     * @param \Irc\Event\ChatEvent $args
     * @param \Irc\Client $bot
     * @param string $cmd
     * @param array<string> $cmdArgs
     * @param array<string, string|null> $invOpts
     * @return bool
     */
    function handleCmd(\Irc\Event\ChatEvent $args, \Irc\Client $bot, string $cmd, array $cmdArgs, array $invOpts = []): bool
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
            if (!empty($invOpts)) {
                $optStr = '';
                foreach ($invOpts as $name => $val) {
                    if ($val !== null) {
                        $optStr .= "$name=$val ";
                    } else {
                        $optStr .= "$name ";
                    }
                }
                $value = trim($value . ' ' . trim($optStr));
            }
            try {
                $this->router->call($alias->cmd, $value, $args, $bot);
            } catch (\Exception $e) {
                $bot->notice($args->nick, $e->getMessage());
            }
            return true;
        }

        if ($alias->act) {
            $bot->msg($args->chan, "\x01ACTION $value\x01");
        } else {
            $bot->msg($args->chan, "\2\2$value");
        }
        return true;
    }

    /**
     * Pure helper: builds the decision/display timeline for an alias's
     * history rows (ordered by id ASC, as the entity hydrates them).
     * 'save' rows get version numbers 1..N in encounter order; 'removed'
     * and 'reverted' marker rows get null. currentVersion is the latest
     * save's version (null when the alias has never been saved), removed
     * is true when a 'removed' marker occurs after the last 'save' (the
     * alias is currently deleted; a later save clears it again).
     *
     * @param array<int, mixed> $events rows shaped like entities\alias_history hydration (id, chan, chanLowered, name, nameLowered, value, act, cmd, fullhost, created, event, note); malformed rows are skipped
     * @return array{entries: list<array{version: int|null, event: string, fullhost: string, created: \DateTimeImmutable|null, value: string|null, act: bool|null, cmd: string|null, note: string|null}>, currentVersion: int|null, removed: bool, totalSaves: int}
     */
    public static function buildTimeline(array $events): array
    {
        /** @var list<array{version: int|null, event: string, fullhost: string, created: \DateTimeImmutable|null, value: string|null, act: bool|null, cmd: string|null, note: string|null}> $entries */
        $entries = [];
        $currentVersion = null;
        $removed = false;
        $version = 0;
        foreach ($events as $row) {
            if (!is_array($row)) {
                continue;
            }
            $entry = self::historyEntry($row, null);
            if ($entry === null) {
                continue;
            }
            if ($entry['event'] === 'save') {
                $version++;
                $entry['version'] = $version;
                $currentVersion = $version;
                $removed = false;
            } elseif ($entry['event'] === 'removed') {
                $removed = true;
            }
            $entries[] = $entry;
        }
        return [
            'entries' => $entries,
            'currentVersion' => $currentVersion,
            'removed' => $removed,
            'totalSaves' => $version,
        ];
    }

    /**
     * Normalizes one history row into a timeline entry; returns null for
     * rows without a usable event string so callers can skip them.
     *
     * @param array<mixed> $row
     * @return array{version: int|null, event: string, fullhost: string, created: \DateTimeImmutable|null, value: string|null, act: bool|null, cmd: string|null, note: string|null}|null
     */
    private static function historyEntry(array $row, ?int $version): ?array
    {
        $event = $row['event'] ?? null;
        if (!is_string($event)) {
            return null;
        }
        $fullhost = $row['fullhost'] ?? null;
        $created = $row['created'] ?? null;
        $value = $row['value'] ?? null;
        $act = $row['act'] ?? null;
        $cmd = $row['cmd'] ?? null;
        $note = $row['note'] ?? null;
        return [
            'version' => $version,
            'event' => $event,
            'fullhost' => is_string($fullhost) ? $fullhost : '',
            'created' => $created instanceof \DateTimeImmutable ? $created : null,
            'value' => is_string($value) ? $value : null,
            'act' => is_bool($act) ? $act : null,
            'cmd' => is_string($cmd) ? $cmd : null,
            'note' => is_string($note) ? $note : null,
        ];
    }

    /**
     * Pure helper: given a buildTimeline() result, picks which save entry a
     * revert should restore. Explicit $version targets that save (null when
     * it does not exist); $newest targets the latest save; the default
     * targets the save previous to currentVersion (null when there is no
     * previous). While the alias is removed, the default and newest both
     * mean the latest save (restoring a deleted alias); explicit versions
     * still work. Returns null when there is nothing revertable.
     *
     * @param array{entries?: mixed, currentVersion?: mixed, removed?: mixed, totalSaves?: mixed} $timeline
     * @return array{version: int, event: string, fullhost: string, created: \DateTimeImmutable|null, value: string|null, act: bool|null, cmd: string|null, note: string|null}|null
     */
    public static function resolveRevertTarget(array $timeline, ?int $version = null, bool $newest = false): ?array
    {
        $rawEntries = $timeline['entries'] ?? null;
        if (!is_array($rawEntries)) {
            return null;
        }
        /** @var array<int, array{version: int, event: string, fullhost: string, created: \DateTimeImmutable|null, value: string|null, act: bool|null, cmd: string|null, note: string|null}> $saves */
        $saves = [];
        foreach ($rawEntries as $rawEntry) {
            if (!is_array($rawEntry)) {
                continue;
            }
            $ver = $rawEntry['version'] ?? null;
            if (!is_int($ver) || $ver < 1) {
                continue;
            }
            $entry = self::historyEntry($rawEntry, $ver);
            if ($entry === null) {
                continue;
            }
            $entry['version'] = $ver;
            $saves[$ver] = $entry;
        }
        if ($saves === []) {
            return null;
        }
        $latest = max(array_keys($saves));
        if ($version !== null) {
            return $saves[$version] ?? null;
        }
        if ($newest) {
            return $saves[$latest] ?? null;
        }
        $removed = $timeline['removed'] ?? false;
        if (is_bool($removed) && $removed) {
            return $saves[$latest] ?? null;
        }
        $currentVersion = $timeline['currentVersion'] ?? null;
        if (!is_int($currentVersion) || $currentVersion < 2) {
            return null;
        }
        return $saves[$currentVersion - 1] ?? null;
    }
}