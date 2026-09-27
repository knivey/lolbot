<?php
// tests/TestEnv/RealProfilesTest.php
use library\testenv\Profile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RealProfilesTest extends TestCase
{
    /** @param list<string> $expectEngines */
    #[DataProvider('profileProvider')]
    public function test_real_profile_loads_and_pins_engines(string $name, string $net, array $expectEngines): void
    {
        $p = Profile::load($name);
        $nets = array_column($p->networks(), null, 'name');
        $this->assertArrayHasKey($net, $nets, "profile {$name} lacks network {$net}");
        $this->assertSame($expectEngines, $nets[$net]['auth_engines']);
        foreach ($nets as $n) {
            $this->assertNotEmpty($n['servers']);
            $this->assertNotEmpty($n['bots']);
            foreach ($n['bots'] as $b) { // @phpstan-ignore-line
                $this->assertNotSame('', $b['name']); // @phpstan-ignore-line
            }
        }
    }

    /** @return list<array{string, string, list<string>}> */
    public static function profileProvider(): array
    {
        return [
            ['ownnet', 'birdnest', ['hostmask', 'manual']],
            ['gamesurge', 'gamesurge', ['vhost', 'whox', 'hostmask', 'manual']],
            ['libera', 'libera', ['account-tag', 'hostmask', 'manual']],
        ];
    }

    public function test_all_profile_contains_all_three(): void
    {
        $p = Profile::load('all');
        $this->assertCount(3, $p->networks());
    }
}
