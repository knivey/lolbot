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

#[AsCommand("ignore:list")]
class ignore_list extends Command
{

    protected function configure(): void
    {
        $this->addOption('network', "N", InputOption::VALUE_REQUIRED, 'Network ID')
            ->addOption('orphaned', "o", InputOption::VALUE_NONE, 'Only display orphans')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        if(null !== $id = $input->getOption("network")) {
            if (null === $network = $entityManager->getRepository(Network::class)->find($id)) {
                throw new \InvalidArgumentException("Network by that ID not found");
            }
            $this->print_ignores($network->getIgnores(), $output);
            return Command::SUCCESS;
        }
        $repo = $entityManager->getRepository(Ignore::class);
        $ignores = $repo->findAll();
        if($input->getOption("orphaned") !== null){
            $ignores = array_filter($ignores, fn ($i) => count($i->getNetworks()) == 0);
        }
        $this->print_ignores($ignores, $output);
        return Command::SUCCESS;
    }

    function print_ignores($ignores, OutputInterface $output) {
        foreach ($ignores as $ignore) {
            $output->writeln($ignore);
        }
    }
}
