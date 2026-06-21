<?php
namespace Tests\Config;

use lolbot\config\ConfigService;
use lolbot\config\LinktitlesDefaults;
use lolbot\config\SettingsResolver;

require_once __DIR__ . '/../../vendor/autoload.php';

class LinktitlesCascadeTest extends ConfigTestCase
{
    private ConfigService $svc;
    private SettingsResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ConfigService($this->em);
        $this->resolver = new SettingsResolver($this->em);
    }

    public function test_channel_overrides_network_overrides_global(): void
    {
        $net = $this->svc->createNetwork('N');
        $bot = $this->svc->createBot($net, 'b');
        $chan = $this->svc->addChannel($bot, '#c');

        $this->svc->setLinktitlesSetting(null, null, 'ai_vision_model', 'global-model');
        $this->svc->setLinktitlesSetting($net, null, 'ai_vision_model', 'net-model');
        $this->svc->setLinktitlesSetting(null, $chan, 'ai_vision_model', 'chan-model');

        $r = $this->resolver->resolveLinktitles($net, $chan);
        $this->assertSame('chan-model', $r->aiVisionModel);
        $this->assertSame('channel', $r->sources['ai_vision_model']);
    }

    public function test_network_overrides_global(): void
    {
        $net = $this->svc->createNetwork('N');

        $this->svc->setLinktitlesSetting(null, null, 'ai_vision_model', 'global-model');
        $this->svc->setLinktitlesSetting($net, null, 'ai_vision_model', 'net-model');

        $r = $this->resolver->resolveLinktitles($net, null);
        $this->assertSame('net-model', $r->aiVisionModel);
        $this->assertSame('network', $r->sources['ai_vision_model']);
    }

    public function test_global_picked_when_no_network_override(): void
    {
        $net = $this->svc->createNetwork('N');

        $this->svc->setLinktitlesSetting(null, null, 'ai_vision_model', 'global-model');

        $r = $this->resolver->resolveLinktitles($net, null);
        $this->assertSame('global-model', $r->aiVisionModel);
        $this->assertSame('global', $r->sources['ai_vision_model']);
    }

    public function test_global_row_written_by_null_null_is_resolved_for_any_network(): void
    {
        $net = $this->svc->createNetwork('N');

        $this->svc->setLinktitlesSetting(null, null, 'ai_vision_model', 'X');

        $r = $this->resolver->resolveLinktitles($net, null);
        $this->assertSame('X', $r->aiVisionModel);
        $this->assertSame('global', $r->sources['ai_vision_model']);
    }

    public function test_defaults_applied_when_unset_everywhere(): void
    {
        $net = $this->svc->createNetwork('N');

        $r = $this->resolver->resolveLinktitles($net, null);
        $this->assertSame(LinktitlesDefaults::MODEL, $r->aiVisionModel);
        $this->assertSame(LinktitlesDefaults::PROMPT, $r->aiVisionPrompt);
        $this->assertSame(LinktitlesDefaults::ENABLED, $r->enabled);
        $this->assertSame(LinktitlesDefaults::AI_VISION_DISABLED, $r->aiVisionDisabled);
        $this->assertNull($r->urlLogChan);
        $this->assertNull($r->aiVisionReasoningEffort);
        $this->assertNull($r->aiVisionReasoning);
        $this->assertSame('default', $r->sources['ai_vision_model']);
        $this->assertSame('default', $r->sources['ai_vision_prompt']);
        $this->assertSame('default', $r->sources['enabled']);
        $this->assertSame('default', $r->sources['ai_vision_disabled']);
        $this->assertSame('default', $r->sources['url_log_chan']);
    }

    public function test_null_at_a_tier_inherits_from_next(): void
    {
        $net = $this->svc->createNetwork('N');
        // network tier sets model only; global tier sets prompt only
        $this->svc->setLinktitlesSetting($net, null, 'ai_vision_model', 'net-model');
        $this->svc->setLinktitlesSetting(null, null, 'ai_vision_prompt', 'global-prompt');

        $r = $this->resolver->resolveLinktitles($net, null);
        $this->assertSame('net-model', $r->aiVisionModel);
        $this->assertSame('network', $r->sources['ai_vision_model']);
        $this->assertSame('global-prompt', $r->aiVisionPrompt);
        $this->assertSame('global', $r->sources['ai_vision_prompt']);
    }

    public function test_enabled_nullable_bool_inherits_from_global(): void
    {
        $net = $this->svc->createNetwork('N');
        // global enables; network row exists but leaves enabled null (inherits)
        $this->svc->setLinktitlesSetting(null, null, 'enabled', true);
        $this->svc->setLinktitlesSetting($net, null, 'ai_vision_model', 'net-model');

        $r = $this->resolver->resolveLinktitles($net, null);
        $this->assertTrue($r->enabled);
        $this->assertSame('global', $r->sources['enabled']);
    }

    public function test_ai_vision_disabled_inherits_from_network(): void
    {
        $net = $this->svc->createNetwork('N');
        $bot = $this->svc->createBot($net, 'b');
        $chan = $this->svc->addChannel($bot, '#c');

        $this->svc->setLinktitlesSetting($net, null, 'ai_vision_disabled', true);

        // channel has no row at all → inherits network value
        $r = $this->resolver->resolveLinktitles($net, $chan);
        $this->assertTrue($r->aiVisionDisabled);
        $this->assertSame('network', $r->sources['ai_vision_disabled']);
    }

    public function test_reset_to_null_inherits_next_tier(): void
    {
        $net = $this->svc->createNetwork('N');
        // global sets model; network sets model then resets (clears to null) → inherits global
        $this->svc->setLinktitlesSetting(null, null, 'ai_vision_model', 'global-model');
        $this->svc->setLinktitlesSetting($net, null, 'ai_vision_model', 'net-model');
        $this->svc->resetLinktitlesSetting($net, null, 'ai_vision_model');

        $r = $this->resolver->resolveLinktitles($net, null);
        $this->assertSame('global-model', $r->aiVisionModel);
        $this->assertSame('global', $r->sources['ai_vision_model']);
    }

    public function test_prompt_provenance_from_channel(): void
    {
        $net = $this->svc->createNetwork('N');
        $bot = $this->svc->createBot($net, 'b');
        $chan = $this->svc->addChannel($bot, '#c');

        $this->svc->setLinktitlesSetting(null, $chan, 'ai_vision_prompt', 'chan-prompt');

        $r = $this->resolver->resolveLinktitles($net, $chan);
        $this->assertSame('chan-prompt', $r->aiVisionPrompt);
        $this->assertSame('channel', $r->sources['ai_vision_prompt']);
        // model still falls to default
        $this->assertSame('default', $r->sources['ai_vision_model']);
    }
}
