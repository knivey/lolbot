<?php
namespace Tests\Config;

use lolbot\config\ConfigService;
use lolbot\config\InvalidSettingException;
use lolbot\entities\AiServiceConfig;
use lolbot\entities\Network;
use lolbot\entities\PasteServiceConfig;

require_once __DIR__ . '/../../vendor/autoload.php';

class ConfigServiceSettingsTest extends ConfigTestCase
{
    private ConfigService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ConfigService($this->em);
    }

    public function test_set_service_config_value_creates_singleton_row(): void
    {
        $this->svc->setServiceConfigValue('ai', 'apiKey', 'sk-1');
        $this->svc->setServiceConfigValue('ai', 'maxDim', 2048);

        $ai = $this->em->getRepository(AiServiceConfig::class)->findAll();
        $this->assertCount(1, $ai); // single row
        $this->assertSame('sk-1', $ai[0]->apiKey);
        $this->assertSame(2048, $ai[0]->maxDim);
    }

    public function test_set_service_config_value_updates_existing_row(): void
    {
        $this->svc->setServiceConfigValue('paste', 'host', 'http://a');
        $this->svc->setServiceConfigValue('paste', 'key', 'k');
        $this->svc->setServiceConfigValue('paste', 'host', 'http://b'); // update

        $p = $this->em->getRepository(PasteServiceConfig::class)->findAll();
        $this->assertCount(1, $p);
        $this->assertSame('http://b', $p[0]->host);
        $this->assertSame('k', $p[0]->key);
    }

    public function test_set_service_config_unknown_type_throws(): void
    {
        $this->expectException(InvalidSettingException::class);
        $this->svc->setServiceConfigValue('nope', 'x', 'y');
    }

    public function test_set_service_config_unknown_key_throws(): void
    {
        $this->expectException(InvalidSettingException::class);
        $this->svc->setServiceConfigValue('ai', 'notAField', 'y');
    }

    public function test_set_linktitles_setting_creates_and_updates(): void
    {
        $net = $this->svc->createNetwork('N');
        $this->svc->setLinktitlesSetting($net, null, 'enabled', true);
        $this->svc->setLinktitlesSetting($net, null, 'url_log_chan', '#urls');

        $rows = $this->em->getRepository(\scripts\linktitles\entities\linktitles_setting::class)->findAll();
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]->enabled);
        $this->assertSame('#urls', $rows[0]->url_log_chan);
    }

    public function test_reset_linktitles_setting_clears_field(): void
    {
        $net = $this->svc->createNetwork('N');
        $this->svc->setLinktitlesSetting($net, null, 'ai_vision_model', 'gpt-4o-mini');
        $this->svc->resetLinktitlesSetting($net, null, 'ai_vision_model');

        $row = $this->em->getRepository(\scripts\linktitles\entities\linktitles_setting::class)->findAll()[0];
        $this->assertNull($row->ai_vision_model);
    }
}
