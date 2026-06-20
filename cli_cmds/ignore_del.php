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

#[AsCommand("ignore:del")]
class ignore_del extends Command
{
    protected function configure(): void
    {
        $this->addArgument("ignore", InputArgument::REQUIRED, "ID of the ignore");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager, \lolbot\config\build_change_notifier());
        $ignoreId = $input->getArgument('ignore');
        $ignore = $svc->getIgnore(is_string($ignoreId) ? (int)$ignoreId : 0);
        if ($ignore === null) {
            throw new \InvalidArgumentException("Couldn't find an ignore by that ID");
        }
        $svc->deleteIgnore($ignore);
        showdb::showdb();
        return Command::SUCCESS;
    }
}
