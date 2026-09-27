<?php
// tests/TestEnv/ProfileTest.php
use library\testenv\Profile;
use library\testenv\ProfileException;
use PHPUnit\Framework\TestCase;

class ProfileTest extends TestCase
{
    public function test_loads_fixture_with_defaults(): void
    {
        $p = Profile::load('fixture_test');
        $this->assertSame('fixture_test', $p->name());
        $nets = $p->networks();
        $this->assertCount(3, $nets);
        $this->assertSame('FixtureNet', $nets[0]['name']);
        $this->assertSame('irc.fixture.example', $nets[0]['servers'][0]['address']); // @phpstan-ignore-line
        $this->assertSame(6697, $nets[0]['servers'][0]['port']); // @phpstan-ignore-line
        $this->assertTrue($nets[0]['servers'][0]['ssl']); // @phpstan-ignore-line
        $this->assertSame('DevBot1', $nets[0]['bots'][0]['name']); // @phpstan-ignore-line
        $this->assertSame(['#testchan'], $nets[0]['bots'][0]['channels']); // @phpstan-ignore-line
        $this->assertSame('Tester', $p->driver()['nick']);
        // default seed = driver admin
        $seed = $p->seedUsers('FixtureNet');
        $this->assertSame('Tester', $seed[0]['name']);
        $this->assertSame(['admin'], $seed[0]['flags']);
    }

    public function test_seed_users_declared_and_none(): void
    {
        $p = Profile::load('fixture_test');
        // fixture declares extra users on net2 and seed_users: none on net3
        $seed2 = $p->seedUsers('FixtureNet2');
        $this->assertSame(['quotes'], $seed2[0]['flags']);
        $this->assertTrue($seed2[1]['paranoid'] ?? false);
        $this->assertSame([], $p->seedUsers('FixtureNet3'));
    }

    public function test_missing_networks_key_throws_naming_it(): void
    {
        $this->expectException(ProfileException::class);
        $this->expectExceptionMessage('networks');
        Profile::load('fixture_missing_networks');
    }

    public function test_missing_server_address_throws_naming_it(): void
    {
        $this->expectException(ProfileException::class);
        $this->expectExceptionMessage('address');
        Profile::load('fixture_missing_address');
    }

    public function test_missing_bot_name_throws_naming_it(): void
    {
        $this->expectException(ProfileException::class);
        $this->expectExceptionMessage('name');
        Profile::load('fixture_missing_botname');
    }

    public function test_unknown_profile_throws(): void
    {
        $this->expectException(ProfileException::class);
        Profile::load('no_such_profile');
    }
}
