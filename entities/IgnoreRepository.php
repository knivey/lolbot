<?php
namespace lolbot\entities;

use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<Ignore>
 */
class IgnoreRepository extends EntityRepository
{
    /**
     * @param string $host
     * @param Network|null $network
     * @return array<Ignore>
     */
    public function findMatching(string $host, Network|null $network = null): array {
        $ignores = $this->findAll();
        if($network !== null) {
            $ignores = array_filter($ignores, function (Ignore $i) use ($network, $host) {
                return ($i->assignedToNetwork($network) && $i->matches($host));
            });
        }
        return array_filter($ignores, function (Ignore $i) use ($host) {
            return $i->matches($host);
        });
    }
}