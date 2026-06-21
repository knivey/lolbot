<?php
namespace lolbot\config;

use Doctrine\ORM\EntityManager;
use lolbot\entities\AiServiceConfig;
use lolbot\entities\Bot;
use lolbot\entities\Channel;
use lolbot\entities\Ignore;
use lolbot\entities\Network;
use lolbot\entities\PasteServiceConfig;
use lolbot\entities\Server;
use scripts\linktitles\entities\linktitles_setting;

/**
 * Owns all configuration mutations and validation. The DB is the source of truth.
 * After every successful flush it calls ChangeNotifier::notify(); the default
 * NoopChangeNotifier does nothing (Sub-project 2 wires HTTP push).
 */
class ConfigService
{
    public function __construct(
        private EntityManager $em,
        private ChangeNotifier $notifier = new NoopChangeNotifier(),
    ) {}

    // ---------------- Networks ----------------

    public function createNetwork(string $name): Network
    {
        if ($this->em->getRepository(Network::class)->findOneBy(['name' => $name]) !== null) {
            throw new DuplicateNameException("Network already exists with that name");
        }
        $n = new Network();
        $n->name = $name;
        $this->em->persist($n);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('network', $n->id, 'create'));
        return $n;
    }

    public function getNetwork(int $id): ?Network
    {
        return $this->em->getRepository(Network::class)->find($id);
    }

    public function deleteNetwork(Network $network): void
    {
        $id = $network->id;
        $this->em->remove($network);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('network', $id, 'delete'));
    }

    /** @return list<Network> */
    public function listNetworks(): array
    {
        return $this->em->getRepository(Network::class)->findAll();
    }

    // ---------------- Bots ----------------

    public function createBot(Network $network, string $name): Bot
    {
        if (!isset($network->id)) {
            throw new NotFoundException("Network does not exist (no id)");
        }
        $managed = $this->em->find(Network::class, $network->id);
        if ($managed === null) {
            throw new NotFoundException("Network not found by id {$network->id}");
        }
        $bot = new Bot();
        $bot->name = $name;
        $bot->network = $managed;
        $this->em->persist($bot);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('bot', $bot->id, 'create'));
        return $bot;
    }

    public function getBot(int $id): ?Bot
    {
        return $this->em->getRepository(Bot::class)->find($id);
    }

    /** @return list<Bot> */
    public function listBots(): array
    {
        return $this->em->getRepository(Bot::class)->findAll();
    }

    public function deleteBot(Bot $bot): void
    {
        $id = $bot->id;
        $this->em->remove($bot);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('bot', $id, 'delete'));
    }

    public function addChannel(Bot $bot, string $name): Channel
    {
        $channel = new Channel();
        $channel->name = $name;
        $bot->addChannel($channel);
        $this->em->persist($channel);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('channel', $channel->id, 'create'));
        return $channel;
    }

    public function deleteChannel(Channel $channel): void
    {
        $id = $channel->id;
        $botId = isset($channel->bot) ? ($channel->bot->id ?? null) : null;
        $chanName = $channel->name;
        $this->em->remove($channel);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('channel', $id, 'delete', [
            'botId' => $botId,
            'chan' => $chanName,
        ]));
    }

    // ---------------- Servers ----------------

    public function addServer(
        Network $network,
        string $address,
        ?int $port = null,
        bool $ssl = false,
        bool $throttle = true,
        ?string $password = null,
    ): Server {
        $server = new Server();
        $server->address = $address;
        $server->setNetwork($network);
        if ($port !== null) {
            if ($port <= 0 || $port > 65535) {
                throw new InvalidSettingException("Invalid port");
            }
            $server->port = $port;
        }
        $server->ssl = $ssl;
        $server->throttle = $throttle;
        if ($password !== null) {
            $server->password = $password;
        }
        $this->em->persist($server);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('server', $server->id, 'create'));
        return $server;
    }

    public function getServer(int $id): ?Server
    {
        return $this->em->getRepository(Server::class)->find($id);
    }

    public function deleteServer(Server $server): void
    {
        $id = $server->id;
        $this->em->remove($server);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('server', $id, 'delete'));
    }

    // ---------------- Ignores ----------------

    /**
     * @param list<Network> $networks
     */
    public function addIgnore(string $hostmask, ?string $reason, array $networks): Ignore
    {
        $ignore = new Ignore();
        $ignore->hostmask = $hostmask;
        if ($reason !== null) {
            $ignore->reason = $reason;
        }
        foreach ($networks as $network) {
            if (!isset($network->id) || !$this->em->contains($network)) {
                throw new NotFoundException("Network not found (id=" . (isset($network->id) ? $network->id : 'null') . ")");
            }
            $ignore->addToNetwork($network);
        }
        $this->em->persist($ignore);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('ignore', $ignore->id, 'create'));
        return $ignore;
    }

    /**
     * @param list<Network> $networks
     */
    public function addIgnoreNetworks(Ignore $ignore, array $networks): void
    {
        foreach ($networks as $network) {
            if (!isset($network->id) || !$this->em->contains($network)) {
                throw new NotFoundException("Network not found (id=" . (isset($network->id) ? $network->id : 'null') . ")");
            }
            $ignore->addToNetwork($network);
        }
        $this->em->persist($ignore);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('ignore', $ignore->id, 'update'));
    }

    public function getIgnore(int $id): ?Ignore
    {
        return $this->em->getRepository(Ignore::class)->find($id);
    }

    /** @return list<Ignore> */
    public function listIgnores(): array
    {
        return $this->em->getRepository(Ignore::class)->findAll();
    }

    public function deleteIgnore(Ignore $ignore): void
    {
        $id = $ignore->id;
        $this->em->remove($ignore);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('ignore', $id, 'delete'));
    }

    // ---------------- Service config (global singletons) ----------------

    /** Known writable keys per service type (entity property names). */
    private const SERVICE_KEYS = [
        'ai' => ['apiKey', 'baseUrl', 'maxDim', 'jpgQuality', 'timeout'],
        'paste' => ['host', 'key'],
    ];

    public function setServiceConfigValue(string $type, string $key, mixed $value): void
    {
        $class = (new ServiceLocator($this->em))->entityClassFor($type);
        if ($class === null) {
            throw new InvalidSettingException("Unknown service type: $type");
        }
        if (!in_array($key, self::SERVICE_KEYS[$type] ?? [], true)) {
            throw new InvalidSettingException("Unknown setting '$key' for service '$type'");
        }
        $entity = $this->findOrCreateServiceEntity($type);
        $this->applyServiceValue($entity, $key, $value);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('service:' . $type, $entity->id, 'update'));
    }

    /** Returns the singleton row, creating it if none exists yet. */
    private function findOrCreateServiceEntity(string $type): AiServiceConfig|PasteServiceConfig
    {
        if ($type === 'ai') {
            $existing = $this->em->getRepository(AiServiceConfig::class)->findAll()[0] ?? null;
            if ($existing !== null) {
                return $existing;
            }
            $new = new AiServiceConfig();
            $this->em->persist($new);
            return $new;
        }
        $existing = $this->em->getRepository(PasteServiceConfig::class)->findAll()[0] ?? null;
        if ($existing !== null) {
            return $existing;
        }
        $new = new PasteServiceConfig();
        $this->em->persist($new);
        return $new;
    }

    /** Validates the value's type and writes it to the matching typed property. */
    private function applyServiceValue(AiServiceConfig|PasteServiceConfig $entity, string $key, mixed $value): void
    {
        if ($entity instanceof AiServiceConfig) {
            match ($key) {
                'apiKey' => $entity->apiKey = is_string($value) || $value === null ? $value : throw new InvalidSettingException("apiKey must be a string or null"),
                'baseUrl' => $entity->baseUrl = is_string($value) || $value === null ? $value : throw new InvalidSettingException("baseUrl must be a string or null"),
                'maxDim' => $entity->maxDim = is_int($value) ? $value : throw new InvalidSettingException("maxDim must be an int"),
                'jpgQuality' => $entity->jpgQuality = is_int($value) ? $value : throw new InvalidSettingException("jpgQuality must be an int"),
                'timeout' => $entity->timeout = is_int($value) ? $value : throw new InvalidSettingException("timeout must be an int"),
                default => throw new \LogicException('unreachable: whitelist mismatch'),
            };
            return;
        }
        match ($key) {
            'host' => $entity->host = is_string($value) || $value === null ? $value : throw new InvalidSettingException("host must be a string or null"),
            'key' => $entity->key = is_string($value) || $value === null ? $value : throw new InvalidSettingException("key must be a string or null"),
            default => throw new \LogicException('unreachable: whitelist mismatch'),
        };
    }

    /**
     * Validates that $value is null or an array keyed by strings, returning a
     * provably string-keyed array (so it satisfies array<string, mixed> properties).
     *
     * @return array<string, mixed>|null
     */
    private function normalizeStringKeyedArray(mixed $value, string $field): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new InvalidSettingException("$field must be an array or null");
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (!is_string($k)) {
                throw new InvalidSettingException("$field must be keyed by strings");
            }
            $out[$k] = $v;
        }
        return $out;
    }

    // ---------------- Script settings (linktitles) ----------------

    /** Writable linktitles_setting keys (property names). */
    private const LINKTITLES_KEYS = [
        'enabled', 'url_log_chan', 'ai_vision_disabled',
        'ai_vision_model', 'ai_vision_prompt',
        'ai_vision_reasoning_effort', 'ai_vision_reasoning',
    ];

    private function findOrCreateLinktitlesSetting(?Network $network, ?Channel $channel): linktitles_setting
    {
        $repo = $this->em->getRepository(linktitles_setting::class);
        $setting = $repo->findOneBy([
            'network' => $network,
            'channel' => $channel,
        ]);
        if ($setting === null) {
            $setting = new linktitles_setting();
            $setting->network = $network;
            $setting->channel = $channel;
            $this->em->persist($setting);
        }
        return $setting;
    }

    public function setLinktitlesSetting(?Network $network, ?Channel $channel, string $key, mixed $value): linktitles_setting
    {
        if (!in_array($key, self::LINKTITLES_KEYS, true)) {
            throw new InvalidSettingException("Unknown linktitles setting: $key");
        }
        $setting = $this->findOrCreateLinktitlesSetting($network, $channel);
        $this->applyLinktitlesValue($setting, $key, $value);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('linktitles_setting', $setting->id, 'update'));
        return $setting;
    }

    /** Validates the value's type and writes it to the matching typed property. */
    private function applyLinktitlesValue(linktitles_setting $setting, string $key, mixed $value): void
    {
        match ($key) {
            'enabled' => $setting->enabled = is_bool($value) ? $value : throw new InvalidSettingException("enabled must be a bool"),
            'url_log_chan' => $setting->url_log_chan = is_string($value) || $value === null ? $value : throw new InvalidSettingException("url_log_chan must be a string or null"),
            'ai_vision_disabled' => $setting->ai_vision_disabled = is_bool($value) ? $value : throw new InvalidSettingException("ai_vision_disabled must be a bool"),
            'ai_vision_model' => $setting->ai_vision_model = is_string($value) || $value === null ? $value : throw new InvalidSettingException("ai_vision_model must be a string or null"),
            'ai_vision_prompt' => $setting->ai_vision_prompt = is_string($value) || $value === null ? $value : throw new InvalidSettingException("ai_vision_prompt must be a string or null"),
            'ai_vision_reasoning_effort' => $setting->ai_vision_reasoning_effort = is_string($value) || $value === null ? $value : throw new InvalidSettingException("ai_vision_reasoning_effort must be a string or null"),
            'ai_vision_reasoning' => $setting->ai_vision_reasoning = $this->normalizeStringKeyedArray($value, 'ai_vision_reasoning'),
            default => throw new \LogicException('unreachable: whitelist mismatch'),
        };
    }

    public function resetLinktitlesSetting(?Network $network, ?Channel $channel, string $key): void
    {
        if (!in_array($key, self::LINKTITLES_KEYS, true)) {
            throw new InvalidSettingException("Unknown linktitles setting: $key");
        }
        $repo = $this->em->getRepository(linktitles_setting::class);
        $setting = $repo->findOneBy([
            'network' => $network,
            'channel' => $channel,
        ]);
        if ($setting !== null) {
            // A reset means "inherit": clear the field to null so resolution
            // falls through to the next tier. $key is narrowed to LINKTITLES_KEYS
            // by the in_array guard above.
            match ($key) {
                'enabled' => $setting->enabled = null,
                'ai_vision_disabled' => $setting->ai_vision_disabled = null,
                'url_log_chan' => $setting->url_log_chan = null,
                'ai_vision_model' => $setting->ai_vision_model = null,
                'ai_vision_prompt' => $setting->ai_vision_prompt = null,
                'ai_vision_reasoning_effort' => $setting->ai_vision_reasoning_effort = null,
                'ai_vision_reasoning' => $setting->ai_vision_reasoning = null,
            };
            $this->em->flush();
            $this->notifier->notify(new ConfigChange('linktitles_setting', $setting->id, 'update'));
        }
    }

    /**
     * Generic persistence + notify for the *:set commands, which assign a
     * whitelisted property on an already-managed entity then call this.
     */
    public function update(object $entity, string $type): void
    {
        $this->em->persist($entity);
        $this->em->flush();
        $id = property_exists($entity, 'id') ? ($entity->id ?? null) : null;
        $this->notifier->notify(new ConfigChange($type, $id, 'update'));
    }

    /**
     * Remove the whole linktitles_setting row for a scope (the reset/inherit
     * semantics of `linktitles:set`). No-op if no row exists. Fires notify.
     */
    public function deleteLinktitlesSettingScope(?Network $network, ?Channel $channel): void
    {
        $repo = $this->em->getRepository(linktitles_setting::class);
        $setting = $repo->findOneBy([
            'network' => $network,
            'channel' => $channel,
        ]);
        if ($setting === null) {
            return;
        }
        $id = $setting->id;
        $this->em->remove($setting);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('linktitles_setting', $id, 'delete'));
    }

    /**
     * Persist a linktitles_setting that the caller already populated, and fire
     * notify. Used by linktitles:set so its mutations participate in live-sync.
     */
    public function saveLinktitlesSetting(linktitles_setting $setting): linktitles_setting
    {
        $this->em->persist($setting);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('linktitles_setting', $setting->id, 'update'));
        return $setting;
    }
}

/**
 * Build the ChangeNotifier for a CLI/web mutating client:
 * HttpPushChangeNotifier when config.yaml has both `listen` and `control_key`,
 * else NoopChangeNotifier (so the CLI works when the bot isn't running).
 */
function build_change_notifier(): ChangeNotifier
{
    /** @var array<string, mixed> $config */
    global $config;
    $listen = isset($config['listen']) && is_string($config['listen']) ? $config['listen'] : null;
    $key = isset($config['control_key']) && is_string($config['control_key']) ? $config['control_key'] : null;
    if ($listen !== null && $key !== null) {
        return new HttpPushChangeNotifier('http://' . $listen, $key);
    }
    return new NoopChangeNotifier();
}
