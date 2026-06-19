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
        $svc = new \lolbot\config\ConfigService($entityManager);
        $idArg = $input->getArgument('network');
        if (!is_string($idArg)) {
            throw new \LogicException("'network' argument must be a string");
        }
        $network = $svc->getNetwork((int)$idArg);
        if ($network === null) {
            throw new \InvalidArgumentException("Couldn't find that network ID");
        }
        $svc->deleteNetwork($network);
        showdb::showdb();
        return Command::SUCCESS;
    }
}
