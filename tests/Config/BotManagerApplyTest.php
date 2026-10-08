<?php

namespace Tests\Config;

use lolbot\config\ConfigChange;
use lolbot\config\ConfigService;
use lolbot\entities\Bot;
use lolbot\entities\Network;
use library\BotManager;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../library/Nicks.php';
require_once __DIR__ . '/../../library/Channels.php';

class BotManagerApplyTest extends ConfigTestCase
{
    protected function tearDown(): void
    {
        // The spawn tests set bot-runtime globals; don't leak a closed EM or
        // stubs into later tests in this process.
        unset($GLOBALS['logHandler'], $GLOBALS['config'], $GLOBALS['entityManager'], $GLOBALS['ignoreCache']);
        parent::tearDown();
    }

    /**
     * @return array{0: BotManager, 1: \PHPUnit\Framework\MockObject\MockObject&\Irc\Client}
     */
    private function mgrWithBot(Network $net, Bot $bot): array
    {
        $mgr = new BotManager($this->em);
        $client = $this->createMock(\Irc\Client::class);
        $mgr->clients[$bot->id] = $client;
        $mgr->bots[$bot->id] = $bot;
        $mgr->networks[$bot->id] = $net;
        $mgr->state[$bot->id] = new \stdClass();
        return [$mgr, $client];
    }

    public function test_channel_create_joins(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        $chan = $svc->addChannel($bot, '#test');
        [$mgr, $client] = $this->mgrWithBot($net, $bot);
        $client->expects($this->once())->method('join')->with('#test');
        $mgr->apply(new ConfigChange('channel', $chan->id, 'create'));
    }

    public function test_channel_delete_parts(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        [$mgr, $client] = $this->mgrWithBot($net, $bot);
        $client->expects($this->once())->method('part')->with('#gone');
        $mgr->apply(new ConfigChange('channel', 0, 'delete', ['botId' => $bot->id, 'chan' => '#gone']));
    }

