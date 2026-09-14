<?php
namespace Tests\Config;

use lolbot\config\ServiceLocator;
use lolbot\entities\AiServiceConfig;
use lolbot\entities\PasteServiceConfig;

require_once __DIR__ . '/../../vendor/autoload.php';

class ServiceLocatorTest extends ConfigTestCase
{
    public function test_returns_null_when_no_config(): void
    {
        $loc = new ServiceLocator($this->em);
        $this->assertNull($loc->getServiceConfig('ai'));
        $this->assertNull($loc->getServiceConfig('paste'));
    }

    public function test_returns_the_single_ai_row(): void
    {
        $ai = new AiServiceConfig();
        $ai->apiKey = 'sk-x';
        $this->em->persist($ai);
        $this->em->flush();

        $loc = new ServiceLocator($this->em);
        $got = $loc->getServiceConfig('ai');
        $this->assertInstanceOf(AiServiceConfig::class, $got);
        $this->assertSame('sk-x', $got->apiKey);
    }

    public function test_unknown_type_returns_null(): void
    {
        $loc = new ServiceLocator($this->em);
        $this->assertNull($loc->getServiceConfig('nope'));
    }

    public function test_service_types_lists_registered(): void
    {
        $loc = new ServiceLocator($this->em);
        $types = $loc->serviceTypes();
        $this->assertContains('ai', $types);
        $this->assertContains('paste', $types);
    }

    public function test_ai_config_update_after_first_read_is_seen(): void
    {
        $ai = new AiServiceConfig();
        $ai->apiKey = 'sk-old';
        $this->em->persist($ai);
        $this->em->flush();

        $loc = new ServiceLocator($this->em);
        // Warm the identity map (what a running bot does on its first read).
        $old = $loc->getServiceConfig('ai');
        $this->assertInstanceOf(AiServiceConfig::class, $old);
        $this->assertSame('sk-old', $old->apiKey);

        // Out-of-band key rotation, as done by `service:set` in another process.
        $this->em->getConnection()->executeStatement(
            'UPDATE ai_service_config SET api_key = ? WHERE id = ?',
            ['sk-new', $ai->id],
        );

        $new = $loc->getServiceConfig('ai');
        $this->assertInstanceOf(AiServiceConfig::class, $new);
        $this->assertSame('sk-new', $new->apiKey);
    }

    public function test_paste_config_update_after_first_read_is_seen(): void
    {
        $paste = new PasteServiceConfig();
        $paste->host = 'https://old.example.net';
        $this->em->persist($paste);
        $this->em->flush();

        $loc = new ServiceLocator($this->em);
        // Warm the identity map.
        $old = $loc->getServiceConfig('paste');
        $this->assertInstanceOf(PasteServiceConfig::class, $old);
        $this->assertSame('https://old.example.net', $old->host);

        $this->em->getConnection()->executeStatement(
            'UPDATE paste_service_config SET host = ? WHERE id = ?',
            ['https://new.example.net', $paste->id],
        );

        $new = $loc->getServiceConfig('paste');
        $this->assertInstanceOf(PasteServiceConfig::class, $new);
        $this->assertSame('https://new.example.net', $new->host);
    }

    public function test_config_row_delete_after_first_read_is_seen(): void
    {
        $ai = new AiServiceConfig();
        $ai->apiKey = 'sk-x';
        $this->em->persist($ai);
        $this->em->flush();

        $loc = new ServiceLocator($this->em);
        // Warm the identity map.
        $this->assertNotNull($loc->getServiceConfig('ai'));

        $this->em->getConnection()->executeStatement('DELETE FROM ai_service_config WHERE id = ?', [$ai->id]);

        $this->assertNull($loc->getServiceConfig('ai'));
    }
}
