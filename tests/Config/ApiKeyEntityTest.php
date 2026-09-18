<?php
namespace Tests\Config;

use lolbot\entities\ApiKey;

require_once __DIR__ . '/../../vendor/autoload.php';

class ApiKeyEntityTest extends ConfigTestCase
{
    public function test_round_trip_persist_and_load(): void
    {
        $key = new ApiKey();
        $key->key = 's3cr3t';
        $key->label = 'image upload site';
        $key->scopes = ['aidesc'];
        $this->em->persist($key);
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->em->getRepository(ApiKey::class)->find($key->id);
        $this->assertNotNull($loaded);
        $this->assertSame('s3cr3t', $loaded->key);
        $this->assertSame('image upload site', $loaded->label);
        $this->assertSame(['aidesc'], $loaded->scopes);
        $this->assertInstanceOf(\DateTimeImmutable::class, $loaded->created);
    }

    public function test_hasscope(): void
    {
        $key = new ApiKey();
        $key->key = 'k';
        $key->scopes = ['aidesc', 'notifier'];
        $this->assertTrue($key->hasScope('aidesc'));
        $this->assertTrue($key->hasScope('notifier'));
        $this->assertFalse($key->hasScope('nope'));
    }

    public function test_duplicate_key_rejected_by_unique_index(): void
    {
        $a = new ApiKey();
        $a->key = 'same';
        $a->scopes = ['aidesc'];
        $this->em->persist($a);
        $this->em->flush();

        $b = new ApiKey();
        $b->key = 'same';
        $b->scopes = ['aidesc'];
        $this->em->persist($b);
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function test_findbykey_sees_deletes_from_another_entity_manager(): void
    {
        $key = new ApiKey();
        $key->key = 'k1';
        $key->scopes = ['aidesc'];
        $this->em->persist($key);
        $this->em->flush();

        /** @var \lolbot\entities\ApiKeyRepository $repo */
        $repo = $this->em->getRepository(ApiKey::class);
        $this->assertNotNull($repo->findByKey('k1'));

        $em2 = new \Doctrine\ORM\EntityManager($this->em->getConnection(), $this->em->getConfiguration());
        $fresh = $em2->find(ApiKey::class, $key->id);
        $this->assertNotNull($fresh);
        $em2->remove($fresh);
        $em2->flush();

        // The first EM still holds the entity in its identity map; the
        // HINT_REFRESH lookup must reflect the DB, not the stale map.
        $this->assertNull($repo->findByKey('k1'));
    }

    public function test_findbykey_sees_updates_from_another_entity_manager(): void
    {
        $key = new ApiKey();
        $key->key = 'k2';
        $key->scopes = ['aidesc'];
        $this->em->persist($key);
        $this->em->flush();

        $em2 = new \Doctrine\ORM\EntityManager($this->em->getConnection(), $this->em->getConfiguration());
        $fresh = $em2->find(ApiKey::class, $key->id);
        $this->assertNotNull($fresh);
        $fresh->scopes = ['notifier'];
        $em2->flush();

        /** @var \lolbot\entities\ApiKeyRepository $repo */
        $repo = $this->em->getRepository(ApiKey::class);
        $loaded = $repo->findByKey('k2');
        $this->assertNotNull($loaded);
        $this->assertSame(['notifier'], $loaded->scopes);
    }
}
