<?php
namespace lolbot\entities;

use Doctrine\ORM\EntityRepository;
use lolbot\entities\Ignore;

/**
 * @extends EntityRepository<Ignore>
 */
class IgnoreRepository extends EntityRepository
{
    /**
     * @param string $host
     * @return array<Ignore>
     */
    public function findByHost(string $host): array {
        $ignores = $this->findAll();
        return array_filter($ignores, function (Ignore $i) use ($host) {
            return $i->matches($host);
        });
    }
}