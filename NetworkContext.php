<?php

use Amp\Future;
use function Amp\async;
use knivey\cmdr\Cmdr;
use knivey\irctools;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Irc\Event\ChatEvent;

class NetworkContext
{
    /** @var \SplObjectStorage<\Irc\Client, self> */
    private static \SplObjectStorage $registry;

    /** @var array<string, mixed> */
    public array $config;
    /** @var list<\Irc\Client> */
    public array $clients = [];
    /** @var array<string, list<string>> */
    public array $playing = [];
    public ?Nicks $nicks = null;
    public Cmdr $router;
    public ArrayAdapter $ignoreCache;
    /** @var array<string, mixed> */
    public array $recordings = [];
    /** @var array<string, mixed> */
    public array $allowedPumps = [];
    /** @var array<string, mixed> */
    public array $recordTokens = [];
    /** @var array<string, int> */
    public array $recordLimit = [];
    /** @var array<string, int> */
    public array $limitWarns = [];
    /** @var array<string, int> */
    public array $trashLimit = [];
    /** @var array<string, int> */
    public array $trashLimitWarns = [];
    /** @var array<string, array{chan: string, lines: list<string>, timeOut: string, keeptimes: bool}> */
    public array $quoteRecordings = [];
    public string $name;
    public int $networkId;
    public string $route = '';
    public string $restUrl = '';
    public bool $quotesDbInit = false;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->name = $config['name'];
        $this->networkId = $config['network_id'];
        $this->ignoreCache = new ArrayAdapter(defaultLifetime: 5, storeSerialized: false, maxLifetime: 10, maxItems: 100);
    }

    public static function register(\Irc\Client $bot, self $ctx): void
    {
        if (!isset(self::$registry)) {
            self::$registry = new \SplObjectStorage();
        }
        self::$registry->attach($bot, $ctx);
    }

    public static function get(\Irc\Client $bot): self
    {
        if (!isset(self::$registry)) {
            throw new \RuntimeException("NetworkContext registry not initialized");
        }
        return self::$registry[$bot];
    }

    /** @return list<self> */
    public static function getAll(): array
    {
        if (!isset(self::$registry)) {
            return [];
        }
        $contexts = [];
        foreach (self::$registry as $bot) {
            $ctx = self::$registry->getInfo();
            if (!in_array($ctx, $contexts, true)) {
                $contexts[] = $ctx;
            }
        }
        return $contexts;
    }

    public function canRun(object $args): bool
    {
        if (isset($this->config['artMinAccess'])) {
            if (!is_string($this->config['artMinAccess']) ||
                strlen($this->config['artMinAccess']) > 1 ||
                !str_contains('~&@%+', $this->config['artMinAccess'])
            ) {
                echo "artMinAccess configured incorrectly, must be one of ~&@%+\n";
                return false;
            }
            switch ($this->config['artMinAccess']) {
                case '~':
                    return $this->nicks->isOwner($args->nick, $args->chan);
                case '&':
                    return $this->nicks->isAdminOrHigher($args->nick, $args->chan);
                case '@':
                    return $this->nicks->isOpOrHigher($args->nick, $args->chan);
                case '%':
                    return $this->nicks->isHalfOpOrHigher($args->nick, $args->chan);
                case '+':
                    return $this->nicks->isVoiceOrHigher($args->nick, $args->chan);
            }
        }
        return true;
    }

    /**
     * @param list<string> $data
     * @return Future<void>|null
     */
    public function pumpToChan(string $chan, array $data, ?string $speed = null): ?Future
    {
        $chan = strtolower($chan);
        if (isset($this->playing[$chan])) {
            array_push($this->playing[$chan], ...$data);
        } else {
            $this->playing[$chan] = $data;
            return $this->startPump($chan, $speed);
        }
        return null;
    }

    /** @return Future<void> */
    public function startPump(string $chan, ?string $speed = null): Future
    {
        return async(function () use ($chan, $speed) {
            $chan = strtolower($chan);
            if (!isset($this->playing[$chan])) {
                echo "startPump but chan not in array?\n";
                return;
            }
            $this->playing[$chan] = array_filter($this->playing[$chan]);
            if (count($this->playing[$chan]) > 9001) {
                $this->playing[$chan] = [$this->playing[$chan][0], "that arts too big for this network"];
            }

            if (count($this->clients) == 1) {
                $bot = $this->clients[0];
                while (!empty($this->playing[$chan])) {
                    $bot->pm($chan, irctools\fixColors(array_shift($this->playing[$chan])));
                    $pumpLag = $this->config['pumplag'] ?? 0.025;
                    if ($speed)
                        $pumpLag = max($pumpLag, $speed);
                    \Amp\delay($pumpLag);
                }
                unset($this->playing[$chan]);
                return;
            }

            $bot = null;
            $nextbot = null;
            while (!empty($this->playing[$chan])) {
                $botson = $this->botsOnChan($chan);
                if ($botson < 2) {
                    unset($this->playing[$chan]);
                    echo "Stopping pump to $chan, not enough bots left on it\n";
                    return;
                }
                if ($bot == null) {
                    if (($bot = $this->selectBot($chan)) === false) {
                        unset($this->playing[$chan]);
                        echo "Stopping pump to $chan, no bots left on it\n";
                        return;
                    }
                    if (($nextbot = $this->selectBot($chan)) === false) {
                        unset($this->playing[$chan]);
                        echo "Stopping pump to $chan, not enough bots left on it\n";
                        return;
                    }
                } else {
                    if ($nextbot != null) {
                        $bot = $nextbot;
                        if (($nextbot = $this->selectBot($chan)) === false) {
                            unset($this->playing[$chan]);
                            echo "Stopping pump to $chan, not enough bots left on it\n";
                            return;
                        }
                    }
                }
                $eventIdx = null;
                $def = new \Amp\DeferredFuture();
                $botNick = $bot->getNick();
                $sendAmount = 4;
                if (count($this->playing[$chan]) < $sendAmount)
                    $sendAmount = count($this->playing[$chan]);
                $cnt = 0;
                $nextbot->on('chat', function (ChatEvent $args, $bot) use ($chan, &$eventIdx, &$def, &$cnt, $botNick, $sendAmount) {
                    if ($args->nick != $botNick)
                        return;
                    if (strtolower($args->chan) != $chan)
                        return;
                    $cnt++;
                    if ($cnt == $sendAmount) {
                        $bot->off('chat', null, $eventIdx);
                        $def->complete();
                    }
                }, $eventIdx);

                foreach (range(0, $sendAmount - 1) as $x) {
                    if (isset($this->playing[$chan]) && !empty($this->playing[$chan])) {
                        $line = array_shift($this->playing[$chan]);
                        $bot->pm($chan, irctools\fixColors($line));
                        $delay = 550 / $botson;
                        if ($delay < 85)
                            $delay = 85;
                        if ($speed) {
                            $delay = max($delay, $speed);
                        }
                        \Amp\delay($delay / 1000);
                    }
                }
                try {
                    $def->getFuture()->await(new \Amp\TimeoutCancellation(8));
                } catch (\Amp\CancelledException|\Amp\TimeoutException|\Exception $e) {
                    echo "Something horrible has happened, timeout on looking for pump lines\n";
                    unset($this->playing[$chan]);
                    $nextbot->off('chat', null, $eventIdx);
                }
            }
            unset($this->playing[$chan]);
        });
    }

    /** @phpstan-impure */
    public function selectBot(string $chan): \Irc\Client|false
    {
        static $current = [];
        $key = $this->name;
        if (!isset($current[$key]))
            $current[$key] = 0;
        $tries = 0;
        $i = $current[$key];
        while ($tries <= count($this->clients)) {
            $i++;
            if ($i == count($this->clients))
                $i = 0;
            if ($this->clients[$i]->onChannel($chan)) {
                $current[$key] = $i;
                return $this->clients[$i];
            }
            $tries++;
        }
        return false;
    }

    public function botsOnChan(string $chan): int
    {
        $cnt = 0;
        foreach ($this->clients as $bot) {
            if ($bot->onChannel($chan))
                $cnt++;
        }
        return $cnt;
    }

    public function getWrapLength(\Irc\Client $bot, string $chan): int
    {
        $size = 0;
        if (!empty($this->clients)) {
            foreach ($this->clients as $b)
                $size = max($size, strlen($b->getNickHost()));
        } else {
            $size = strlen($bot->getNickHost());
        }
        return 500 - $size - strlen(" PRIVMSG $chan :");
    }

    /** @param list<string> $exclude */
    public function getFinder(array $exclude = ['p2u']): \Symfony\Component\Finder\Finder
    {
        $finder = new \Symfony\Component\Finder\Finder();
        $finder->files();
        $finder->in($this->config['artdir'])->exclude($exclude);
        return $finder;
    }

    public function initQuotesDb(): void
    {
        if ($this->quotesDbInit) {
            return;
        }
        if (!isset($this->config['quotedb'])) {
            return;
        }
        \RedBeanPHP\R::addDatabase("quotes_{$this->name}", "sqlite:{$this->config['quotedb']}");
        $this->quotesDbInit = true;
    }
}

/**
 * @param list<string> $data
 * @return Future<void>|null
 */
function pumpToChan(\Irc\Client $bot, string $chan, array $data, ?string $speed = null): ?Future
{
    return NetworkContext::get($bot)->pumpToChan($chan, $data, $speed);
}
