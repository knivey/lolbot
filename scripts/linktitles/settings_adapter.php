<?php

namespace scripts\linktitles;

use Doctrine\ORM\EntityManager;
use library\settings\Setting;
use library\settings\SettingsRegistry;
use library\settings\SettingStorage;
use lolbot\config\ConfigService;
use lolbot\config\LinktitlesDefaults;
use lolbot\entities\Channel;
use lolbot\entities\Network;
use scripts\linktitles\entities\linktitles_setting;

use function lolbot\config\build_change_notifier;

/*
 * Storage adapter surfacing linktitles' own linktitles_settings table
 * through the central settings registry. This file is required from
 * linktitles.php, so its definitions register at file load; all
 * get/set/clear traffic for linktitles.* names routes here instead of
 * the generic channel_settings table.
 */

/**
 * SettingStorage over the linktitles_settings table.
 *
 * get() implements the table's own tiering — channel row → network row
 * → global row, per key — and returns null when every tier's column is
 * null (the registry's NOT_FOUND sentinel; the store then falls back to
 * the definition default). set()/clear() route through ConfigService so
 * value validation and the reset/inherit semantics stay in one place.
 */
class LinktitlesSettingStorage implements SettingStorage
{
    /** Writable keys this adapter surfaces (linktitles_setting columns). */
    private const KEYS = [
        'enabled', 'ai_vision_disabled', 'url_log_chan', 'ai_vision_model',
        'ai_vision_prompt', 'ai_vision_reasoning_effort', 'ai_vision_reasoning',
    ];

    /**
     * Constructed with the caller's EntityManager, or with null to
     * resolve bootstrap.php's global $entityManager lazily at call time
     * (the bot's file-load registration has no EM handle yet).
     */
    public function __construct(private ?EntityManager $entityManager = null)
    {
    }

    public function get(string $name, ?int $networkId = null, ?int $channelId = null, ?int $userId = null): mixed
    {
        // channel-scoped surface: only (networkId, channelId) pairs ever
        // arrive here, so the user tier is ignored
        $key = $this->key($name);
        [$network, $channel] = $this->resolve($networkId, $channelId);

        // cascade channel → network → global, mirroring how linktitles
        // itself scopes rows (SettingsResolver::linktitlesTiers): the
        // channel tier is keyed by the channel alone, a null criterion
        // compiles to IS NULL, and the global row is (NULL, NULL)
        $tiers = [];
        if ($channel !== null) {
            $tiers[] = ['channel' => $channel];
        }
        if ($network !== null) {
            $tiers[] = ['network' => $network, 'channel' => null];
        }
        $tiers[] = ['network' => null, 'channel' => null];

        foreach ($tiers as $criteria) {
            $row = $this->freshSetting($criteria);
            if ($row === null) {
                continue;
            }
            $value = $row->$key;
            if ($value !== null) {
                return $value;
            }
        }
        return null;
    }

    public function set(string $name, mixed $value, ?int $networkId = null, ?int $channelId = null, ?int $userId = null): void
    {
        [$network, $channel] = $this->resolve($networkId, $channelId);
        $this->config()->setLinktitlesSetting($network, $channel, $this->key($name), $value);
    }

    public function clear(string $name, ?int $networkId = null, ?int $channelId = null, ?int $userId = null): void
    {
        [$network, $channel] = $this->resolve($networkId, $channelId);
        $this->config()->resetLinktitlesSetting($network, $channel, $this->key($name));
    }

    /**
     * Resolve the tier ids to their entities. A null (or unknown) id
     * means that tier is skipped — matching the ?Network/?Channel
     * signatures ConfigService already accepts.
     *
     * @return array{0: Network|null, 1: Channel|null}
     */
    private function resolve(?int $networkId, ?int $channelId): array
    {
        $network = $networkId !== null ? $this->em()->find(Network::class, $networkId) : null;
        $channel = $channelId !== null ? $this->em()->find(Channel::class, $channelId) : null;
        return [$network, $channel];
    }

    /** Strip the linktitles. prefix and narrow to the writable columns. */
    private function key(string $name): string
    {
        $key = str_starts_with($name, 'linktitles.') ? substr($name, strlen('linktitles.')) : $name;
        if (!in_array($key, self::KEYS, true)) {
            throw new \InvalidArgumentException("unknown linktitles setting: $name");
        }
        return $key;
    }

