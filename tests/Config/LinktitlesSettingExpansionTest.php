<?php
namespace Tests\Config;

use lolbot\entities\Network;
use scripts\linktitles\entities\linktitles_setting;

require_once __DIR__ . '/../../vendor/autoload.php';

class LinktitlesSettingExpansionTest extends ConfigTestCase
{
    public function test_new_fields_round_trip_with_defaults(): void
    {
        $net = new Network();
        $net->name = 'N';
        $this->em->persist($net);

        $s = new linktitles_setting();
        $s->network = $net;
        $s->enabled = true;
        $s->url_log_chan = '#urls';
        $s->ai_vision_model = 'gpt-4o-mini';
        $s->ai_vision_prompt = 'describe';
        $s->ai_vision_reasoning_effort = 'low';
        $s->ai_vision_reasoning = ['effort' => 'low'];
        $this->em->persist($s);
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->em->getRepository(linktitles_setting::class)->findAll()[0];
        $this->assertTrue($loaded->enabled);
        $this->assertSame('#urls', $loaded->url_log_chan);
        $this->assertSame('gpt-4o-mini', $loaded->ai_vision_model);
        $this->assertSame('describe', $loaded->ai_vision_prompt);
        $this->assertSame('low', $loaded->ai_vision_reasoning_effort);
        $this->assertSame(['effort' => 'low'], $loaded->ai_vision_reasoning);
    }

    public function test_defaults_when_unset(): void
    {
        $net = new Network();
        $net->name = 'N';
        $this->em->persist($net);

        $s = new linktitles_setting();
        $s->network = $net;
        $this->em->persist($s);
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->em->getRepository(linktitles_setting::class)->findAll()[0];
        $this->assertNull($loaded->enabled);
        $this->assertNull($loaded->ai_vision_disabled);
        $this->assertNull($loaded->url_log_chan);
        $this->assertNull($loaded->ai_vision_model);
        $this->assertNull($loaded->ai_vision_prompt);
        $this->assertNull($loaded->ai_vision_reasoning_effort);
        $this->assertNull($loaded->ai_vision_reasoning);
    }
}
