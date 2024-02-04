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

#[AsCommand("network:del")]
class network_del extends Command
{
    protected function configure(): void
    {
        $this->addArgument("network", InputArgument::REQUIRED, "ID of the network");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $repo = $entityManager->getRepository(Network::class);
        $network = $repo->find($input->getArgument('network'));
        if($network == null) {
            throw new \InvalidArgumentException("Couldn't find that network ID");
        }

        $entityManager->remove($network);
        $entityManager->flush();

        showdb::showdb();

        return Command::SUCCESS;
    }
}