    /**
     * findOneBy() hydrates from the identity map when the entity is
     * already managed, so a long-lived EntityManager would keep serving
     * first-read values (mutations arrive from other processes). Refresh
     * every tier read — the SettingsResolver::freshSetting pattern.
     *
     * @param array<string, mixed> $criteria
     */
    private function freshSetting(array $criteria): ?linktitles_setting
    {
        $row = $this->em()->getRepository(linktitles_setting::class)->findOneBy($criteria);
        if ($row !== null) {
            $this->em()->refresh($row);
        }
        return $row;
    }

    private function config(): ConfigService
    {
        // build_change_notifier() degrades to the NoopChangeNotifier when
        // config.yaml lacks listen/control_key, keeping this headless-safe
        // (no HTTP push out of an unconfigured process), while a configured
        // control server receives the linktitles_setting event so the
        // running bot reloads its cached gates on .set/.unset writes
        return new ConfigService($this->em(), build_change_notifier());
    }

    private function em(): EntityManager
    {
        if ($this->entityManager === null) {
            $global = $GLOBALS['entityManager'] ?? null;
            if (!$global instanceof EntityManager) {
                throw new \RuntimeException(
                    'LinktitlesSettingStorage has no EntityManager (boot via bootstrap.php or construct with one)'
                );
            }
            $this->entityManager = $global;
        }
        return $this->entityManager;
    }
}

/**
 * Define the linktitles.* settings on the central registry, each backed
 * by one shared storage adapter. Deliberately unguarded: a duplicate
 * define() throws, so double registration without an intervening
 * SettingsRegistry::reset() fails loudly instead of silently dropping
 * the storage binding. $em overrides the lazy global-EntityManager
 * lookup for headless callers (tests, CLI).
 */
function linktitles_register_settings(?EntityManager $em = null): void
{
    $storage = new LinktitlesSettingStorage($em);
    // Defaults with a LinktitlesDefaults counterpart reference the constant
    // (the bottom tier of the runtime resolver's cascade) so .set-reported
    // defaults match live behavior; the nullable-override fields keep ''
    // because their runtime cascade bottoms out at null, not a constant.
    SettingsRegistry::define(new Setting(
        'linktitles.enabled', type: 'bool', default: LinktitlesDefaults::ENABLED, scope: 'channel', flag: 'admin',
        description: 'enable or disable link titles in a channel',
    ), $storage);
    SettingsRegistry::define(new Setting(
        'linktitles.ai_vision_disabled', type: 'bool', default: LinktitlesDefaults::AI_VISION_DISABLED, scope: 'channel', flag: 'admin',
        description: 'disable AI image descriptions in a channel',
    ), $storage);
    SettingsRegistry::define(new Setting(
        'linktitles.url_log_chan', type: 'string', default: '', scope: 'channel', flag: 'admin',
        description: 'channel to mirror seen URLs into (empty disables)',
    ), $storage);
    SettingsRegistry::define(new Setting(
        'linktitles.ai_vision_model', type: 'string', default: LinktitlesDefaults::MODEL, scope: 'channel', flag: 'admin', network_only: true,
        description: 'AI vision model override (empty uses the default)',
    ), $storage);
    SettingsRegistry::define(new Setting(
        'linktitles.ai_vision_prompt', type: 'string', default: LinktitlesDefaults::PROMPT, scope: 'channel', flag: 'admin',
        description: 'AI vision prompt override (empty uses the default)',
    ), $storage);
    SettingsRegistry::define(new Setting(
        'linktitles.ai_vision_reasoning_effort', type: 'string', default: '', scope: 'channel', flag: 'admin', network_only: true,
        description: 'AI vision reasoning effort override (empty uses the default)',
    ), $storage);
    SettingsRegistry::define(new Setting(
        // raw JSON blob: a CLI/web-only surface, kept off IRC lists
        'linktitles.ai_vision_reasoning', type: 'string', default: '', scope: 'channel', flag: 'admin',
        description: 'raw AI vision reasoning config blob (CLI/web only)',
        irc: false,
    ), $storage);
}

linktitles_register_settings();
