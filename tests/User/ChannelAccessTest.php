<?php
namespace Tests\User;

use Doctrine\ORM\EntityManager;
use library\user\Access;
use library\user\ChannelAccess;
use library\user\ChannelFlagRepo;
use library\user\Flags;
use library\user\IdentityCache;
use library\user\IdentityService;
use library\user\UserHostmaskRepo;
use library\user\UserRepo;
use library\user\UserRepos;
use library\user\UserSystem;
use lolbot\entities\Channel;
use lolbot\entities\Network;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../library/Irc/Consts.php';

/**
 * ChannelAccess::passesInChannel() against a real final UserSystem with
 * a stubbed EntityManager — the same harness shape as AclMiddlewareTest:
 * channelByName()'s DQL is the only EM touch, stubbed at createQuery()
 * with a per-test channel row; the ChannelFlagRepo stub carries the
 * per-test grant. Production wiring is exercised by the scratch-DB e2e
 * (/tmp/opencode/setcmd_e2e.php) instead.
 */
class ChannelAccessTest extends \PHPUnit\Framework\TestCase
{
    public function tearDown(): void
    {
        // reset global Access state so the before-hook doesn't leak into other tests
        Access::before(null);
        Flags::reset();
    }

    /**
     * Real final UserSystem with a stubbed EntityManager: channelByName()'s
     * DQL is the only EM touch, stubbed at createQuery() with a per-test
     * channel row, while the repos carry a per-test channel-flags grant
     * (fakes for the untouched users/masks repos).
     */
    private function makeBundle(?object $channel, ?object $grant): UserSystem
    {
        $query = $this->createStub(\Doctrine\ORM\Query::class);
        $query->method('getOneOrNullResult')->willReturn($channel);
        $em = $this->createStub(EntityManager::class);
        $em->method('createQuery')->willReturn($query);
        $channelFlags = $this->createStub(ChannelFlagRepo::class);
        $channelFlags->method('findForChannelUser')->willReturn($grant);
        $network = new Network();
        $network->name = 'TestNet';
        return new UserSystem(
            $network,
            new IdentityService(new IdentityCache(), []),
            new UserRepos(
                $this->createStub(UserRepo::class),
                $this->createStub(UserHostmaskRepo::class),
                $channelFlags,
            ),
            new IdentityCache(),
            $em,
        );
    }

    private function makeChannel(): Channel
    {
        $channel = new Channel();
        $channel->id = 7;
        $channel->name = '#test';
        return $channel;
    }

    public function test_unknown_channel_denies_even_with_admin_flags(): void
    {
        // channelByName miss is checked first: no flag set, not even the
        // superadmin before-hook, can pass an unknown channel
        Access::before(fn(object ...$args): bool => true);
        $user = (object)['id' => 3, 'flags' => ['admin']];
        $this->assertFalse(ChannelAccess::passesInChannel($this->makeBundle(null, null), $user, '#nope', 'admin'));
    }

    public function test_before_hook_true_short_circuits_with_no_flags(): void
    {
        // owner can never be locked out: before-hook true passes with NO
        // flags and no channel grant
        Access::before(fn(object ...$args): bool => true);
        $user = (object)['id' => 3, 'flags' => []];
        $this->assertTrue(
            ChannelAccess::passesInChannel($this->makeBundle($this->makeChannel(), null), $user, '#test', 'admin')
        );
    }

    public function test_before_hook_null_falls_through_to_flags(): void
    {
        Access::before(fn(object ...$args): ?bool => null);
        $user = (object)['id' => 3, 'flags' => []];
        $this->assertFalse(
            ChannelAccess::passesInChannel($this->makeBundle($this->makeChannel(), null), $user, '#test', 'admin')
        );
    }

    public function test_network_flags_alone_pass(): void
    {
        // fall-through: no grant row, but the user's network flags satisfy
        $user = (object)['id' => 3, 'flags' => ['quotes']];
        $this->assertTrue(
            ChannelAccess::passesInChannel($this->makeBundle($this->makeChannel(), null), $user, '#test', 'quotes')
        );
    }

