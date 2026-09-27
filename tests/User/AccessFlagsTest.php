<?php

namespace Tests\User;

use library\user\Access;
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
        $this->assertFalse(Access::userHasFlag($user, 'nope'));
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
}
