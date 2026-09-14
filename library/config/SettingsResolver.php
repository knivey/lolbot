<?php
namespace lolbot\config;

use Doctrine\ORM\EntityManager;
use lolbot\entities\Channel;
use lolbot\entities\Network;
use scripts\linktitles\entities\linktitles_setting;

/**
 * Resolves effective per-scope settings for a running bot.
 *
 * linktitles_setting resolution cascades per field across three tiers —
 * channel → network → global — then falls back to LinktitlesDefaults. The
 * legacy getLinktitlesSetting() still returns the single most-specific
 * channel/network row (used elsewhere); resolveLinktitles() applies the full
 * cascade including the global-defaults tier.
 */
class SettingsResolver
{
    public function __construct(private EntityManager $em) {}

    public function getLinktitlesSetting(Network $network, ?Channel $channel): ?linktitles_setting
    {
        if ($channel !== null) {
            $s = $this->freshSetting(['channel' => $channel]);
            if ($s !== null) {
                return $s;
            }
        }
        return $this->freshSetting(['network' => $network, 'channel' => null]);
    }

    private function globalLinktitlesSetting(): ?linktitles_setting
    {
        return $this->freshSetting(['network' => null, 'channel' => null]);
    }

    /**
     * findOneBy() hydrates from the identity map when the entity is already
     * managed, so a long-lived bot EntityManager would keep serving the values
     * from its first read even though the SQL runs every time. Mutations arrive
     * from other processes (admin-cli, web panel), so every tier read must
     * refresh managed rows from the DB.
     *
     * @param array<string, mixed> $criteria
     */
    private function freshSetting(array $criteria): ?linktitles_setting
    {
        $row = $this->em->getRepository(linktitles_setting::class)->findOneBy($criteria);
        if ($row !== null) {
            $this->em->refresh($row);
        }
        return $row;
    }

    /**
     * Full cascade resolution: for each field pick the most-specific non-null
     * value across channel → network → global tiers, falling back to the
     * matching LinktitlesDefaults constant when every tier is null.
     *
     * @return array{0: linktitles_setting|null, 1: linktitles_setting|null, 2: linktitles_setting|null}
     */
    private function linktitlesTiers(Network $network, ?Channel $channel): array
    {
        $channelRow = $channel !== null ? $this->freshSetting(['channel' => $channel]) : null;
        $networkRow = $this->freshSetting(['network' => $network, 'channel' => null]);
        $globalRow = $this->globalLinktitlesSetting();
        return [$channelRow, $networkRow, $globalRow];
    }

    public function resolveLinktitles(Network $network, ?Channel $channel): LinktitlesResolved
    {
        [$channelRow, $networkRow, $globalRow] = $this->linktitlesTiers($network, $channel);
        $sources = [];

        [$enabled, $sources['enabled']] = $this->pick(
            $channelRow, $networkRow, $globalRow,
            static fn(linktitles_setting $s) => $s->enabled,
            LinktitlesDefaults::ENABLED,
        );
        [$aiVisionDisabled, $sources['ai_vision_disabled']] = $this->pick(
            $channelRow, $networkRow, $globalRow,
            static fn(linktitles_setting $s) => $s->ai_vision_disabled,
            LinktitlesDefaults::AI_VISION_DISABLED,
        );
        [$aiVisionModel, $sources['ai_vision_model']] = $this->pick(
            $channelRow, $networkRow, $globalRow,
            static fn(linktitles_setting $s) => $s->ai_vision_model,
            LinktitlesDefaults::MODEL,
        );
        [$aiVisionPrompt, $sources['ai_vision_prompt']] = $this->pick(
            $channelRow, $networkRow, $globalRow,
            static fn(linktitles_setting $s) => $s->ai_vision_prompt,
            LinktitlesDefaults::PROMPT,
        );
        [$urlLogChan, $sources['url_log_chan']] = $this->pickNullable(
            $channelRow, $networkRow, $globalRow,
            static fn(linktitles_setting $s) => $s->url_log_chan,
        );
        [$aiVisionReasoningEffort, $sources['ai_vision_reasoning_effort']] = $this->pickNullable(
            $channelRow, $networkRow, $globalRow,
            static fn(linktitles_setting $s) => $s->ai_vision_reasoning_effort,
        );
        [$aiVisionReasoning, $sources['ai_vision_reasoning']] = $this->pickNullable(
            $channelRow, $networkRow, $globalRow,
            static fn(linktitles_setting $s) => $s->ai_vision_reasoning,
        );

        return new LinktitlesResolved(
            enabled: $enabled,
            urlLogChan: $urlLogChan,
            aiVisionModel: $aiVisionModel,
            aiVisionPrompt: $aiVisionPrompt,
            aiVisionReasoningEffort: $aiVisionReasoningEffort,
            aiVisionReasoning: $aiVisionReasoning,
            aiVisionDisabled: $aiVisionDisabled,
            sources: $sources,
        );
    }

    public function linktitlesEnabled(Network $network, ?Channel $channel): bool
    {
        return $this->resolveLinktitles($network, $channel)->enabled;
    }

    public function urlLogChan(Network $network, ?Channel $channel): ?string
    {
        return $this->resolveLinktitles($network, $channel)->urlLogChan;
    }

    /**
     * Cascade a field whose default is non-null (bool/string). Returns the
     * most-specific non-null tier value, or $default when every tier is null.
     *
     * @template T of bool|string
     * @param callable(linktitles_setting): (T|null) $extract
     * @param T $default
     * @return array{0: T, 1: 'channel'|'network'|'global'|'default'}
     */
    private function pick(
        ?linktitles_setting $channelRow,
        ?linktitles_setting $networkRow,
        ?linktitles_setting $globalRow,
        callable $extract,
        bool|string $default,
    ): array {
        if ($channelRow !== null && ($v = $extract($channelRow)) !== null) {
            return [$v, 'channel'];
        }
        if ($networkRow !== null && ($v = $extract($networkRow)) !== null) {
            return [$v, 'network'];
        }
        if ($globalRow !== null && ($v = $extract($globalRow)) !== null) {
            return [$v, 'global'];
        }
        return [$default, 'default'];
    }

    /**
     * Cascade a field whose default is null. Returns the most-specific non-null
     * tier value, or null when every tier is null.
     *
     * @template T of bool|string|array
     * @param callable(linktitles_setting): (T|null) $extract
     * @return array{0: T|null, 1: 'channel'|'network'|'global'|'default'}
     */
    private function pickNullable(
        ?linktitles_setting $channelRow,
        ?linktitles_setting $networkRow,
        ?linktitles_setting $globalRow,
        callable $extract,
    ): array {
        if ($channelRow !== null && ($v = $extract($channelRow)) !== null) {
            return [$v, 'channel'];
        }
        if ($networkRow !== null && ($v = $extract($networkRow)) !== null) {
            return [$v, 'network'];
        }
        if ($globalRow !== null && ($v = $extract($globalRow)) !== null) {
            return [$v, 'global'];
        }
        return [null, 'default'];
    }
}