    public function test_channel_grant_alone_passes(): void
    {
        // union fall-through the other way: no network flags, grant only
        $user = (object)['id' => 3, 'flags' => []];
        $grant = (object)['id' => 9, 'flags' => ['quotes']];
        $this->assertTrue(
            ChannelAccess::passesInChannel($this->makeBundle($this->makeChannel(), $grant), $user, '#test', 'quotes')
        );
    }

    public function test_no_flags_no_grant_denies(): void
    {
        $user = (object)['id' => 3, 'flags' => []];
        $this->assertFalse(
            ChannelAccess::passesInChannel($this->makeBundle($this->makeChannel(), null), $user, '#test', 'admin')
        );
    }

    public function test_union_merges_network_flags_with_channel_grant(): void
    {
        // neither half passes alone; the union does ('admin' grants '*')
        $user = (object)['id' => 3, 'flags' => ['quotes']];
        $grant = (object)['id' => 9, 'flags' => ['admin']];
        $bundle = $this->makeBundle($this->makeChannel(), $grant);
        $this->assertTrue(ChannelAccess::passesInChannel($bundle, $user, '#test', 'quotes'));
        $this->assertTrue(ChannelAccess::passesInChannel($bundle, $user, '#test', 'anything.else'));
    }

    public function test_grant_group_expansion_applies(): void
    {
        // a held flag grants its defined grants: 'setter' group passes 'opts.set'
        Flags::define('setter', ['opts.set']);
        $user = (object)['id' => 3, 'flags' => []];
        $grant = (object)['id' => 9, 'flags' => ['setter']];
        $this->assertTrue(
            ChannelAccess::passesInChannel($this->makeBundle($this->makeChannel(), $grant), $user, '#test', 'opts.set')
        );
    }

    public function test_grant_looked_up_for_the_resolved_channel_and_user(): void
    {
        // the grant row must be fetched for THIS channel's id and THIS
        // user's id — pin the routing, not just the verdict
        $user = (object)['id' => 3, 'flags' => []];
        $channel = $this->makeChannel();
        $seen = [];
        $query = $this->createStub(\Doctrine\ORM\Query::class);
        $query->method('getOneOrNullResult')->willReturn($channel);
        $em = $this->createStub(EntityManager::class);
        $em->method('createQuery')->willReturn($query);
        $channelFlags = $this->createStub(ChannelFlagRepo::class);
        $channelFlags->method('findForChannelUser')->willReturnCallback(
            function (int $channelId, int $userId) use (&$seen): ?object {
                $seen[] = [$channelId, $userId];
                return null;
            }
        );
        $network = new Network();
        $network->name = 'TestNet';
        $bundle = new UserSystem(
            $network,
            new IdentityService(new IdentityCache(), []),
            new UserRepos(
                $this->createStub(UserRepo::class),
                $this->createStub(UserHostmaskRepo::class),
                $channelFlags,
            ),
            new IdentityCache(),
            $em,
        );
        ChannelAccess::passesInChannel($bundle, $user, '#test', 'admin');
        $this->assertSame([[7, 3]], $seen);
    }

    public function test_flagless_user_object_treated_as_no_flags(): void
    {
        // defensive flag extraction: a user object without a flags property
        // (or a non-array one) must deny, not crash
        $plain = (object)['id' => 3];
        $bundle = $this->makeBundle($this->makeChannel(), null);
        $this->assertFalse(ChannelAccess::passesInChannel($bundle, $plain, '#test', 'admin'));
        $scalarFlags = (object)['id' => 3, 'flags' => 'admin'];
        $this->assertFalse(ChannelAccess::passesInChannel($bundle, $scalarFlags, '#test', 'admin'));
    }

    public function test_defensive_grant_flags_shape(): void
    {
        // a grant row whose flags came back malformed must not crash or pass
        $user = (object)['id' => 3, 'flags' => []];
        $grant = (object)['id' => 9, 'flags' => 'admin'];
        $this->assertFalse(
            ChannelAccess::passesInChannel($this->makeBundle($this->makeChannel(), $grant), $user, '#test', 'admin')
        );
    }
}
