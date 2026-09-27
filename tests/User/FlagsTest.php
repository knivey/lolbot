<?php
// tests/User/FlagsTest.php
use library\user\Flags;
use PHPUnit\Framework\TestCase;

class FlagsTest extends TestCase
{
    protected function tearDown(): void
    {
        Flags::reset();
    }

    public function test_admin_is_defined_and_grants_wildcard(): void
    {
        $this->assertTrue(Flags::defined('admin'));
        $this->assertSame(['*'], Flags::definitions()['admin']);
    }

    public function test_undefined_flag_is_not_defined_and_never_passes(): void
    {
        $this->assertFalse(Flags::defined('nope'));
        $this->assertFalse(Flags::passes(['nope'], 'admin'));
    }

    public function test_plain_flag_passes_for_holder(): void
    {
        $this->assertTrue(Flags::passes(['quotes'], 'quotes'));
        $this->assertFalse(Flags::passes(['quotes'], 'kick'));
    }

    public function test_wildcard_grants_everything(): void
    {
        $this->assertTrue(Flags::passes(['admin'], 'anything.at.all'));
        $this->assertTrue(Flags::passes(['admin'], 'admin'));
    }

    public function test_groups_expand_transitively(): void
    {
        Flags::define('manager', ['quotes', 'set']);
        Flags::define('boss', ['manager', 'kick']);
        // boss -> manager -> quotes/set, boss -> kick
        $this->assertTrue(Flags::passes(['boss'], 'quotes'));
        $this->assertTrue(Flags::passes(['boss'], 'set'));
        $this->assertTrue(Flags::passes(['boss'], 'kick'));
        $this->assertFalse(Flags::passes(['boss'], 'admin'));
        $this->assertSame(
            ['boss', 'manager', 'kick', 'quotes', 'set'],
            Flags::expand(['boss']),
        );
    }

    public function test_cycles_terminate(): void
    {
        Flags::define('a', ['b']);
        Flags::define('b', ['a']);
        $this->assertSame(['a', 'b'], Flags::expand(['a']));
        $this->assertFalse(Flags::passes(['a'], 'c'));
    }

    public function test_expand_filters_non_strings_and_dedupes(): void
    {
        Flags::define('g', ['x']);
        $this->assertSame(['x', 'g'], Flags::expand(['x', 3, 'x', 'g', null, true]));
    }

    public function test_define_overwrites_and_reset_restores_defaults(): void
    {
        Flags::define('admin', []);
        $this->assertSame([], Flags::definitions()['admin']);
        Flags::reset();
        $this->assertSame(['*'], Flags::definitions()['admin']);
    }
}
