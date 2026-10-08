<?php

namespace library\settings;

use Doctrine\ORM\EntityManager;
use lolbot\entities\ChannelSetting;
use lolbot\entities\UserSetting;

/**
 * Reads and writes scoped setting values behind the SettingsRegistry.
 *
 * Resolution order for a channel-scoped read: the exact
 * (network, channel, key) row, then the network tier expressed as the
 * (network, NULL channel, key) row, then the definition default.
 *
 * Adapter-backed definitions (a SettingStorage attached in the registry)
 * own their storage entirely: get/set/clear are routed to the adapter and
 * the setting tables are never touched. A null from the adapter means
 * NOT_FOUND and falls through to the definition default.
 */
class SettingsStore
{
    public function __construct(private readonly EntityManager $em) {}

    /**
     * @return array{value: bool|int|string, source: 'channel'|'network'|'default'|'adapter'}
     */
    public function getChannelSetting(int $networkId, ?int $channelId, string $name): array
    {
        $definition = $this->definition($name);
        $storage = SettingsRegistry::storage($name);
        if ($storage !== null) {
            $value = $storage->get($name, $networkId, $channelId);
            if ($value !== null) {
                return ['value' => $this->scalar($value), 'source' => 'adapter'];
            }
            return ['value' => $this->scalar($definition->default), 'source' => 'default'];
        }
        if ($channelId !== null) {
            $row = $this->findChannelRow($networkId, $channelId, $name);
            if ($row !== null) {
                return ['value' => $this->scalar($row->value), 'source' => 'channel'];
            }
        }
        $row = $this->findChannelRow($networkId, null, $name);
        if ($row !== null) {
            return ['value' => $this->scalar($row->value), 'source' => 'network'];
        }
        return ['value' => $this->scalar($definition->default), 'source' => 'default'];
    }

    /**
     * @return array{value: bool|int|string, source: 'user'|'default'|'adapter'}
     */
    public function getUserSetting(int $userId, string $name): array
    {
        $definition = $this->definition($name);
        $storage = SettingsRegistry::storage($name);
        if ($storage !== null) {
            $value = $storage->get($name, null, null, $userId);
            if ($value !== null) {
                return ['value' => $this->scalar($value), 'source' => 'adapter'];
            }
            return ['value' => $this->scalar($definition->default), 'source' => 'default'];
        }
        $row = $this->em->getRepository(UserSetting::class)->findOneBy([
            'user_id' => $userId,
            'settingKey' => $name,
        ]);
        if ($row !== null) {
            return ['value' => $this->scalar($row->value), 'source' => 'user'];
        }
        return ['value' => $this->scalar($definition->default), 'source' => 'default'];
    }

    public function setChannelSetting(int $networkId, int $channelId, string $name, mixed $value): void
    {
        $this->definition($name);
        $storage = SettingsRegistry::storage($name);
        if ($storage !== null) {
            $storage->set($name, $value, $networkId, $channelId);
            return;
        }
        $row = $this->findChannelRow($networkId, $channelId, $name);
        if ($row === null) {
            $row = new ChannelSetting();
            $row->network_id = $networkId;
            $row->channel_id = $channelId;
            $row->settingKey = $name;
            $this->em->persist($row);
        }
        $row->value = $this->scalar($value);
        $row->updated = new \DateTimeImmutable();
        $this->em->flush();
    }

    public function setNetworkSetting(int $networkId, string $name, mixed $value): void
    {
        $this->definition($name);
        $storage = SettingsRegistry::storage($name);
        if ($storage !== null) {
            $storage->set($name, $value, $networkId, null);
            return;
        }
        $row = $this->findChannelRow($networkId, null, $name);
        if ($row === null) {
            $row = new ChannelSetting();
            $row->network_id = $networkId;
            $row->channel_id = null;
            $row->settingKey = $name;
            $this->em->persist($row);
        }
        $row->value = $this->scalar($value);
        $row->updated = new \DateTimeImmutable();
        $this->em->flush();
    }

    public function clearChannelSetting(int $networkId, int $channelId, string $name): void
    {
        $this->definition($name);
        $storage = SettingsRegistry::storage($name);
        if ($storage !== null) {
            $storage->clear($name, $networkId, $channelId);
            return;
        }
        $row = $this->findChannelRow($networkId, $channelId, $name);
        if ($row !== null) {
            $this->em->remove($row);
            $this->em->flush();
        }
    }

    public function clearNetworkSetting(int $networkId, string $name): void
    {
        $this->definition($name);
        $storage = SettingsRegistry::storage($name);
        if ($storage !== null) {
            $storage->clear($name, $networkId, null);
            return;
        }
        $row = $this->findChannelRow($networkId, null, $name);
        if ($row !== null) {
            $this->em->remove($row);
            $this->em->flush();
        }
    }

    public function setUserSetting(int $userId, string $name, mixed $value): void
    {
        $this->definition($name);
        $storage = SettingsRegistry::storage($name);
        if ($storage !== null) {
            $storage->set($name, $value, null, null, $userId);
            return;
        }
        $row = $this->em->getRepository(UserSetting::class)->findOneBy([
            'user_id' => $userId,
            'settingKey' => $name,
        ]);
        if ($row === null) {
            $row = new UserSetting();
            $row->user_id = $userId;
            $row->settingKey = $name;
            $this->em->persist($row);
        }
        $row->value = $this->scalar($value);
        $row->updated = new \DateTimeImmutable();
        $this->em->flush();
    }

    public function clearUserSetting(int $userId, string $name): void
    {
        $this->definition($name);
        $storage = SettingsRegistry::storage($name);
        if ($storage !== null) {
            $storage->clear($name, null, null, $userId);
            return;
        }
        $row = $this->em->getRepository(UserSetting::class)->findOneBy([
            'user_id' => $userId,
            'settingKey' => $name,
        ]);
        if ($row !== null) {
            $this->em->remove($row);
            $this->em->flush();
        }
    }

    /**
     * Registry lookup comes FIRST in every method: unknown names never
     * reach the storage adapter or the entity layer.
     */
    private function definition(string $name): Setting
    {
        return SettingsRegistry::get($name)
            ?? throw new UnknownSettingException("unknown setting '{$name}'");
    }

    /**
     * Exact match on the full unique triple — a null channel_id criterion
     * is compiled to `channel_id IS NULL`, so network-tier rows are found
     * (and upserted) distinctly from channel-tier rows.
     */
    private function findChannelRow(int $networkId, ?int $channelId, string $name): ?ChannelSetting
    {
        return $this->em->getRepository(ChannelSetting::class)->findOneBy([
            'network_id' => $networkId,
            'channel_id' => $channelId,
            'settingKey' => $name,
        ]);
    }

    /**
     * Setting values are bool|int|string by contract (never null, never
     * arrays). Applied on every write (fail fast on out-of-domain values
     * instead of storing them) and on every read (a stored row, adapter
     * result, or definition default outside the domain is a bug worth
     * failing loudly on rather than silently widening the value type).
     *
     * @return bool|int|string
     */
    private function scalar(mixed $value): bool|int|string
    {
        if (is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }
        throw new \InvalidArgumentException('setting value must be bool|int|string, got ' . get_debug_type($value));
    }
}
