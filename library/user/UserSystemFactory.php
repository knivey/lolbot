<?php

namespace library\user;

use Doctrine\ORM\EntityManager;
use Irc\Client;
use Irc\Event\NickEvent;
use Irc\Event\QuitEvent;
use Irc\Event\WelcomeEvent;
use library\user\engines\AccountTagEngine;
use library\user\engines\HostmaskEngine;
use library\user\engines\ManualEngine;
use library\user\engines\VhostPatternEngine;
use library\user\engines\WhoxEngine;
use lolbot\entities\Network;
use lolbot\entities\User as UserEntity;

/*
 * Builds a network's UserSystem bundle (repos, engine chain from the
 * Network row + the client's advertised capabilities, identity cache,
 * IdentityService) and pins it on the client. Shared by
 * BotManager::spawn() and the scratch-DB verification script so both
 * exercise the same real wiring.
 */

class UserSystemFactory
{
    public function __construct(private EntityManager $em)
    {
    }

    /**
     * Build the bundle for one network+client and store it as the
     * client's userSystem. Also refreshes the per-network static
     * locator maps (IdentityService::$instances / UserRepos::$instances,
     * keyed by network id).
     *
     * Safe to call again on a live client (hot-apply of a network
     * config change): the fresh bundle starts with an EMPTY identity
     * cache, which fails closed and re-resolves lazily. Lifecycle
     * subscriptions installed by wireLifecycle() read the client's
     * current bundle at event time, so they survive a rebuild.
     */
    public function create(Network $network, Client $client): UserSystem
    {
        $users = new DoctrineUserRepo($this->em);
        $masks = new DoctrineUserHostmaskRepo($this->em);
        $repos = new UserRepos($users, $masks);
        $repos->network = $network;

        // vhost patterns ship name-keyed (EngineConfig::defaultPatterns);
        // the engine wants them keyed by network id for this network only
        $patternForNet = EngineConfig::defaultPatterns()[mb_strtolower($network->name)] ?? null;
        $caps = [
            'account-tag' => $client->hasCap('account-tag'),
            'vhost' => $patternForNet !== null,
            'whox' => $client->hasOption('WHOX'),
        ];
        $engines = [];
        foreach (EngineConfig::chain($network->auth_engines, $caps) as $name) {
            $engines[] = match ($name) {
                'account-tag' => new AccountTagEngine($users),
                'vhost' => new VhostPatternEngine(
                    $patternForNet !== null ? [$network->id => $patternForNet] : [],
                    $users,
                ),
                'whox' => new WhoxEngine($client, $users),
                'hostmask' => new HostmaskEngine($masks),
                'manual' => new ManualEngine(),
                // unreachable: chain() already rejected unknown names
                default => throw new \UnexpectedValueException("unknown auth engine '$name'"),
            };
        }

        $cache = new IdentityCache();
        $svc = new IdentityService($cache, $engines);
        IdentityService::$instances[$network->id] = $svc;
        UserRepos::$instances[$network->id] = $repos;

        $bundle = new UserSystem($network, $svc, $repos, $cache, $this->em);
        $client->userSystem = $bundle;
        return $bundle;
    }

    /**
     * Subscribe the identity-cache lifecycle to one client's
     * welcome/nick/quit events. Handlers resolve the bundle from the
     * emitting client at event time (not from a captured bundle) so a
     * hot-applied bundle rebuild keeps the hooks pointed at live state.
     * Call once per client (spawn does); never on rebuilds.
     */
    public function wireLifecycle(Client $client): void
    {
        // new IRC session = new bindings; stale nick reuse must never
        // inherit the previous session's user. The chain is also rebuilt
        // here: capabilities (CAP LS negotiation, 005 isupport incl.
        // WHOX) are only known once welcome arrives — the spawn-time
        // bundle was built blind, so auto-detect needs the advertised
        // caps to pick account-tag/whox. Pinned auth_engines lists are
        // unaffected by caps and survive the rebuild unchanged.
        $client->on('welcome', function (WelcomeEvent $e, Client $bot): void {
            $us = $bot->userSystem;
            if (!$us instanceof UserSystem) {
                return;
            }
            $this->create($us->network, $bot);
            $fresh = $bot->userSystem;
            if ($fresh instanceof UserSystem) {
                $fresh->cache->flushNetwork($fresh->netId());
            }
        });
        $client->on('nick', function (NickEvent $e, Client $bot): void {
            $us = $bot->userSystem;
            if ($us instanceof UserSystem) {
                $us->cache->carryNick($us->netId(), mb_strtolower($e->old), mb_strtolower($e->new));
            }
        });
        $client->on('quit', function (QuitEvent $e, Client $bot): void {
            $us = $bot->userSystem;
            if ($us instanceof UserSystem) {
                $us->cache->drop($us->netId(), mb_strtolower($e->nick));
            }
        });
    }

    /**
     * Register the global Access pieces the acl middleware needs: the
     * 'admin' flag check and the user resolver that pulls the
     * requesting nick's identity via the emitting client's bundle
     * (allowCreate=true — gated commands auto-register services
     * identities). Idempotent: the resolver is network-agnostic (it
     * reads the bundle off the event's client), so re-registering per
     * spawn is harmless. With several bots wired the LAST registration
     * wins but every registration behaves identically.
     */
    public static function wireAccess(): void
    {
        Access::define('admin', fn (object $user): bool => Access::userHasFlag($user, 'admin'));
        Access::userResolver(function (array $extraArgs): ?object {
            $event = null;
            foreach ($extraArgs as $extra) {
                if ($extra instanceof \Irc\Event\UserEvent) {
                    $event = $extra;
                    break;
                }
            }
            if ($event === null) {
                return null;
            }
            $sender = $event->sender;
            if (!$sender instanceof Client) {
                return null;
            }
            $us = $sender->userSystem;
            if (!$us instanceof UserSystem) {
                return null;
            }
            $hit = $us->svc->resolve(new ResolveContext(
                networkId: $us->netId(),
                nick: $event->nick,
                nickLowered: mb_strtolower($event->nick),
                identHost: $event->identhost,
                account: $event->account,
                client: $sender,
                allowCreate: true,
            ));
            if ($hit === null) {
                return null;
            }
            return $us->em->find(UserEntity::class, $hit['user_id']);
        });
    }
}
