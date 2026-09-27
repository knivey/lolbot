<?php

namespace Tests\User;

use library\user\Access;
use library\user\Flags;
use lolbot\entities\User;

require_once __DIR__ . '/../../vendor/autoload.php';

class AccessFlagsTest extends \PHPUnit\Framework\TestCase
{
    public function test_real_user_entity_carries_its_flags(): void
    {
        $user = new User();
        $user->network_id = 1;
        $user->name = 'Knivey';
        $user->nameLowered = 'knivey';
        $user->flags = ['admin', 'trusted'];
        $this->assertTrue(Access::userHasFlag($user, 'admin'));
        $this->assertTrue(Access::userHasFlag($user, 'trusted'));
        // 'admin' now expands to '*' through the Flags registry, so the
        // not-held-flag denial is pinned on a non-admin holder instead
        $plain = new User();
        $plain->flags = ['trusted'];
        $this->assertFalse(Access::userHasFlag($plain, 'nope'));
    }

    public function test_real_user_entity_with_empty_flags_denies(): void
    {
        $user = new User();
        $user->flags = [];
        $this->assertFalse(Access::userHasFlag($user, 'admin'));
    }

    public function test_non_string_entries_are_ignored_not_cast(): void
    {
        $user = new User();
        // deliberately not a list<string>: pins the defensive filtering
        $user->flags = [123, 3.14, false, null, [], 'trusted']; // @phpstan-ignore assign.propertyType
        $this->assertTrue(Access::userHasFlag($user, 'trusted'));
        // strict comparison: int 123 must not satisfy string '123'
        $this->assertFalse(Access::userHasFlag($user, '123'));
        $this->assertFalse(Access::userHasFlag($user, '3.14'));
    }

    public function test_stdclass_with_public_flags_array_is_duck_typed(): void
    {
        $user = new \stdClass();
        $user->flags = ['trusted'];
        $this->assertTrue(Access::userHasFlag($user, 'trusted'));
        $this->assertFalse(Access::userHasFlag($user, 'admin'));
    }

    public function test_object_with_no_flags_property_denies(): void
    {
        $user = new \stdClass();
        $this->assertFalse(Access::userHasFlag($user, 'admin'));
    }

    public function test_string_flags_property_legacy_shape_denies(): void
    {
        $user = new \stdClass();
        $user->flags = 'admin,trusted';
        $this->assertFalse(Access::userHasFlag($user, 'admin'));
    }

    public function test_null_flags_property_denies(): void
    {
        $user = new \stdClass();
        $user->flags = null;
        $this->assertFalse(Access::userHasFlag($user, 'admin'));
    }

    public function test_flag_array_is_defensive(): void
    {
        $this->assertSame([], Access::flagArray(new \stdClass()));
        $o = new \stdClass();
        $o->flags = 'admin';
        $this->assertSame([], Access::flagArray($o));
        $o->flags = ['admin', 3, null];
        $this->assertSame(['admin'], Access::flagArray($o));
    }

    public function test_user_has_flag_is_group_aware(): void
    {
        Flags::define('manager', ['quotes']);
        $o = new \stdClass();
        $o->flags = ['manager'];
        $this->assertTrue(Access::userHasFlag($o, 'quotes'));
        $this->assertFalse(Access::userHasFlag($o, 'kick'));
    }

    public function test_admin_passes_anything_via_wildcard(): void
    {
        $o = new \stdClass();
        $o->flags = ['admin'];
        $this->assertTrue(Access::userHasFlag($o, 'whatever'));
    }

    protected function tearDown(): void
    {
        Flags::reset();
    }
}
