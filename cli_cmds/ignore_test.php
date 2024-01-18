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
use lolbot\entities\Ignore;
use lolbot\entities\Network;

#[AsCommand("ignore:test")]
class ignore_test extends Command
{
    protected function configure(): void
    {
        $this->addOption('network', "N", InputOption::VALUE_REQUIRED, 'Network ID')
            ->addArgument("host", InputArgument::REQUIRED, "Hostname to test if any ignores match")
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        /**
         * @var \lolbot\entities\IgnoreRepository $ignoreRepository
         */
        $ignoreRepository = $entityManager->getRepository(Ignore::class);

        if($input->getOption("network") !== null) {
            $network = $entityManager->getRepository(Network::class)->find($input->getOption("network"));
            if($network === null) {
                throw new \InvalidArgumentException("Couldn't find that network ID");
            }
            $ignores = $ignoreRepository->findMatching($input->getArgument('host'), $network);
        } else {
            $ignores = $ignoreRepository->findMatching($input->getArgument('host'));
        }
        foreach ($ignores as $ignore) {
            $output->writeln($ignore);
        }
        return Command::SUCCESS;
    }
}