    public function test_bot_update_reloads_nick_when_changed(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'oldname');
        [$mgr, $client] = $this->mgrWithBot($net, $bot);
        $bot->name = 'newname';
        $this->em->flush();
        $client->expects($this->once())->method('setNick')->with('newname');
        $mgr->apply(new ConfigChange('bot', $bot->id, 'update'));
    }

    public function test_server_update_triggers_jump_for_network_bots(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        $srv = $svc->addServer($net, 'irc.example.net', 6667, false, true, null);
        [$mgr, $client] = $this->mgrWithBot($net, $bot);
        $client->expects($this->once())->method('reconnect');
        $mgr->apply(new ConfigChange('server', $srv->id, 'update'));
    }

    public function test_server_delete_triggers_jump_for_network_bots(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        $keep = $svc->addServer($net, 'irc-keep.example.net', 6667, false, true, null);
        $gone = $svc->addServer($net, 'irc-gone.example.net', 6667, false, true, null);
        [$mgr, $client] = $this->mgrWithBot($net, $bot);
        $client->expects($this->once())->method('setServer')
            ->with('irc-keep.example.net', '6667', false, null, true);
        $client->expects($this->once())->method('reconnect');

        $goneId = $gone->id;
        $svc->deleteServer($gone);

        // The push the notifier delivers for the delete: the row is gone, so
        // the network id travels in the data bag.
        $mgr->apply(new ConfigChange('server', $goneId, 'delete', ['networkId' => $net->id]));
    }

    /**
     * @return array{0: string, 1: string|null} The address and port jump() targeted.
     */
    private function jumpAndCaptureServer(Network $net, Bot $bot): array
    {
        $mgr = new BotManager($this->em);
        $client = $this->createStub(\Irc\Client::class);
        $captured = [];
        $client->method('setServer')->willReturnCallback(
            /** @param list<mixed> $args */
            function (mixed ...$args) use (&$captured, $client): \Irc\Client {
                $captured = $args;
                return $client;
            }
        );
        $mgr->clients[$bot->id] = $client;
        $mgr->bots[$bot->id] = $bot;
        $mgr->networks[$bot->id] = $net;
        $mgr->state[$bot->id] = new \stdClass();
        $mgr->jump($bot->id);
        /** @var list<mixed> $captured */
        $address = $captured[0] ?? null;
        return [is_string($address) ? $address : '', isset($captured[1]) && is_string($captured[1]) ? $captured[1] : null];
    }

    public function test_jump_uses_fresh_server_list_after_out_of_band_replacement(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        $old = $svc->addServer($net, 'old.example.net', 6667, false, true, null);

        // Warm the servers collection (spawn() initializes it via selectServer()).
        $net->getServers()->toArray();

        // Out-of-band: replace the only server (another process).
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM Servers WHERE id = ?', [$old->id]);
        $conn->executeStatement(
            'INSERT INTO Servers (address, port, ssl, throttle, network_id) VALUES (?, 6697, 1, 1, ?)',
            ['new.example.net', $net->id]
        );

        [$address] = $this->jumpAndCaptureServer($net, $bot);
        $this->assertSame('new.example.net', $address);
    }

    public function test_jump_uses_fresh_server_fields_after_out_of_band_edit(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        $srv = $svc->addServer($net, 'old.example.net', 6667, false, true, null);

        // Warm the servers collection (spawn() initializes it via selectServer()).
        $net->getServers()->toArray();

        // Out-of-band address/port edit (another process).
        $this->em->getConnection()->executeStatement(
            'UPDATE Servers SET address = ?, port = ? WHERE id = ?',
            ['new.example.net', 6697, $srv->id]
        );

        [$address, $port] = $this->jumpAndCaptureServer($net, $bot);
        $this->assertSame('new.example.net', $address);
        $this->assertSame('6697', $port);
    }

    public function test_spawn_uses_fresh_server_list_after_out_of_band_replacement(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot1 = $svc->createBot($net, 'b1');
        $bot2 = $svc->createBot($net, 'b2');
        $old = $svc->addServer($net, 'old.example.net', 6667, false, true, null);

        // First spawn warms the network's servers collection (selectServer()
        // initializes it) and leaves the entities managed, as in a running bot.
        $GLOBALS['logHandler'] = $this->createStub(\Monolog\Handler\HandlerInterface::class);
        $GLOBALS['config'] = [];
        $GLOBALS['entityManager'] = $this->em;
        $mgr = new BotManager($this->em);
        $client1 = $mgr->spawn($net, $bot1);
        $this->assertSame('old.example.net:6667', $client1->getServerDesc());

        // Out-of-band: replace the only server (another process).
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM Servers WHERE id = ?', [$old->id]);
        $conn->executeStatement(
            'INSERT INTO Servers (address, port, ssl, throttle, network_id) VALUES (?, 6697, 1, 1, ?)',
            ['new.example.net', $net->id]
        );

        // A bot spawned after the change must target the new server.
        $client2 = $mgr->spawn($net, $bot2);
        $this->assertSame('new.example.net:6697 ssl', $client2->getServerDesc());
    }

    public function test_linktitles_setting_update_clears_per_channel_gate_cache(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        $mgr = new BotManager($this->em);
        $mgr->clients[$bot->id] = $this->createStub(\Irc\Client::class);
        $mgr->bots[$bot->id] = $bot;
        $mgr->networks[$bot->id] = $net;
        $mgr->state[$bot->id] = new \stdClass();
        // stale seeded cache entry as a warmed gate would leave behind
        $mgr->state[$bot->id]->linktitlesEnabled = [123 => true];
        $svc->setLinktitlesSetting($net, null, 'enabled', true);
        // Find the linktitles_setting id so apply can resolve the network.
        $setting = $this->em->getRepository(\scripts\linktitles\entities\linktitles_setting::class)->findOneBy(['network' => $net]);
        $this->assertNotNull($setting);
        $mgr->apply(new ConfigChange('linktitles_setting', $setting->id, 'update'));
        // clear-on-change: the next chat event re-resolves its channel
        $this->assertSame([], $mgr->state[$bot->id]->linktitlesEnabled);
    }

    /**
     * Spawns a real bot (as the fresh-server-list test does) with the globals
     * the chat handler reads and a fresh event-loop driver, so the async
     * dispatch fibers this test drains stay scoped to this test.
     *
     * @return array{0: BotManager, 1: \Irc\Client, 2: LinktitlesSpyConfig}
     */
    private function spawnWithChatHarness(Network $net, Bot $bot): array
    {
        \Revolt\EventLoop::setDriver(new \Revolt\EventLoop\Driver\StreamSelectDriver());
        $spy = new LinktitlesSpyConfig();
        // spawn hands $config to script constructors typed as array, so the
        // real array goes in; the spy takes over the global afterwards and
        // linktitles() re-reads `global $config` per dispatched call.
        $GLOBALS['logHandler'] = $this->createStub(\Monolog\Handler\HandlerInterface::class);
        $GLOBALS['config'] = [];
        $GLOBALS['entityManager'] = $this->em;
        // same cache lolbot.php pins for the chat handler's ignore check
        $GLOBALS['ignoreCache'] = new \Symfony\Component\Cache\Adapter\ArrayAdapter(
            defaultLifetime: 5,
            storeSerialized: false,
            maxLifetime: 10,
            maxItems: 100,
        );
        $mgr = new BotManager($this->em);
        // tell's chat listener (registered during spawn) queries its table on
        // the global EM; create it on the scratch EM so emitted chat events
        // pass through cleanly.
        (new \Doctrine\ORM\Tools\SchemaTool($this->em))
            ->createSchema([$this->em->getClassMetadata(\scripts\tell\entities\tell::class)]);
        $client = $mgr->spawn($net, $bot);
        $GLOBALS['config'] = $spy;
        return [$mgr, $client, $spy];
    }

    private function emitChat(\Irc\Client $client, string $chan, string $text): void
    {
        $client->emit('chat', new \Irc\Event\ChatEvent(
            time(),
            'chat',
            $client,
            'nick',
            'ident',
            'host',
            'ident@host',
            'nick!ident@host',
            $chan,
            $text,
        ));
    }

    /**
     * Run the queued microtasks (the chat gate dispatches linktitles via
     * async(), which queues one) exactly once, then stop the loop before
     * spawn's nick-repeat watcher can spin it forever.
     */
    private function drainAsyncDispatch(): void
    {
        \Revolt\EventLoop::defer(static fn () => \Revolt\EventLoop::getDriver()->stop());
        \Revolt\EventLoop::run();
    }

    public function test_chat_linktitles_gate_is_channel_tier_aware(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        $chanA = $svc->addChannel($bot, '#a');
        $chanB = $svc->addChannel($bot, '#b');
        $svc->addServer($net, '127.0.0.1', 1, false, true, null);
        $bot->trigger = '.';
        $this->em->flush();
        $svc->setLinktitlesSetting($net, null, 'enabled', true);
        // channel A opts out at its own tier; B has no row (inherits network)
        $svc->setLinktitlesSetting($net, $chanA, 'enabled', false);
        [$mgr, $client, $spy] = $this->spawnWithChatHarness($net, $bot);

        $this->emitChat($client, '#a', 'look https://example.com/a');
        $this->drainAsyncDispatch();
        $this->assertSame(0, $spy->rateLimitReads, 'channel-tier disabled must gate linktitles off in #a');

        $this->emitChat($client, '#b', 'look https://example.com/b');
        $this->drainAsyncDispatch();
        $this->assertSame(1, $spy->rateLimitReads, 'channel without its own row inherits the network tier in #b');

        // the gate cache resolved per channel through the real handler
        $this->assertFalse($mgr->state[$bot->id]->linktitlesEnabled[$chanA->id]);
        $this->assertTrue($mgr->state[$bot->id]->linktitlesEnabled[$chanB->id]);
    }

    public function test_chat_linktitles_gate_channel_tier_overrides_disabled_network(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        $chanA = $svc->addChannel($bot, '#a');
        $svc->addServer($net, '127.0.0.1', 1, false, true, null);
        $bot->trigger = '.';
        $this->em->flush();
        $svc->setLinktitlesSetting($net, null, 'enabled', false);
        // channel A opts back in over the disabled network tier
        $svc->setLinktitlesSetting($net, $chanA, 'enabled', true);
        [$mgr, $client, $spy] = $this->spawnWithChatHarness($net, $bot);

        $this->emitChat($client, '#a', 'look https://example.com/a');
        $this->drainAsyncDispatch();
        $this->assertSame(1, $spy->rateLimitReads, 'channel-tier enabled must gate linktitles on in #a despite the network tier');

        $this->assertTrue($mgr->state[$bot->id]->linktitlesEnabled[$chanA->id]);
    }

    public function test_chat_linktitles_gate_reload_follows_flipped_channel_row(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        $chanA = $svc->addChannel($bot, '#a');
        $svc->addServer($net, '127.0.0.1', 1, false, true, null);
        $bot->trigger = '.';
        $this->em->flush();
        $svc->setLinktitlesSetting($net, null, 'enabled', true);
        $svc->setLinktitlesSetting($net, $chanA, 'enabled', false);
        [$mgr, $client, $spy] = $this->spawnWithChatHarness($net, $bot);

        $this->emitChat($client, '#a', 'look https://example.com/a');
        $this->drainAsyncDispatch();
        $this->assertSame(0, $spy->rateLimitReads);

        // flip the channel-tier row out-of-band, then deliver the push
        $svc->setLinktitlesSetting($net, $chanA, 'enabled', true);
        $setting = $this->em->getRepository(\scripts\linktitles\entities\linktitles_setting::class)->findOneBy(['channel' => $chanA]);
        $this->assertNotNull($setting);
        $mgr->apply(new ConfigChange('linktitles_setting', $setting->id, 'update'));
        $this->assertSame([], $mgr->state[$bot->id]->linktitlesEnabled, 'reload must clear the cached channel gate');

        $this->emitChat($client, '#a', 'look https://example.com/a2');
        $this->drainAsyncDispatch();
        $this->assertSame(1, $spy->rateLimitReads, 'next event after reload must follow the flipped channel tier');
    }

    public function test_bot_update_disabled_drops_client(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        [$mgr, $client] = $this->mgrWithBot($net, $bot);
        $client->expects($this->once())->method('sendNow')->with('quit :disabled');
        $client->expects($this->once())->method('exit');
        $bot->disabled = true;
        $this->em->flush();
        $mgr->apply(new ConfigChange('bot', $bot->id, 'update'));
        $this->assertArrayNotHasKey($bot->id, $mgr->clients);
    }

    public function test_bot_update_network_disabled_drops_client(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        [$mgr, $client] = $this->mgrWithBot($net, $bot);
        $client->expects($this->once())->method('exit');
        $net->disabled = true;
        $this->em->flush();
        $mgr->apply(new ConfigChange('bot', $bot->id, 'update'));
        $this->assertArrayNotHasKey($bot->id, $mgr->clients);
    }

    public function test_bot_update_reenable_spawns(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        $bot->disabled = true;
        $this->em->flush();
        $mgr = new RecordingBotManager($this->em, fn(): \Irc\Client => $this->createStub(\Irc\Client::class));

        $mgr->apply(new ConfigChange('bot', $bot->id, 'update'));
        $this->assertSame([], $mgr->spawned);

        $bot->disabled = false;
        $this->em->flush();
        $mgr->apply(new ConfigChange('bot', $bot->id, 'update'));
        $this->assertSame([$bot->id], $mgr->spawned);
        $this->assertArrayHasKey($bot->id, $mgr->clients);
    }

    public function test_bot_create_disabled_does_not_spawn(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        $bot->disabled = true;
        $this->em->flush();
        $mgr = new RecordingBotManager($this->em, fn(): \Irc\Client => $this->createStub(\Irc\Client::class));
        $mgr->apply(new ConfigChange('bot', $bot->id, 'create'));
        $this->assertSame([], $mgr->spawned);
    }

    public function test_network_update_disabled_drops_all_bots(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot1 = $svc->createBot($net, 'b1');
        $bot2 = $svc->createBot($net, 'b2');
        $mgr = new BotManager($this->em);
        foreach ([$bot1, $bot2] as $b) {
            $mgr->clients[$b->id] = $this->createStub(\Irc\Client::class);
            $mgr->bots[$b->id] = $b;
            $mgr->networks[$b->id] = $net;
            $mgr->state[$b->id] = new \stdClass();
        }
        $net->disabled = true;
        $this->em->flush();
        $mgr->apply(new ConfigChange('network', $net->id, 'update'));
        $this->assertArrayNotHasKey($bot1->id, $mgr->clients);
        $this->assertArrayNotHasKey($bot2->id, $mgr->clients);
    }

    public function test_network_delete_drops_held_bots(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot1 = $svc->createBot($net, 'b1');
        $bot2 = $svc->createBot($net, 'b2');
        $mgr = new BotManager($this->em);
        $clients = [];
        foreach ([$bot1, $bot2] as $b) {
            $client = $this->createMock(\Irc\Client::class);
            $client->expects($this->once())->method('sendNow')->with('quit :network deleted');
            $client->expects($this->once())->method('exit');
            $clients[$b->id] = $client;
            $mgr->clients[$b->id] = $client;
            $mgr->bots[$b->id] = $b;
            $mgr->networks[$b->id] = $net;
            $mgr->state[$b->id] = new \stdClass();
        }

        $netId = $net->id;
        $botIds = [$bot1->id, $bot2->id];
        $svc->deleteNetwork($net);

        // The push the notifier delivers: rows are gone via ON DELETE CASCADE,
        // so the bot ids travel in the data bag.
        $mgr->apply(new ConfigChange('network', $netId, 'delete', ['botIds' => $botIds]));

        $this->assertArrayNotHasKey($bot1->id, $mgr->clients);
        $this->assertArrayNotHasKey($bot2->id, $mgr->clients);
        $this->assertArrayNotHasKey($bot1->id, $mgr->bots);
        $this->assertArrayNotHasKey($bot2->id, $mgr->bots);
        $this->assertArrayNotHasKey($bot1->id, $mgr->networks);
        $this->assertArrayNotHasKey($bot2->id, $mgr->networks);
        $this->assertArrayNotHasKey($bot1->id, $mgr->state);
        $this->assertArrayNotHasKey($bot2->id, $mgr->state);
    }

    public function test_network_update_reenable_spawns_only_enabled_bots(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $b1 = $svc->createBot($net, 'b1');
        $b2 = $svc->createBot($net, 'b2');
        $b2->disabled = true;
        $net->disabled = true;
        $this->em->flush();

        $mgr = new RecordingBotManager($this->em, fn(): \Irc\Client => $this->createStub(\Irc\Client::class));
        $mgr->apply(new ConfigChange('network', $net->id, 'update'));
        $this->assertSame([], $mgr->spawned);

        $net->disabled = false;
        $this->em->flush();
        $mgr->apply(new ConfigChange('network', $net->id, 'update'));
        $this->assertSame([$b1->id], $mgr->spawned);
    }

    public function test_network_update_uses_fresh_bot_membership_not_startup_snapshot(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $created = $svc->createBot($net, 'created');
        $gone = $svc->createBot($net, 'gone');
        $netId = $net->id;
        $createdId = $created->id;

        // Simulate lolbot.php startup: load the network fresh and initialize its
        // bots collection (freezes membership at [created, gone]).
        $this->em->clear();
        $net = $this->em->getRepository(\lolbot\entities\Network::class)->find($netId);
        $this->assertNotNull($net);
        $net->getBots()->toArray();

        // Simulate another process (CLI/web): delete one bot, add another.
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM Bots WHERE id = ?', [$gone->id]);
        $conn->executeStatement(
            "INSERT INTO Bots (name, network_id, created, onConnect, bindIp, disabled) VALUES ('added', ?, '2000-01-01 00:00:00', '', '0', 0)",
            [$netId]
        );

        $mgr = new RecordingBotManager($this->em, fn(): \Irc\Client => $this->createStub(\Irc\Client::class));
        $mgr->apply(new ConfigChange('network', $netId, 'update'));

        $fetched = $conn->fetchOne('SELECT id FROM Bots WHERE name = ?', ['added']);
        $addedId = is_numeric($fetched) ? (int) $fetched : 0;
        $this->assertNotSame(0, $addedId);
        // Guards the fresh-membership guarantee: em->refresh($net) must make the
        // network's bots collection re-query, so 'gone' is not ghost-spawned and
        // 'added' (created by another process) is spawned.
        $this->assertSame([$createdId, $addedId], $mgr->spawned);
    }
}

