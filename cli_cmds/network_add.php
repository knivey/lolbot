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

#[AsCommand("network:add")]
class network_add extends Command
{
    protected function configure(): void
    {
        $this->addArgument("name", InputArgument::REQUIRED, "Name for the network");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $network = $entityManager->getRepository(Network::class)->findOneBy(["name" => $input->getArgument("name")]);
        if($network !== null) {
            throw new \InvalidArgumentException("Network already exists with that name");
        }

        $network = new Network();
        $network->name = $input->getArgument("name");
        $entityManager->persist($network);
        $entityManager->flush();

        showdb::showdb();

        return Command::SUCCESS;
    }
}
