<?php

namespace Tests\User;

use lolbot\entities\Network;
use lolbot\entities\User;
use PHPUnit\Framework\Assert;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Config\ConfigTestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

class UserFlagsCliTest extends ConfigTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $net = new Network();
        $net->name = 'ScratchNet';
        $this->em->persist($net);
        $this->em->flush();

        $user = new User();
        $user->network_id = $net->id;
        $user->name = 'Knivey';
        $user->nameLowered = mb_strtolower('Knivey');
        $this->em->persist($user);
        $this->em->flush();
        $this->userId = $user->id;
    }

    /**
     * Run the command with a real tokenized command line (including the
     * command name, consumed by the synthetic 'command' argument exactly
     * like the application does).
     *
     * @return array{0: int, 1: string} exit code and output
     */
    private function runCli(string $commandLine): array
    {
        $GLOBALS['entityManager'] = $this->em;
        $command = new \lolbot\cli_cmds\user_flags();
        $command->setApplication(new Application('test'));
        $output = new BufferedOutput();
        $code = $command->run(new StringInput($commandLine), $output);
        return [$code, $output->fetch()];
    }

    /**
     * @return list<string>
     */
    private function persistedFlags(): array
    {
        $this->em->clear();
        $user = $this->em->find(User::class, $this->userId);
        Assert::assertInstanceOf(User::class, $user);
        return $user->flags;
    }

    public function test_unknown_flag_is_refused_with_valid_names(): void
    {
        // registry guard: the whole batch is refused before anything is
        // applied, naming the unknown flag and the valid set
        [$code, $out] = $this->runCli('user:flags ScratchNet Knivey +bogus');
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('unknown flag(s): bogus', $out);
        $this->assertStringContainsString('valid:', $out);
        $this->assertSame([], $this->persistedFlags());
    }

    public function test_known_flag_still_applies(): void
    {
        [$code, $out] = $this->runCli('user:flags ScratchNet Knivey +admin');
        $this->assertSame(0, $code, $out);
        $this->assertSame(['admin'], $this->persistedFlags());
    }

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
