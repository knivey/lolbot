<?php
namespace lolbot\cli_cmds;
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use lolbot\entities\Network;
use lolbot\entities\User;
use library\user\Flags;

#[AsCommand("user:flags")]
class user_flags extends Command
{
    protected function configure(): void
    {
        $this->addArgument("network", InputArgument::REQUIRED, "Network name");
        $this->addArgument("name", InputArgument::REQUIRED, "User name");
        $this->addArgument("flags", InputArgument::IS_ARRAY, "Flag ops: add via +flag or bare flag, remove via -flag, ^flag or !flag. On the command line put -- before dash-forms (e.g. user:flags net user -- -admin) so they are not parsed as options");
        $this->addOption("list", "l", InputOption::VALUE_NONE, "List current flags");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;

        $networkArg = $input->getArgument("network");
        if (!is_string($networkArg)) {
            throw new \LogicException("'network' argument must be a string");
        }
        $nameArg = $input->getArgument("name");
        if (!is_string($nameArg)) {
            throw new \LogicException("'name' argument must be a string");
        }

        // Networks are a tiny table; match case-insensitively in PHP so
        // mb case-folding applies regardless of the db's LOWER() semantics.
        $network = null;
        foreach ($entityManager->getRepository(Network::class)->findAll() as $net) {
            if (mb_strtolower($net->name) === mb_strtolower($networkArg)) {
                $network = $net;
                break;
            }
        }
        if (!$network) {
            $output->writeln("<error>No network by name $networkArg</error>");
            return Command::FAILURE;
        }

        $user = $entityManager->getRepository(User::class)->findOneBy([
            "network_id" => $network->id,
            "nameLowered" => mb_strtolower($nameArg),
        ]);
        if (!$user) {
            $output->writeln("<error>No user $nameArg on network {$network->name}</error>");
            return Command::FAILURE;
        }

        /** @var array<int, mixed> $rawOps */
        $rawOps = $input->getArgument("flags");
        // IS_ARRAY guarantees a list of strings, be defensive for programmatic callers
        $ops = array_values(array_filter($rawOps, "is_string"));

        if ($input->getOption("list") || $ops === []) {
            $output->writeln($this->formatFlags($user, $network));
            return Command::SUCCESS;
        }

        // normalize every op first, then validate the whole batch against
        // the flag registry before touching the user (all-or-nothing)
        /** @var list<array{0: '+'|'-', 1: string}> $parsed */
        $parsed = [];
        $unknown = [];
        foreach ($ops as $opArg) {
            if (str_starts_with($opArg, "+")) {
                $op = "+";
                $flag = substr($opArg, 1);
            } elseif (str_starts_with($opArg, "-") || str_starts_with($opArg, "^") || str_starts_with($opArg, "!")) {
                // '^flag' and '!flag' are removal spellings too: the console
                // tokenizes leading-dash tokens as options, so removal needs
                // prefixes that survive parsing. Normalized to the '-' op
                // applyFlag expects.
                $op = "-";
                $flag = substr($opArg, 1);
            } else {
                // bare flag name means add
                $op = "+";
                $flag = $opArg;
            }
            $parsed[] = [$op, $flag];
            if (!Flags::defined($flag)) {
                $unknown[] = $flag;
            }
        }
        if ($unknown !== []) {
            $output->writeln("<error>unknown flag(s): " . Flags::formatList(array_values(array_unique($unknown)))
                . " (valid: " . Flags::formatList(array_keys(Flags::definitions())) . ")</error>");
            return Command::FAILURE;
        }
        foreach ($parsed as [$op, $flag]) {
            $user->flags = User::applyFlag($user->flags, $op, $flag);
        }
        $entityManager->persist($user);
        $entityManager->flush();

        $output->writeln($this->formatFlags($user, $network));
        return Command::SUCCESS;
    }

    private function formatFlags(User $user, Network $network): string
    {
        $flags = $user->flags === [] ? "(none)" : implode(", ", $user->flags);
        return "Flags for {$user->name} on {$network->name}: $flags";
    }
}
