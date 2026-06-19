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
        $svc = new \lolbot\config\ConfigService($entityManager);
        $name = $input->getArgument("name");
        if (!is_string($name)) {
            throw new \LogicException("'name' argument must be a string");
        }
        $svc->createNetwork($name);
        showdb::showdb();
        return Command::SUCCESS;
    }
}
