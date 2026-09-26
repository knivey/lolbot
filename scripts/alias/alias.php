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

    /**
     * @var EntityRepository<entities\alias_history>
     */
    private EntityRepository $historyRepo;

    public function init(): void
    {
        /** @var \Doctrine\ORM\EntityManager */
        global $entityManager;
        $this->repo = $entityManager->getRepository(entities\alias::class);
        $this->historyRepo = $entityManager->getRepository(entities\alias_history::class);
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
            // record this save as the next version of the alias's history
            try {
                $this->appendHistory('save', $alias->chan, $alias->chanLowered, $alias->name,
                    $alias->nameLowered, $args->fullhost, $alias->value, $alias->act, $alias->cmd);
            } catch (\Exception $e) {
                $this->logger->error($e);
            }
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
            // the history log outlives the live row so the alias can be restored
            try {
                $this->appendHistory('removed', $alias->chan, $alias->chanLowered, $alias->name,
                    $alias->nameLowered, $args->fullhost);
            } catch (\Exception $e) {
                $this->logger->error($e);
            }
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
        $suffix = '';
        try {
            $suffix = self::versionSuffix(alias::buildTimeline(
                $this->loadHistoryRows($alias->nameLowered, $alias->chanLowered)
            ));
        } catch (\Exception $e) {
            $this->logger->error($e);
        }
        $rpl("\2Name:\2 {$alias->name} \2Last set by:\2 $alias->fullhost \2Action:\2 $act \2Cmd:\2 $alias->cmd$suffix");
        $rpl("\2Value:\2 $alias->value");
    }

    #[Cmd("revertalias")]
    #[Syntax("<name> [version]")]
    #[Desc("Revert an alias to a previous version (default: previous version, -n/--new: newest, or pass a version number). Can restore a removed alias")]
    #[Option(["--new", "-n"], "revert to the newest version instead")]
    function revertalias(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
    {
        global $entityManager;
        list($rpl, $rpln) = makeRepliers($args, $bot, "alias");
        try {
            $rawName = $cmdArgs['name'];
            $name = is_string($rawName) ? $rawName : '';
            $nameLowered = u($name)->lower();
            $chanLowered = u($args->chan)->lower();
            $events = $this->loadHistoryRows($nameLowered, $chanLowered);
            $timeline = alias::buildTimeline($events);
            $rawVersion = $cmdArgs['version'] ?? null;
            $version = is_string($rawVersion) && ctype_digit($rawVersion) ? (int)$rawVersion : null;
            $newest = $cmdArgs->optEnabled('--new') || $cmdArgs->optEnabled('-n');
            $target = alias::resolveRevertTarget($timeline, $version, $newest);
            if ($target === null) {
                $rpl("no version to revert to for that alias");
                return;
            }
            $alias = $this->repo->findOneBy([
                "nameLowered" => $nameLowered,
                "chanLowered" => $chanLowered,
                "network" => $this->network
            ]);
            if (!$alias) {
                // currently removed: re-create the live row, taking the identity
                // fields from the history row so the original casing is kept
                $seed = $events[array_key_first($events) ?? 0] ?? [];
                $alias = new entities\alias();
                $alias->name = self::seedStr($seed, 'name', $name);
                $alias->nameLowered = self::seedStr($seed, 'nameLowered', $nameLowered);
                $alias->chan = self::seedStr($seed, 'chan', $args->chan);
                $alias->chanLowered = self::seedStr($seed, 'chanLowered', $chanLowered);
            }
            $alias->value = $target['value'] ?? '';
            $alias->act = $target['act'] ?? false;
            $alias->cmd = $target['cmd'];
            $alias->fullhost = $args->fullhost;
            $alias->network = $this->network;
            $entityManager->persist($alias);
            $entityManager->flush();
            // only a marker: a revert never renumbers the saved versions
            $this->appendHistory('reverted', $alias->chan, $alias->chanLowered, $alias->name,
                $alias->nameLowered, $args->fullhost, note: "restored version {$target['version']}");
            $value = $target['value'] ?? '';
            $preview = mb_strlen($value) > 80 ? mb_substr($value, 0, 80) . '...' : $value;
            $rpl("alias restored to version {$target['version']}: $preview");
        } catch (\Exception $e) {
            $rpl("Error while reverting alias");
            $this->logger->error($e);
        }
    }

    #[Cmd("aliashistory")]
    #[Syntax("<name>")]
    #[Desc("Show the version history of an alias")]
    function aliashistory(\Irc\Event\ChatEvent $args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs): void
    {
        list($rpl, $rpln) = makeRepliers($args, $bot, "alias");
        try {
            $rawName = $cmdArgs['name'];
            $nameArg = is_string($rawName) ? $rawName : '';
            $events = $this->loadHistoryRows(u($nameArg)->lower(), u($args->chan)->lower());
            $entries = alias::buildTimeline($events)['entries'];
        } catch (\Exception $e) {
            $rpl("error while retrieving history");
            $this->logger->error($e);
            return;
        }
        if (count($entries) == 0) {
            $rpl("no history for that alias");
            return;
        }
        // keep the original casing from the history row for display
        $seed = $events[array_key_first($events) ?? 0] ?? [];
        $name = self::seedStr($seed, 'name', $nameArg);
        if (count($entries) == 1) {
            $rpl(self::historyLine($entries[0]));
            return;
        }
        global $entityManager;
        $paste = (new \lolbot\config\ServiceLocator($entityManager))->getServiceConfig('paste');
        if ($paste instanceof \lolbot\entities\PasteServiceConfig && $paste->host !== null && $paste->key !== null) {
            try {
                $content = $this->historyMarkdown($entries, $args->chan, $name);
                $url = \createPaste($content, "Alias history for {$name} in {$args->chan}", $paste->host, $paste->key);
                $rpl($url, 'list');
                return;
            } catch (\Throwable $e) {
                echo "Paste error for aliashistory: " . $e->getMessage() . "\n";
            }
        }
        // paste unavailable or failed: fall back to the truncated channel lines
        $list = implode(', ', array_map(fn($e) => $this->historyLine($e), $entries));
        foreach (explode("\n", wordwrap($list, 300, "\n", true)) as $line)
            $rpl("$line", 'list');
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
     * Loads the history rows for one alias (network+chan+name key, ordered
     * oldest first) hydrated as the plain arrays buildTimeline() consumes.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadHistoryRows(string $nameLowered, string $chanLowered): array
    {
        $rows = $this->historyRepo->findBy([
            "network" => $this->network,
            "chanLowered" => $chanLowered,
            "nameLowered" => $nameLowered
        ], ["id" => "ASC"]);
        return array_map(fn(entities\alias_history $h): array => [
            'id' => $h->id,
            'chan' => $h->chan,
            'chanLowered' => $h->chanLowered,
            'name' => $h->name,
            'nameLowered' => $h->nameLowered,
            'value' => $h->value,
            'act' => $h->act,
            'cmd' => $h->cmd,
            'fullhost' => $h->fullhost,
            'created' => $h->created,
            'event' => $h->event,
            'note' => $h->note,
        ], $rows);
    }

    /**
     * Appends one event to the alias_history log and flushes it. Marker
     * events ('removed', 'reverted') leave value/act/cmd null and carry
     * their meaning in $event/$note instead.
     */
    private function appendHistory(string $event, string $chan, string $chanLowered, string $name,
                                   string $nameLowered, string $fullhost,
                                   ?string $value = null, ?bool $act = null, ?string $cmd = null,
                                   ?string $note = null): void
    {
        global $entityManager;
        $history = new entities\alias_history();
        $history->network = $this->network;
        $history->chan = $chan;
        $history->chanLowered = $chanLowered;
        $history->name = $name;
        $history->nameLowered = $nameLowered;
        $history->value = $value;
        $history->act = $act;
        $history->cmd = $cmd;
        $history->fullhost = $fullhost;
        $history->created = new \DateTimeImmutable();
        $history->event = $event;
        $history->note = $note;
        $entityManager->persist($history);
        $entityManager->flush();
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

    /**
     * Pure helper: formats one timeline entry (from buildTimeline()) as an
     * IRC line, e.g. "\2v2\2 saved by nick!host at 2026-01-01 12:00 UTC".
     * 'reverted' entries read the restored version out of their note.
     *
     * @param array<string, mixed> $entry
     */
    public static function historyLine(array $entry): string
    {
        $version = $entry['version'] ?? null;
        $event = $entry['event'] ?? null;
        $fullhost = $entry['fullhost'] ?? null;
        $created = $entry['created'] ?? null;
        $who = is_string($fullhost) ? $fullhost : '';
        $when = $created instanceof \DateTimeImmutable ? $created->format('Y-m-d H:i T') : 'unknown';
        if ($event === 'save' && is_int($version)) {
            return "\2v{$version}\2 saved by {$who} at {$when}";
        }
        if ($event === 'removed') {
            return "\2removed\2 by {$who} at {$when}";
        }
        if ($event === 'reverted') {
            $restored = self::restoredVersion($entry['note'] ?? null);
            if ($restored !== null) {
                return "\2reverted\2 to v{$restored} by {$who} at {$when}";
            }
            return "\2reverted\2 by {$who} at {$when}";
        }
        $label = is_string($event) ? $event : 'unknown';
        return "\2{$label}\2 by {$who} at {$when}";
    }

    /**
     * Extracts the version a 'reverted' marker restored from its note
     * ("restored version N"); null when the note carries no number.
     *
     * @param mixed $note
     */
    private static function restoredVersion(mixed $note): ?int
    {
        if (!is_string($note) || !preg_match('/(\d+)/', $note, $m)) {
            return null;
        }
        return (int)$m[1];
    }

    /**
     * Reads one string field from a hydrated history row, falling back to
     * $default when the row (or field) is missing or not a string.
     *
     * @param array<string, mixed> $row
     */
    private static function seedStr(array $row, string $key, string $default): string
    {
        $value = $row[$key] ?? null;
        return is_string($value) ? $value : $default;
    }

    /**
     * Section heading for one entry inside historyMarkdown().
     *
     * @param array<string, mixed> $entry
     */
    private static function historyHeading(array $entry): string
    {
        $event = $entry['event'] ?? null;
        $version = $entry['version'] ?? null;
        if ($event === 'save' && is_int($version)) {
            return "v{$version} saved";
        }
        if ($event === 'removed') {
            return 'removed';
        }
        if ($event === 'reverted') {
            $restored = self::restoredVersion($entry['note'] ?? null);
            if ($restored !== null) {
                return "reverted to v{$restored}";
            }
            return 'reverted';
        }
        return is_string($event) ? $event : 'unknown';
    }

    /**
     * Pure helper: renders the timeline entries as the markdown pasted by
     * aliashistory() (header with chan+name, one section per entry with
     * who/when, the value in a code fence and the note when set).
     *
     * @param array<int, array<string, mixed>> $entries
     */
    public static function historyMarkdown(array $entries, string $chan, string $name): string
    {
        $out = "# Alias history for {$name} in {$chan}\n\n";
        $first = true;
        foreach ($entries as $entry) {
            if (!$first)
                $out .= "\n---\n\n";
            $first = false;
            $fullhost = $entry['fullhost'] ?? null;
            $created = $entry['created'] ?? null;
            $value = $entry['value'] ?? null;
            $note = $entry['note'] ?? null;
            $who = is_string($fullhost) ? $fullhost : '';
            $when = $created instanceof \DateTimeImmutable ? $created->format('Y-m-d H:i T') : 'unknown';
            $out .= "## " . self::historyHeading($entry) . "\n\n";
            $out .= "- **By:** `{$who}`\n";
            $out .= "- **At:** {$when}\n";
            if (is_string($note))
                $out .= "- **Note:** {$note}\n";
            if (is_string($value))
                $out .= "\n**Value:**\n```\n{$value}\n```\n";
        }
        return $out;
    }

    /**
     * Pure helper: the version info showalias() appends to its first reply
     * line (" \2Version:\2 N of M \2Updated:\2 <latest save date>"). Empty
     * string while the alias is removed or has no dated history, so the
     * existing output is shown unchanged.
     *
     * @param array<string, mixed> $timeline
     */
    public static function versionSuffix(array $timeline): string
    {
        $entries = $timeline['entries'] ?? null;
        if (!is_array($entries)) {
            return '';
        }
        $removed = $timeline['removed'] ?? false;
        if (!is_bool($removed) || $removed) {
            return '';
        }
        $currentVersion = $timeline['currentVersion'] ?? null;
        $totalSaves = $timeline['totalSaves'] ?? null;
        if (!is_int($currentVersion) || $currentVersion < 1 || !is_int($totalSaves) || $totalSaves < 1) {
            return '';
        }
        $updated = null;
        foreach ($entries as $entry) {
            if (!is_array($entry) || ($entry['event'] ?? null) !== 'save') {
                continue;
            }
            $created = $entry['created'] ?? null;
            if ($created instanceof \DateTimeImmutable) {
                $updated = $created;
            }
        }
        if ($updated === null) {
            return '';
        }
        return " \2Version:\2 {$currentVersion} of {$totalSaves} \2Updated:\2 " . $updated->format('Y-m-d H:i T');
    }
}