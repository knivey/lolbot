<?php
namespace Tests\Config;

use lolbot\config\ConfigService;
use lolbot\config\DuplicateNameException;
use lolbot\config\InvalidSettingException;
use lolbot\config\NoopChangeNotifier;
use lolbot\entities\ApiKey;

require_once __DIR__ . '/../../vendor/autoload.php';

class CapturingKeyNotifier extends NoopChangeNotifier
{
    /** @var list<\lolbot\config\ConfigChange> */
    public array $changes = [];

    public function notify(\lolbot\config\ConfigChange $change): void
    {
        $this->changes[] = $change;
    }
}

class ConfigServiceApiKeyTest extends ConfigTestCase
{
    private ConfigService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ConfigService($this->em);
    }

    public function test_add_api_key(): void
    {
        $key = $this->svc->addApiKey('sitekey', 'image upload site', ['aidesc']);
        $this->assertSame('sitekey', $key->key);
        $this->assertSame('image upload site', $key->label);
        $this->assertSame(['aidesc'], $key->scopes);
        $this->assertTrue($key->hasScope('aidesc'));

        $dup = $this->svc->addApiKey('dedupe', null, ['aidesc', 'aidesc']);
        $this->assertSame(['aidesc'], $dup->scopes);
    }

    public function test_add_duplicate_key_throws(): void
    {
        $this->svc->addApiKey('sitekey', null, ['aidesc']);
        try {
            $this->svc->addApiKey('sitekey', null, ['aidesc']);
            $this->fail("Expected DuplicateNameException for exact key");
        } catch (DuplicateNameException $e) {
            $this->addToAssertionCount(1);
        }
        try {
            $this->svc->addApiKey(' sitekey ', null, ['aidesc']);
            $this->fail("Expected DuplicateNameException for ' sitekey ' after trim");
        } catch (DuplicateNameException $e) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_add_unknown_scope_throws(): void
    {
        $this->expectException(InvalidSettingException::class);
        $this->svc->addApiKey('sitekey', null, ['nope']);
    }

    public function test_add_empty_key_throws(): void
    {
        $this->expectException(InvalidSettingException::class);
        $this->svc->addApiKey('  ', null, ['aidesc']);
    }

    public function test_list_get_delete(): void
    {
        $key = $this->svc->addApiKey('sitekey', null, ['aidesc']);
        $this->assertCount(1, $this->svc->listApiKeys());
        $loaded = $this->svc->getApiKey($key->id);
        $this->assertNotNull($loaded);
        $this->assertSame($key->id, $loaded->id);

        $id = $key->id;
        $this->svc->deleteApiKey($key);
        $this->assertSame([], $this->svc->listApiKeys());
        $this->assertNull($this->em->getRepository(ApiKey::class)->find($id));
    }

    public function test_create_and_delete_notify(): void
    {
        $notifier = new CapturingKeyNotifier();
        $svc = new ConfigService($this->em, $notifier);
        $key = $svc->addApiKey('sitekey', null, ['aidesc']);
        $id = $key->id;
        $svc->deleteApiKey($key);

        $this->assertCount(2, $notifier->changes);
        $this->assertSame('api_key', $notifier->changes[0]->entityType);
        $this->assertSame($id, $notifier->changes[0]->id);
        $this->assertSame('create', $notifier->changes[0]->action);
        $this->assertSame('api_key', $notifier->changes[1]->entityType);
        $this->assertSame('delete', $notifier->changes[1]->action);
    }
}
