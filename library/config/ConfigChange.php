<?php
namespace lolbot\config;

/**
 * Describes a single config mutation, passed to ChangeNotifier after a flush.
 */
final class ConfigChange
{
    public function __construct(
        public readonly string $entityType,
        public readonly ?int $id,
        public readonly string $action, // create | update | delete
    ) {}
}
