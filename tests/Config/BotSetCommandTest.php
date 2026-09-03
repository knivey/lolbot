<?php
namespace Tests\Config;

use lolbot\config\ConfigService;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__ . '/../../vendor/autoload.php';

class BotSetCommandTest extends ConfigTestCase
{
    private function runCommand(string $setting, string $value, int $botId): int
    {
        $GLOBALS['entityManager'] = $this->em;
        $GLOBALS['config'] = $GLOBALS['config'] ?? [];
        $tester = new CommandTester(new \lolbot\cli_cmds\bot_set());
        return $tester->execute(['bot' => (string)$botId, 'setting' => $setting, 'value' => $value]);
    }

    private function makeBot(): \lolbot\entities\Bot
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        return $svc->createBot($net, 'b');
    }

    public function test_sets_disabled_true(): void
    {
        $bot = $this->makeBot();
        $this->runCommand('disabled', 'true', $bot->id);
        $this->assertTrue($bot->disabled);
    }

    public function test_sets_disabled_1_and_back_0(): void
    {
        $bot = $this->makeBot();
        $this->runCommand('disabled', '1', $bot->id);
        $this->assertTrue($bot->disabled);
        $this->runCommand('disabled', '0', $bot->id);
        $this->assertFalse($bot->disabled);
    }

    public function test_rejects_garbage(): void
    {
        $bot = $this->makeBot();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('disabled must be true or false');
        $this->runCommand('disabled', 'garbage', $bot->id);
    }
}
