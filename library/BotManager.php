<?php
namespace library;

use Channels;
use Crell\Tukio\Dispatcher;
use Crell\Tukio\OrderedListenerProvider;
use Doctrine\ORM\EntityManager;
use Exception;
use Irc\Client;
use Irc\Event\ChatEvent;
use Irc\Event\KickEvent;
use Irc\Event\ModeEvent;
use Irc\Event\PmEvent;
use Irc\Event\WelcomeEvent;
use Monolog\Logger;
use Nicks;
use Revolt\EventLoop;
use function Amp\async;
use function extractOptsAndArgs;
use knivey\cmdr\Cmdr;
use lolbot\config\ConfigChange;
use lolbot\config\SettingsResolver;
use lolbot\entities\Bot;
use lolbot\entities\Channel;
use lolbot\entities\Ignore;
use lolbot\entities\Network;
use lolbot\entities\Server;
use scripts\alias\alias;
use scripts\bomb_game\bomb_game;
use scripts\codesand\codesand;
use scripts\crypto\crypto;
use scripts\github\github;
use scripts\help\help;
use scripts\imgur\imgur;
use scripts\invidious\invidious;
use scripts\lastfm\lastfm;
use scripts\linktitles\linktitles;
use scripts\reddit\reddit;
use scripts\remindme\remindme;
use scripts\seen\seen;
use scripts\stocks\stocks;
use scripts\tell\tell;
use scripts\tiktok\tiktok;
use scripts\tools\tools;
use scripts\twitter\twitter;
use scripts\urbandict\urbandict;
use scripts\weather\weather;
use scripts\youtube\youtube;

/**
 * Owns the live channel-bot fleet and hot-applies ConfigChange mutations.
 *
 * spawn() relocates the procedural startBot() body from lolbot.php verbatim
 * (only the three documented adaptations); the lifecycle methods and apply()
 * dispatcher turn those long-lived clients into a manageable fleet.
 */
class BotManager
{
    /** @var array<int, \Irc\Client> */
    public array $clients = [];
    /** @var array<int, \lolbot\entities\Bot> */
    public array $bots = [];
    /** @var array<int, \lolbot\entities\Network> */
    public array $networks = [];
    /** @var array<int, \stdClass> per-bot mutable state (linktitlesEnabled) */
    public array $state = [];

    public function __construct(private \Doctrine\ORM\EntityManager $em) {}

