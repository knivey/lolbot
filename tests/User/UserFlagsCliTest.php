<?php

namespace Tests\User;

use lolbot\entities\User;

require_once __DIR__ . '/../../vendor/autoload.php';

class UserFlagsCliTest extends \PHPUnit\Framework\TestCase
{
    public function test_adds_flag_to_empty_list(): void
    {
        $this->assertSame(['admin'], User::applyFlag([], '+', 'admin'));
    }

    public function test_add_appends_after_existing_flags(): void
    {
        $this->assertSame(['admin', 'trusted'], User::applyFlag(['admin'], '+', 'trusted'));
    }

    public function test_adding_existing_flag_dedupes(): void
    {
        $this->assertSame(['admin'], User::applyFlag(['admin'], '+', 'admin'));
    }

    public function test_add_dedupes_preexisting_duplicates_keeping_order(): void
    {
        $this->assertSame(['admin', 'trusted'], User::applyFlag(['admin', 'trusted', 'admin'], '+', 'admin'));
        $this->assertSame(['admin', 'trusted', 'new'], User::applyFlag(['admin', 'trusted', 'admin'], '+', 'new'));
    }

    public function test_removes_flag(): void
    {
        $this->assertSame(['trusted'], User::applyFlag(['admin', 'trusted'], '-', 'admin'));
    }

    public function test_remove_reindexes_the_list(): void
    {
        $result = User::applyFlag(['admin', 'trusted'], '-', 'admin');
        $this->assertSame(['trusted'], $result);
        $this->assertSame([0], array_keys($result));
    }

    public function test_remove_drops_every_occurrence(): void
    {
        $this->assertSame(['b'], User::applyFlag(['a', 'b', 'a'], '-', 'a'));
    }

    public function test_removing_missing_flag_is_noop(): void
    {
        $this->assertSame(['admin'], User::applyFlag(['admin'], '-', 'nope'));
        $this->assertSame([], User::applyFlag([], '-', 'nope'));
    }

    public function test_invalid_op_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        User::applyFlag([], '=', 'admin');
    }

    public function test_empty_op_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        User::applyFlag([], '', 'admin');
    }

    public function test_empty_flag_name_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        User::applyFlag([], '+', '');
    }
}
