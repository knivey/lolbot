<?php

namespace Tests\User;

use lolbot\entities\Network;
use lolbot\entities\User;
use library\user\Flags;
use PHPUnit\Framework\Assert;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Config\ConfigTestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Drives the real user:flags command through StringInput, which tokenizes
 * exactly like the real ArgvInput command line (dash-prefixed tokens become
 * console options). ArrayInput/CommandTester bypass tokenization and so
 * cannot catch this class of bug.
 */
class UserFlagsStringInputTest extends ConfigTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        // the removal tests need a second registered flag; scripts
        // register their own flags at load time the same way
        Flags::reset();
        Flags::define('trusted', []);

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

    protected function tearDown(): void
    {
        Flags::reset();
        parent::tearDown();
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

    public function test_dash_flag_without_terminator_is_parsed_as_option(): void
    {
        $command = new \lolbot\cli_cmds\user_flags();
        $input = new StringInput('user:flags ScratchNet Knivey -admin');
        // StringInput tokenizes like ArgvInput: '-admin' hits the option
        // parser before execute() ever runs. This pins why the '^'/'!'
        // removal spellings exist.
        $this->expectException(\Symfony\Component\Console\Exception\RuntimeException::class);
        $this->expectExceptionMessage('The "-a" option does not exist.');
        $input->bind($command->getDefinition());
    }

    public function test_caret_and_bang_and_terminated_dash_bind_as_arguments(): void
    {
        $command = new \lolbot\cli_cmds\user_flags();
        $command->setApplication(new Application('test'));
        // Command::run() merges the app definition (synthetic 'command'
        // argument) before binding; mirror that for a faithful pin.
        $command->mergeApplicationDefinition();
        $input = new StringInput('user:flags net user ^a !b -- -c');
        $input->bind($command->getDefinition());
        $this->assertSame(['^a', '!b', '-c'], $input->getArgument('flags'));
    }

    public function test_plus_form_adds(): void
    {
        [$code, $out] = $this->runCli('user:flags scratchnet KNIVEY +admin');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('admin', $out);
        $this->assertSame(['admin'], $this->persistedFlags());
    }

    public function test_caret_form_removes(): void
    {
        $this->runCli('user:flags ScratchNet Knivey +admin trusted');
        [$code, $out] = $this->runCli('user:flags ScratchNet Knivey ^admin');
        $this->assertSame(0, $code);
        $this->assertSame(['trusted'], $this->persistedFlags());
    }

    public function test_bang_form_removes(): void
    {
        $this->runCli('user:flags ScratchNet Knivey +admin trusted');
        [$code, $out] = $this->runCli('user:flags ScratchNet Knivey !trusted');
        $this->assertSame(0, $code);
        $this->assertSame(['admin'], $this->persistedFlags());
    }

    public function test_dash_form_after_terminator_removes(): void
    {
        $this->runCli('user:flags ScratchNet Knivey +admin');
        [$code, $out] = $this->runCli('user:flags ScratchNet Knivey -- -admin');
        $this->assertSame(0, $code);
        $this->assertSame([], $this->persistedFlags());
    }

    public function test_unknown_removal_flag_is_refused_nothing_persisted(): void
    {
        // registry guard covers removal spellings too: '^bogus' must
        // refuse the batch and leave previously-applied flags intact
        $this->runCli('user:flags ScratchNet Knivey +admin');
        [$code, $out] = $this->runCli('user:flags ScratchNet Knivey ^bogus');
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('unknown flag(s): bogus', $out);
        $this->assertStringContainsString('valid:', $out);
        $this->assertSame(['admin'], $this->persistedFlags());
    }
}
