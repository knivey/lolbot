<?php
namespace Tests\Config;

use lolbot\config\ConfigService;
use lolbot\config\SettingsResolver;
use scripts\linktitles\entities\linktitles_setting;

require_once __DIR__ . '/../../vendor/autoload.php';

class SettingsResolverGlobalTest extends ConfigTestCase
{
    public function test_global_row_is_honored(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');

        // Global scope row (no network).
        $global = new linktitles_setting();
        $global->ai_vision_model = 'gpt-global';
        $global->ai_vision_prompt = 'global prompt';
        $this->em->persist($global);

        // Network scope row must be ignored by the global resolve.
        $networkRow = new linktitles_setting();
        $networkRow->network = $net;
        $networkRow->ai_vision_model = 'gpt-network';
        $this->em->persist($networkRow);
        $this->em->flush();

        $resolved = (new SettingsResolver($this->em))->resolveGlobalLinktitles();
        $this->assertSame('gpt-global', $resolved->aiVisionModel);
        $this->assertSame('global prompt', $resolved->aiVisionPrompt);
    }

    public function test_defaults_when_no_rows(): void
    {
        $resolved = (new SettingsResolver($this->em))->resolveGlobalLinktitles();
        $this->assertSame(\lolbot\config\LinktitlesDefaults::MODEL, $resolved->aiVisionModel);
        $this->assertSame(\lolbot\config\LinktitlesDefaults::PROMPT, $resolved->aiVisionPrompt);
    }
}