    public function spawn(Network $network, Bot $dbBot): \Irc\Client
    {
        /** @var \Monolog\Handler\HandlerInterface $logHandler */
        $logHandler = $GLOBALS['logHandler'];
        /** @var array<string, mixed> $config */
        $config = $GLOBALS['config'];
        $entityManager = $this->em;
        $linktitlesEnabled = (new \lolbot\config\SettingsResolver($entityManager))->linktitlesEnabled($network, null);
        $st = new \stdClass();
        $st->linktitlesEnabled = $linktitlesEnabled;
        //TODO add support and check for per bot servers first
        $server = $network->selectServer();
        $log = new Logger($dbBot->name);
        $log->pushHandler($logHandler);
        $client = new \Irc\Client($dbBot->name, $server->address, $log, (string)$server->port, $dbBot->bindIp, $server->ssl);
        $client->setThrottle($server->throttle);
        $client->setServerPassword($server->password ?? '');
        if (isset($dbBot->sasl_user) && isset($dbBot->sasl_pass)) {
            $client->setSasl($dbBot->sasl_user, $dbBot->sasl_pass);
        }
        $nicks = new Nicks($client);
        $chans = new Channels($client);

        $router = new Cmdr();
        $router->loadFuncs();

        $bomb_game = new bomb_game($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:bomb_game", [$logHandler]), $nicks, $chans, $router);
        $router->loadMethods($bomb_game);
        $alias = new alias($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:alias", [$logHandler]), $nicks, $chans, $router);
        $router->loadMethods($alias);
        $weather = new weather($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:weather", [$logHandler]), $nicks, $chans, $router);
        $router->loadMethods($weather);
        $lastfm = new lastfm($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:lastfm", [$logHandler]), $nicks, $chans, $router);
        $router->loadMethods($lastfm);
        $remindme = new remindme($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:remindme", [$logHandler]), $nicks, $chans, $router);
        $router->loadMethods($remindme);
        $tell = new tell($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:tell", [$logHandler]), $nicks, $chans, $router);
        $router->loadMethods($tell);
        $seen = new seen($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:seen", [$logHandler]), $nicks, $chans, $router);
        $router->loadMethods($seen);
        $codesand = new codesand($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:codesand", [$logHandler]), $nicks, $chans, $router);
        $router->loadMethods($codesand);
        $tools = new tools($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:tools", [$logHandler]), $nicks, $chans, $router);
        $router->loadMethods($tools);
        $urbandict = new urbandict($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:urbandict", [$logHandler]), $nicks, $chans, $router);
        $router->loadMethods($urbandict);
        $help = new help($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:help", [$logHandler]), $nicks, $chans, $router);
        $router->loadMethods($help);
        $stocks = new stocks($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:stocks", [$logHandler]), $nicks, $chans, $router);
        $router->loadMethods($stocks);
        $crypto = new crypto($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:crypto", [$logHandler]), $nicks, $chans, $router);
        $router->loadMethods($crypto);

        $eventLogger = new Logger("{$dbBot->name}:linktitles:UrlEvents", [$logHandler]);
        $eventProvider = new OrderedListenerProvider();
        $eventDispatcher = new Dispatcher($eventProvider, $eventLogger);
        $linktitles = new linktitles($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:linktitles", [$logHandler]), $nicks, $chans, $router);
        $linktitles->eventDispatcher = $eventDispatcher;
        $router->loadMethods($linktitles);

        $youtube = new youtube($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:youtube", [$logHandler]), $nicks, $chans, $router);
        $youtube->setEventProvider($eventProvider);
        $router->loadMethods($youtube);

        $twitter = new twitter($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:twitter", [$logHandler]), $nicks, $chans, $router);
        $twitter->setEventProvider($eventProvider);
        $router->loadMethods($twitter);

        $invidious = new invidious($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:invidious", [$logHandler]), $nicks, $chans, $router);
        $invidious->setEventProvider($eventProvider);
        $router->loadMethods($invidious);

        $github = new github($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:github", [$logHandler]), $nicks, $chans, $router);
        $github->setEventProvider($eventProvider);
        $router->loadMethods($github);

        $reddit = new reddit($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:reddit", [$logHandler]), $nicks, $chans, $router);
        $reddit->setEventProvider($eventProvider);
        $router->loadMethods($reddit);

        $tiktok = new tiktok($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:tiktok", [$logHandler]), $nicks, $chans, $router);
        $tiktok->setEventProvider($eventProvider);
        $router->loadMethods($tiktok);

        $imgur = new imgur($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:imgur", [$logHandler]), $nicks, $chans, $router);
        $imgur->setEventProvider($eventProvider, $linktitles);
        $router->loadMethods($imgur);

        $client->on('welcome', function (WelcomeEvent $e, \Irc\Client $bot) use ($dbBot) {
            foreach (explode("\n", $dbBot->onConnect) as $line) {
                if($line == "")
                    continue;
                $line = str_replace('$me', $bot->getNick(), $line);
                $bot->send($line);
            }
            $join = [];
            foreach ($dbBot->getChannels() as $channel)
                $join[] = $channel->name;
            $bot->join(implode(',', $join));
        });

        EventLoop::repeat(10, function() use ($client, $dbBot) {
            if(!$client->isEstablished()) {
                return;
            }
            if($client->isCurrentNick($dbBot->name)) {
                return;
            }
            $client->nick($dbBot->name);
        });

        $client->on('kick', function (KickEvent $args, \Irc\Client $bot) {
            if ($args->target == $bot->getNick())
                $bot->join($args->chan);
        });

        //Stop abuse from an IRCOP called sylar
        $client->on('mode', function (ModeEvent $args, \Irc\Client $bot) {
            if ($args->target == $bot->getNick()) {
                $adding = true;
                foreach (str_split($args->args[0]) as $mode) {
                    switch ($mode) {
                        case '+':
                            $adding = true;
                            break;
                        case '-':
                            $adding = false;
                            break;
                        case 'd':
                        case 'D':
                            if ($adding)
                                $bot->send("MODE {$bot->getNick()} -{$mode}");
                    }
                }
            }
        });

        $client->on('chat', function (ChatEvent $args, \Irc\Client $bot) use ($alias, $linktitles, $network, $dbBot, $router, $st) {
            try {
                global $config, $entityManager, $ignoreCache;

                $ignored = $ignoreCache->getItem($args->fullhost);
                if (!$ignored->isHit()) {
                    $ignoreRepository = $entityManager->getRepository(Ignore::class);
                    if (count($ignoreRepository->findMatching($args->fullhost, $network)) > 0)
                        $ignored->set(true);
                    else
                        $ignored->set(false);
                    $ignoreCache->save($ignored);
                }
                if ($ignored->get())
                    return;


                if ($st->linktitlesEnabled) {
                    async(fn() => $linktitles->linktitles($bot, $args->nick, $args->chan, $args->identhost, $args->text));
                }

                if ($dbBot->trigger != "") {
                    if (substr($args->text, 0, 1) != $dbBot->trigger) {
                        return;
                    }
                    $text = substr($args->text, 1);
                } elseif ($dbBot->trigger_re != "") {
                    $trig = "/(^{$dbBot->trigger_re}).+$/";
                    if (!preg_match($trig, $args->text, $m)) {
                        return;
                    }
                    $text = substr($args->text, strlen($m[1]));
                } else {
                    echo "No trigger defined\n";
                    return;
                }


                $ar = explode(' ', $text);
                if (array_shift($ar) == 'ping') {
                    $bot->msg($args->chan, "Pong");
                }
                /*
                $ar = explode(' ', $text);
                if (array_shift($ar) == 'test') {
                    $lines = $chans->dump();
                    foreach($lines as $line)
                        $bot->msg($args->nick, $line);
                    return;
                }*/


                $text = explode(' ', $text);
                $cmd = array_shift($text);
                $text = implode(' ', $text);
                if (trim($cmd) == '')
                    return;

                async(function () use ($cmd, $text, $args, $bot, $router, $alias): void {
                    if ($router->cmdExists($cmd)) {
                        try {
                            $router->call($cmd, $text, $args, $bot);
                        } catch (\Exception $e) {
                            $bot->notice($args->nick, $e->getMessage());
                        } catch (\Throwable $e) {
                            echo "Command error for '{$cmd}': " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
                            $bot->notice($args->nick, "error running command :(");
                        }
                    } else {
                        //call other cmd handlers
                        [$invOpts, $posArgs] = extractOptsAndArgs($text);
                        $alias->handleCmd($args, $bot, $cmd, $posArgs, $invOpts);
                    }
                });
            } catch (Exception $e) {
                echo "UNCAUGHT EXCEPTION $e\n";
            }
        });
        $client->on('pm', function (PmEvent $args, \Irc\Client $bot) use ($router) {
            $text = explode(' ', $args->text);
            $cmd = array_shift($text);
            $text = implode(' ', $text);
            if (trim($cmd) == '')
                return;

            try {
                $router->callPriv($cmd, $text, $args, $bot);
            } catch (Exception $e) {
                $bot->notice($args->nick, $e->getMessage());
            }
        });
        $client->go();
        $this->clients[$dbBot->id] = $client;
        $this->bots[$dbBot->id] = $dbBot;
        $this->networks[$dbBot->id] = $network;
        $this->state[$dbBot->id] = $st;
        return $client;
    }

    public function drop(int $botId): void
    {
        $client = $this->clients[$botId] ?? null;
        if ($client === null) return;
        try { $client->sendNow("quit :removed"); } catch (\Throwable $e) {}
        $client->exit();
        unset($this->clients[$botId], $this->bots[$botId], $this->networks[$botId], $this->state[$botId]);
    }

    public function respawn(int $botId): void
    {
        $bot = $this->bots[$botId] ?? null;
        $network = $this->networks[$botId] ?? null;
        if ($bot === null || $network === null) return;
        $this->drop($botId);
        $fresh = $this->em->find(\lolbot\entities\Bot::class, $bot->id);
        if ($fresh !== null) {
            $this->spawn($network, $fresh);
        }
    }

    public function reconnect(int $botId): void
    {
        ($this->clients[$botId] ?? null)?->reconnect();
    }

    public function jump(int $botId): void
    {
        $network = $this->networks[$botId] ?? null;
        $client = $this->clients[$botId] ?? null;
        if ($network === null || $client === null) return;
        $fresh = $this->em->find(\lolbot\entities\Network::class, $network->id);
        if ($fresh === null) return;
        $server = $fresh->selectServer();
        if ($server === null) return;
        $client->setServer($server->address, (string)$server->port, $server->ssl, $server->password, $server->throttle);
        $client->reconnect();
    }

    public function joinChannel(int $botId, string $chan): void
    {
        ($this->clients[$botId] ?? null)?->join($chan);
    }

    public function partChannel(int $botId, string $chan): void
    {
        ($this->clients[$botId] ?? null)?->part($chan);
    }

    public function reloadBot(int $botId): void
    {
        $bot = $this->bots[$botId] ?? null;
        $client = $this->clients[$botId] ?? null;
        if ($bot === null || $client === null) return;
        $this->em->refresh($bot);
        if (!$client->isCurrentNick($bot->name)) {
            $client->setNick($bot->name);
        }
        // trigger/trigger_re are read live from $bot by the chat handler.
        // sasl/bindIp/onConnect need a respawn; log it.
        echo "bot {$bot->id} reloaded (sasl/bindIp/onConnect need /_control/respawn/{$bot->id})\n";
    }

    public function reloadLinktitlesEnabled(int $botId): void
    {
        if (!isset($this->state[$botId]) || !isset($this->bots[$botId])) return;
        $resolver = new \lolbot\config\SettingsResolver($this->em);
        $this->state[$botId]->linktitlesEnabled = $resolver->linktitlesEnabled($this->bots[$botId]->network, null);
    }

    public function apply(\lolbot\config\ConfigChange $c): void
    {
        try {
            switch ($c->entityType) {
                case 'channel':
                    if ($c->action === 'create') {
                        $chan = $this->em->find(\lolbot\entities\Channel::class, $c->id);
                        if ($chan === null) return;
                        $this->joinChannel($chan->bot->id, $chan->name);
                        return;
                    }
                    if ($c->action === 'delete') {
                        $botId = isset($c->data['botId']) && is_int($c->data['botId']) ? $c->data['botId'] : 0;
                        $chanName = is_string($c->data['chan'] ?? null) ? $c->data['chan'] : null;
                        if ($botId && $chanName !== null) $this->partChannel($botId, $chanName);
                        return;
                    }
                    return;
                case 'bot':
                    if ($c->action === 'create') {
                        $bot = $this->em->find(\lolbot\entities\Bot::class, $c->id);
                        if ($bot !== null) $this->spawn($bot->network, $bot);
                        return;
                    }
                    if ($c->action === 'delete') { $this->drop((int)$c->id); return; }
                    if ($c->action === 'update') { $this->reloadBot((int)$c->id); return; }
                    return;
                case 'network':
                    if ($c->action === 'update') {
                        foreach ($this->bots as $bid => $bot) {
                            if ($bot->network->id === $c->id) { $this->em->refresh($bot); }
                        }
                    }
                    return;
                case 'server':
                    if (in_array($c->action, ['create', 'update', 'delete'], true)) {
                        $server = ($c->action === 'delete') ? null : $this->em->find(\lolbot\entities\Server::class, $c->id);
                        $network = $server?->network;
                        if ($network === null) return;
                        foreach ($this->bots as $bid => $bot) {
                            if ($bot->network->id === $network->id) { $this->jump($bid); }
                        }
                    }
                    return;
                case 'ignore':
                    return; // ignore cache is 5s TTL; auto-applies.
                case 'service:ai':
                case 'service:paste':
                    return; // consumers read per-use; live already.
                case 'linktitles_setting':
                    if ($c->id !== null) {
                        $setting = $this->em->find(\scripts\linktitles\entities\linktitles_setting::class, $c->id);
                        $net = $setting?->network;
                        foreach ($this->bots as $bid => $bot) {
                            if ($net === null || $bot->network->id === $net->id) { $this->reloadLinktitlesEnabled($bid); }
                        }
                    } else {
                        foreach ($this->bots as $bid => $_) { $this->reloadLinktitlesEnabled($bid); }
                    }
                    return;
            }
        } catch (\Throwable $e) {
            echo "live-sync apply error for {$c->entityType}/{$c->action}: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Live runtime status for one bot, read from its \Irc\Client.
     *
     * @return array{id:int,name:string,network:string,connected:bool,nick:string,channels:list<string>,server:string}|null
     */
    public function botStatus(int $botId): ?array
    {
        $client = $this->clients[$botId] ?? null;
        $bot = $this->bots[$botId] ?? null;
        if ($client === null || $bot === null) {
            return null;
        }
        return [
            'id' => $bot->id,
            'name' => $bot->name,
            'network' => $bot->network->name,
            'connected' => $client->isEstablished(),
            'nick' => $client->getNick(),
            'channels' => array_values($client->getJoinedChannels()),
            'server' => $client->getServerDesc(),
        ];
    }

    /**
     * @return list<array{id:int,name:string,network:string,connected:bool,nick:string,channels:list<string>,server:string}>
     */
    public function allBotStatuses(): array
    {
        $out = [];
        foreach (array_keys($this->clients) as $botId) {
            $s = $this->botStatus((int)$botId);
            if ($s !== null) {
                $out[] = $s;
            }
        }
        return $out;
    }
}