/**
 * ArrayAccess stand-in for $GLOBALS['config'] that makes a dispatched
 * linktitles() run observably reach its rate-limit read: the read is counted
 * and answered with 0, so the fiber stops at the rate limiter (logUrl is a
 * no-op without a url_log_chan) and never performs HTTP. Every other key
 * behaves as absent, matching the empty-array config the spawn tests use.
 *
 * @implements \ArrayAccess<string, mixed>
 */
class LinktitlesSpyConfig implements \ArrayAccess
{
    public int $rateLimitReads = 0;

    public function offsetExists(mixed $offset): bool
    {
        return $offset === 'linktitles_rate_urls' || $offset === 'linktitles_rate_seconds';
    }

    public function offsetGet(mixed $offset): mixed
    {
        if ($offset === 'linktitles_rate_urls') {
            $this->rateLimitReads++;
            return 0;
        }
        return 2;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
    }

    public function offsetUnset(mixed $offset): void
    {
    }
}

/**
 * BotManager whose spawn() is stubbed: records bot ids and registers client
 * doubles from a factory closure instead of constructing real IRC connections.
 */
class RecordingBotManager extends BotManager
{
    /** @var list<int> */
    public array $spawned = [];
    /** @var \Closure(): \Irc\Client */
    private \Closure $clientFactory;

    public function __construct(\Doctrine\ORM\EntityManager $em, \Closure $clientFactory)
    {
        parent::__construct($em);
        $this->clientFactory = $clientFactory;
    }

    public function spawn(\lolbot\entities\Network $network, \lolbot\entities\Bot $dbBot): \Irc\Client
    {
        $this->spawned[] = $dbBot->id;
        $client = ($this->clientFactory)();
        $this->clients[$dbBot->id] = $client;
        $this->bots[$dbBot->id] = $dbBot;
        $this->networks[$dbBot->id] = $network;
        $this->state[$dbBot->id] = new \stdClass();
        return $client;
    }
}
