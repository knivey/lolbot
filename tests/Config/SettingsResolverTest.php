<?php
namespace Tests\Config;

use lolbot\config\ConfigService;
use lolbot\config\SettingsResolver;
use lolbot\entities\Network;
use scripts\linktitles\entities\linktitles_setting;

require_once __DIR__ . '/../../vendor/autoload.php';

class SettingsResolverTest extends ConfigTestCase
{
    private ConfigService $svc;
    private SettingsResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ConfigService($this->em);
        $this->resolver = new SettingsResolver($this->em);
    }

    public function test_returns_null_setting_when_none(): void
    {
        $net = $this->svc->createNetwork('N');
        $this->assertNull($this->resolver->getLinktitlesSetting($net, null));
        $this->assertFalse($this->resolver->linktitlesEnabled($net, null));
    }

    public function test_network_setting_resolves(): void
    {
        $net = $this->svc->createNetwork('N');
        $this->svc->setLinktitlesSetting($net, null, 'enabled', true);
        $this->assertTrue($this->resolver->linktitlesEnabled($net, null));
    }

    public function test_channel_overrides_network(): void
    {
        $net = $this->svc->createNetwork('N');
        $bot = $this->svc->createBot($net, 'b');
        $chan = $this->svc->addChannel($bot, '#c');

        // network: enabled=true; channel row: enabled=false overrides
        $this->svc->setLinktitlesSetting($net, null, 'enabled', true);
        $this->svc->setLinktitlesSetting($net, $chan, 'enabled', false);

        $this->assertFalse($this->resolver->linktitlesEnabled($net, $chan));
        $this->assertTrue($this->resolver->linktitlesEnabled($net, null)); // no channel → network
    }

    public function test_url_log_chan_resolves(): void
    {
        $net = $this->svc->createNetwork('N');
        $this->svc->setLinktitlesSetting($net, null, 'url_log_chan', '#urls');
        $this->assertSame('#urls', $this->resolver->urlLogChan($net, null));
    }
}
