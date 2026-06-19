<?php
namespace lolbot\config;

use Doctrine\ORM\EntityManager;
use lolbot\entities\Bot;
use lolbot\entities\Channel;
use lolbot\entities\Ignore;
use lolbot\entities\Network;
use lolbot\entities\Server;

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
        $this->em->remove($channel);
        $this->em->flush();
        $this->notifier->notify(new ConfigChange('channel', $id, 'delete'));
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
}
