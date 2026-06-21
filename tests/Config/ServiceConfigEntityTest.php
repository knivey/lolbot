<?php
namespace Tests\Config;

use lolbot\entities\AiServiceConfig;
use lolbot\entities\PasteServiceConfig;

require_once __DIR__ . '/../../vendor/autoload.php';

class ServiceConfigEntityTest extends ConfigTestCase
{
    public function test_ai_service_config_persists_with_defaults(): void
    {
        $ai = new AiServiceConfig();
        $ai->apiKey = 'sk-test';
        $this->em->persist($ai);
        $this->em->flush();
        $this->em->clear();

        $all = $this->em->getRepository(AiServiceConfig::class)->findAll();
        $this->assertCount(1, $all);
        $loaded = $all[0];
        $this->assertSame('sk-test', $loaded->apiKey);
        $this->assertSame(1024, $loaded->maxDim);
        $this->assertSame(85, $loaded->jpgQuality);
        $this->assertSame(10, $loaded->timeout);
    }

    public function test_paste_service_config_persists(): void
    {
        $p = new PasteServiceConfig();
        $p->host = 'http://localhost:8080';
        $p->key = 'sekret';
        $this->em->persist($p);
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->em->getRepository(PasteServiceConfig::class)->findAll()[0];
        $this->assertSame('http://localhost:8080', $loaded->host);
        $this->assertSame('sekret', $loaded->key);
    }
}
