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

    /**
     * The bot reads settings through one long-lived EntityManager while
     * admin-cli/web writes from a separate process. Raw SQL simulates that
     * out-of-band write; a warm first read puts the row in the identity map.
     *
     * @param list<mixed> $params
     */
    private function outOfBandUpdate(string $sql, array $params = []): void
    {
        $this->em->getConnection()->executeStatement($sql, $params);
    }

    public function test_ai_vision_disabled_update_after_first_read_is_seen(): void
    {
        $net = $this->svc->createNetwork('N');
        $this->svc->setLinktitlesSetting($net, null, 'ai_vision_disabled', false);

        // Warm the identity map (what the running bot does on its first link).
        $this->assertFalse($this->resolver->resolveLinktitles($net, null)->aiVisionDisabled);

        // Out-of-band disable, as done by `linktitles:set` in another process.
        $this->outOfBandUpdate(
            'UPDATE linktitles_settings SET ai_vision_disabled = 1 WHERE network_id = ?',
            [$net->id],
        );

        $this->assertTrue($this->resolver->resolveLinktitles($net, null)->aiVisionDisabled);
    }

    public function test_global_tier_update_after_first_read_is_seen(): void
    {
        $net = $this->svc->createNetwork('N');
        $this->svc->setLinktitlesSetting(null, null, 'ai_vision_model', 'old-model');

        // Warm the identity map.
        $this->assertSame('old-model', $this->resolver->resolveLinktitles($net, null)->aiVisionModel);

        $this->outOfBandUpdate(
            'UPDATE linktitles_settings SET ai_vision_model = ? WHERE network_id IS NULL AND channel_id IS NULL',
            ['new-model'],
        );

        $this->assertSame('new-model', $this->resolver->resolveLinktitles($net, null)->aiVisionModel);
    }

    public function test_field_reset_to_null_after_first_read_falls_through_to_next_tier(): void
    {
        $net = $this->svc->createNetwork('N');
        $this->svc->setLinktitlesSetting(null, null, 'ai_vision_disabled', true); // global tier
        $this->svc->setLinktitlesSetting($net, null, 'ai_vision_disabled', false); // network tier overrides

        // Warm the identity map.
        $this->assertFalse($this->resolver->resolveLinktitles($net, null)->aiVisionDisabled);

        // Out-of-band per-field reset (`resetLinktitlesSetting` in another process)
        // nulls the network-tier field so resolution falls through to the global tier.
        $this->outOfBandUpdate(
            'UPDATE linktitles_settings SET ai_vision_disabled = NULL WHERE network_id = ?',
            [$net->id],
        );

        $this->assertTrue($this->resolver->resolveLinktitles($net, null)->aiVisionDisabled);
    }

    public function test_row_delete_after_first_read_falls_back_to_default(): void
    {
        $net = $this->svc->createNetwork('N');
        $this->svc->setLinktitlesSetting($net, null, 'enabled', true);

        // Warm the identity map.
        $this->assertTrue($this->resolver->linktitlesEnabled($net, null));

        // Out-of-band scope reset (`linktitles:set --reset` in another process).
        $this->outOfBandUpdate('DELETE FROM linktitles_settings WHERE network_id = ?', [$net->id]);

        $this->assertFalse($this->resolver->linktitlesEnabled($net, null));
    }
}
