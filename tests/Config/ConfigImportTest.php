<?php
namespace Tests\Config;

use lolbot\cli_cmds\config_import;
use lolbot\entities\AiServiceConfig;
use scripts\linktitles\entities\linktitles_setting;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__ . '/../../vendor/autoload.php';

class ConfigImportTest extends ConfigTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['entityManager'] = $this->em;
    }

    private function runImport(array $config, bool $force = false): CommandTester
    {
        $GLOBALS['config'] = $config;
        $command = new config_import();
        $tester = new CommandTester($command);
        $tester->execute($force ? ['--force' => true] : []);
        return $tester;
    }

    public function test_vision_model_and_prompt_go_to_global_tier(): void
    {
        $net = $this->em->getRepository(\lolbot\entities\Network::class);
        // No networks exist; the import must still write the global row once.
        $this->runImport([
            'ai_vision_model' => 'gpt-5-mini',
            'ai_vision_prompt' => 'describe briefly',
        ]);

        $repo = $this->em->getRepository(linktitles_setting::class);
        $globalRow = $repo->findOneBy(['network' => null, 'channel' => null]);
        $this->assertNotNull($globalRow, 'global linktitles row should exist');
        $this->assertSame('gpt-5-mini', $globalRow->ai_vision_model);
        $this->assertSame('describe briefly', $globalRow->ai_vision_prompt);

        // Exactly one linktitles_setting row (the global one) — no per-network rows.
        $this->assertCount(1, $repo->findAll());
    }

    public function test_vision_reasoning_imported_to_global_tier(): void
    {
        $this->runImport([
            'ai_vision_reasoning_effort' => 'high',
            'ai_vision_reasoning' => ['effort' => 'high', 'exclude' => false],
        ]);

        $globalRow = $this->em->getRepository(linktitles_setting::class)
            ->findOneBy(['network' => null, 'channel' => null]);
        $this->assertNotNull($globalRow);
        $this->assertSame('high', $globalRow->ai_vision_reasoning_effort);
        $this->assertSame(['effort' => 'high', 'exclude' => false], $globalRow->ai_vision_reasoning);
    }

    public function test_ai_service_reasoning_keys_not_imported_into_service_config(): void
    {
        $this->runImport([
            'ai_vision_key' => 'sk-test',
            'ai_vision_reasoning_effort' => 'high',
            'ai_vision_reasoning' => ['effort' => 'high'],
        ]);

        $ai = $this->em->getRepository(AiServiceConfig::class)->findOneBy([]);
        $this->assertNotNull($ai);
        $this->assertSame('sk-test', $ai->apiKey);
        // reasoning / reasoningEffort no longer exist on the AI service entity.
        $this->assertFalse(property_exists(AiServiceConfig::class, 'reasoning'));
        $this->assertFalse(property_exists(AiServiceConfig::class, 'reasoningEffort'));
    }

    public function test_ai_service_connection_keys_still_imported(): void
    {
        $this->runImport([
            'ai_vision_key' => 'sk-conn',
            'ai_vision_base_url' => 'https://example.test/v1',
            'ai_vision_max_dim' => 2048,
            'ai_vision_jpg_quality' => 90,
            'ai_vision_timeout' => 30,
        ]);

        $ai = $this->em->getRepository(AiServiceConfig::class)->findOneBy([]);
        $this->assertNotNull($ai);
        $this->assertSame('sk-conn', $ai->apiKey);
        $this->assertSame('https://example.test/v1', $ai->baseUrl);
        $this->assertSame(2048, $ai->maxDim);
        $this->assertSame(90, $ai->jpgQuality);
        $this->assertSame(30, $ai->timeout);
    }
}
