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
        $this->addArgument("address", InputArgument::REQUIRED, "Address of the server, excluding port (Ex: irc.gamesurge.net)");
        $this->addOption("port", "p", InputOption::VALUE_REQUIRED);
        $this->addOption("ssl", "s", InputOption::VALUE_NONE);
        $this->addOption("no-throttle", "", InputOption::VALUE_NONE, "Should the messages to the server not be rate limited");
        $this->addOption("password", "", InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager);
        $networkId = $input->getArgument("network");
        if (!is_string($networkId)) {
            throw new \LogicException("'network' argument must be a string");
        }
        $network = $svc->getNetwork((int)$networkId);
        if (!$network) {
            throw new \InvalidArgumentException("Network ID not found");
        }

        $address = $input->getArgument("address");
        if (!is_string($address)) {
            throw new \LogicException("'address' argument must be a string");
        }
        $portOpt = $input->getOption("port");
        $port = is_string($portOpt) ? (int)$portOpt : null;

        $passwordOpt = $input->getOption("password");

        $svc->addServer(
            $network,
            $address,
            $port,
            (bool)$input->getOption("ssl"),
            !(bool)$input->getOption("no-throttle"),
            is_string($passwordOpt) ? $passwordOpt : null,
        );

        showdb::showdb();
        return Command::SUCCESS;
    }
}
