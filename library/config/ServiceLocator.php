<?php
namespace lolbot\config;

use Doctrine\ORM\EntityManager;
use lolbot\entities\AiServiceConfig;
use lolbot\entities\PasteServiceConfig;

/**
 * Read-only accessor for global service configs. Each registered type maps to a
 * single-row table; getServiceConfig() returns that row (the singleton) or null.
 */
class ServiceLocator
{
    /** @var array<string, class-string> */
    private const REGISTRY = [
        'ai'    => AiServiceConfig::class,
        'paste' => PasteServiceConfig::class,
    ];

    public function __construct(private EntityManager $em) {}

    /**
     * @return object|null  The singleton service-config entity, or null if none / unknown type.
     */
    public function getServiceConfig(string $type): ?object
    {
        $class = self::REGISTRY[$type] ?? null;
        if ($class === null) {
            return null;
        }
        $rows = $this->em->getRepository($class)->findAll();
        // findAll() hydrates from the identity map when the entity is already
        // managed, so a long-lived bot EntityManager would keep serving the
        // values from its first read. Mutations arrive from other processes
        // (admin-cli, web panel), so refresh the row from the DB on every read.
        if (isset($rows[0])) {
            $this->em->refresh($rows[0]);
        }
        return $rows[0] ?? null;
    }

    /** @return list<string> */
    public function serviceTypes(): array
    {
        return array_keys(self::REGISTRY);
    }

    /**
     * Internal helper for ConfigService upserts.
     * @return class-string|null
     */
    public function entityClassFor(string $type): ?string
    {
        return self::REGISTRY[$type] ?? null;
    }
}
