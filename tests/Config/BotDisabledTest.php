<?php
namespace Tests\Config;

use lolbot\config\ConfigService;
use lolbot\entities\Bot;

require_once __DIR__ . '/../../vendor/autoload.php';

class BotDisabledTest extends ConfigTestCase
{
    public function test_disabled_defaults_to_false(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');

        $this->assertFalse($net->disabled);
        $this->assertFalse($bot->disabled);
        $this->assertFalse($bot->isDisabled());
    }

    public function test_is_disabled_when_bot_flag_set(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        $bot->disabled = true;
        $this->em->flush();

        $this->assertTrue($bot->isDisabled());
    }

    public function test_is_disabled_when_network_flag_set(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        $net->disabled = true;
        $this->em->flush();

        $this->assertFalse($bot->disabled);
        $this->assertTrue($bot->isDisabled());
    }

    public function test_is_disabled_without_network_is_own_flag(): void
    {
        $bot = new Bot();
        $this->assertFalse($bot->isDisabled());
        $bot->disabled = true;
        $this->assertTrue($bot->isDisabled());
    }

    public function test_to_string_marks_disabled(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b');
        $this->assertStringNotContainsString('disabled', (string)$bot);
        $this->assertStringNotContainsString('disabled', (string)$net);

        $bot->disabled = true;
        $this->assertStringContainsString('[disabled]', (string)$bot);

        $bot->disabled = false;
        $net->disabled = true;
        $this->assertStringContainsString('[disabled (network)]', (string)$bot);
        $this->assertStringContainsString('[disabled]', (string)$net);
    }
}
