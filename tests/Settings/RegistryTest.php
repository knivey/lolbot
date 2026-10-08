<?php
// tests/Settings/RegistryTest.php
use library\settings\Setting;
use library\settings\SettingsRegistry;
use PHPUnit\Framework\TestCase;

#[Setting('test.one', type: 'bool', default: true, scope: 'account', description: 'a bool')]
#[Setting('test.two', type: 'enum', enum_of: ['a', 'b'], default: 'a')]
function registryTestFixture(): void
{
}

class RegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        SettingsRegistry::reset();
    }

    public function test_programmatic_define_and_get(): void
    {
        SettingsRegistry::define(new Setting('lastfm', type: 'string', default: '', scope: 'account', description: 'your last.fm username'));
        $s = SettingsRegistry::get('lastfm');
        $this->assertNotNull($s);
        $this->assertSame('string', $s->type);
        $this->assertSame('', $s->default);
        $this->assertSame('account', $s->scope);
        $this->assertNull(SettingsRegistry::storage('lastfm'));
    }

    public function test_duplicate_name_throws(): void
    {
        SettingsRegistry::define(new Setting('x'));
        $this->expectException(InvalidArgumentException::class);
        SettingsRegistry::define(new Setting('x'));
    }

    public function test_attribute_scan_loads_fixture(): void
    {
        SettingsRegistry::loadAttributeSettings();
        $one = SettingsRegistry::get('test.one');
        $this->assertNotNull($one);
        $this->assertTrue($one->default);
        $this->assertSame('account', $one->scope);
        $two = SettingsRegistry::get('test.two');
        $this->assertNotNull($two);
        $this->assertSame(['a', 'b'], $two->enum_of);
        $this->assertSame('channel', $two->scope); // default scope
        $this->assertSame('admin', $two->flag);    // default flag
    }

    public function test_attribute_scan_is_idempotent(): void
    {
        SettingsRegistry::loadAttributeSettings();
        SettingsRegistry::loadAttributeSettings();
        $this->assertNotNull(SettingsRegistry::get('test.one'));
    }

    public function test_scope_and_irc_filters(): void
    {
        SettingsRegistry::define(new Setting('acct', scope: 'account'));
        SettingsRegistry::define(new Setting('chan', scope: 'channel', irc: false));
        SettingsRegistry::define(new Setting('webchan', scope: 'channel', irc: false));
        SettingsRegistry::define(new Setting('normal', scope: 'channel'));
        $this->assertCount(2, SettingsRegistry::all(scope: 'account'));
        $this->assertArrayNotHasKey('chan', SettingsRegistry::all(irc: true));
        $this->assertArrayHasKey('chan', SettingsRegistry::all(irc: false));
    }

    public function test_attach_storage(): void
    {
        SettingsRegistry::define(new Setting('adapted'));
        $storage = $this->createStub(library\settings\SettingStorage::class);
        SettingsRegistry::attachStorage('adapted', $storage);
        $this->assertSame($storage, SettingsRegistry::storage('adapted'));
        $this->expectException(InvalidArgumentException::class);
        SettingsRegistry::attachStorage('missing', $storage);
    }
}
