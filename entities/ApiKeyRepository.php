<?php
namespace lolbot\entities;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;

/**
 * @extends EntityRepository<ApiKey>
 */
class ApiKeyRepository extends EntityRepository
{
    public function findByKey(string $key): ?ApiKey
    {
        // Repository finds hydrate from the identity map when the entity is
        // already managed, so a long-lived bot EntityManager would keep serving
        // its first read. Mutations arrive from other processes (admin-cli, web
        // panel) and deletion is a primary flow for keys, so query with
        // HINT_REFRESH: updated rows re-hydrate and a deleted row simply
        // returns null (EntityManager::refresh() would throw instead).
        /** @var list<ApiKey> $rows */
        $rows = $this->getEntityManager()->createQuery(
            'SELECT a FROM lolbot\entities\ApiKey a WHERE a.key = :key'
        )
            ->setParameter('key', $key)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
        return $rows[0] ?? null;
    }
}
