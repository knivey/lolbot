<?php

namespace Tests\Config;

use lolbot\config\ConfigService;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__ . '/../../vendor/autoload.php';

class NetworkSetCommandTest extends ConfigTestCase
{
    private function runCommand(string $setting, string $value, int $netId): int
    {
        $GLOBALS['entityManager'] = $this->em;
        $GLOBALS['config'] ??= [];
        $tester = new CommandTester(new \lolbot\cli_cmds\network_set());
        return $tester->execute(['network' => (string) $netId, 'setting' => $setting, 'value' => $value]);
    }

    private function makeNet(): \lolbot\entities\Network
    {
        $svc = new ConfigService($this->em);
        return $svc->createNetwork('N');
    }

    public function test_sets_disabled_true(): void
    {
        $net = $this->makeNet();
        $this->runCommand('disabled', 'true', $net->id);
        $this->assertTrue($net->disabled);
    }

    public function test_sets_disabled_false(): void
    {
        $net = $this->makeNet();
        $net->disabled = true;
        $this->em->flush();
        $this->runCommand('disabled', 'false', $net->id);
        $this->assertFalse($net->disabled);
    }

    public function test_rejects_garbage(): void
    {
        $net = $this->makeNet();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('disabled must be true or false');
        $this->runCommand('disabled', 'garbage', $net->id);
    }
}
