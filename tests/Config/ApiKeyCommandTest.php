<?php
namespace Tests\Config;

use lolbot\config\ConfigService;
use lolbot\entities\ApiKey;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__ . '/../../vendor/autoload.php';

class ApiKeyCommandTest extends ConfigTestCase
{
    private function bootGlobals(): void
    {
        $GLOBALS['entityManager'] = $this->em;
        $GLOBALS['config'] ??= [];
    }

    public function test_add_lists_and_deletes(): void
    {
        $this->bootGlobals();

        $add = new CommandTester(new \lolbot\cli_cmds\apikey_add());
        $add->execute(['key' => 'sitekey', '--label' => 'image upload site', '--scope' => ['aidesc']]);

        $svc = new ConfigService($this->em);
        $keys = $svc->listApiKeys();
        $this->assertCount(1, $keys);
        $this->assertSame('sitekey', $keys[0]->key);
        $this->assertSame('image upload site', $keys[0]->label);
        $this->assertSame(['aidesc'], $keys[0]->scopes);

        $list = new CommandTester(new \lolbot\cli_cmds\apikey_list());
        $list->execute([]);
        $this->assertStringContainsString('sitekey', $list->getDisplay());
        $this->assertStringContainsString('aidesc', $list->getDisplay());

        $del = new CommandTester(new \lolbot\cli_cmds\apikey_del());
        $del->execute(['id' => (string)$keys[0]->id]);
        $this->assertSame([], $svc->listApiKeys());
    }

    public function test_add_requires_a_scope(): void
    {
        $this->bootGlobals();
        $add = new CommandTester(new \lolbot\cli_cmds\apikey_add());
        $this->expectException(\InvalidArgumentException::class);
        $add->execute(['key' => 'sitekey']);
    }

    public function test_add_rejects_unknown_scope(): void
    {
        $this->bootGlobals();
        $add = new CommandTester(new \lolbot\cli_cmds\apikey_add());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nope');
        $add->execute(['key' => 'sitekey', '--scope' => ['nope']]);
    }
}
