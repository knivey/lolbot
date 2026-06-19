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
}
