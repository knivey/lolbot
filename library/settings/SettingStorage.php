<?php

namespace library\settings;

/*
 * Storage backend for adapter-backed settings (scripts with their own
 * tables — e.g. linktitles). get() returns null to mean "no value at
 * this scope" — null is safe as the NOT_FOUND sentinel because setting
 * VALUES are never null (bool/int/string only).
 */
interface SettingStorage
{
    public function get(string $name, ?int $networkId = null, ?int $channelId = null, ?int $userId = null): mixed;
    public function set(string $name, mixed $value, ?int $networkId = null, ?int $channelId = null, ?int $userId = null): void;
    public function clear(string $name, ?int $networkId = null, ?int $channelId = null, ?int $userId = null): void;
}
