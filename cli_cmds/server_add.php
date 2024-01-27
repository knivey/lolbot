<?php
namespace lolbot\cli_cmds;
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;

use lolbot\entities\Server;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use lolbot\entities\Network;

#[AsCommand("server:add")]
class server_add extends Command
{
    protected function configure(): void
    {
        $this->addArgument("network", InputArgument::REQUIRED, "ID of the network");
        $this->addArgument("address", InputArgument::REQUIRED, "Address of the server, excluding port");
        $this->addOption("port", "p", InputOption::VALUE_REQUIRED);
        $this->addOption("ssl", "s", InputOption::VALUE_NONE);
        $this->addOption("no-throttle", "", InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $network = $entityManager->getRepository(Network::class)->find($input->getArgument("network"));
        if(!$network) {
            throw new \InvalidArgumentException("Network ID not found");
        }

        $server = new Server();
        $server->setNetwork($network);

        $entityManager->persist($server);
        $entityManager->flush();

        showdb::showdb();

        return Command::SUCCESS;
    }
}
