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
use lolbot\entities\Server;

#[AsCommand("server:del")]
class server_del extends Command
{
    protected function configure(): void
    {
        $this->addArgument("server", InputArgument::REQUIRED, "ID of the server to delete");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager, \lolbot\config\build_change_notifier());
        $serverId = $input->getArgument('server');
        if (!is_string($serverId)) {
            throw new \LogicException("'server' argument must be a string");
        }
        $server = $svc->getServer((int)$serverId);
        if ($server === null) {
            throw new \InvalidArgumentException("Couldn't find a server with that ID");
        }
        $svc->deleteServer($server);
        showdb::showdb();
        return Command::SUCCESS;
    }
}
