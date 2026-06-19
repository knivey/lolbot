<?php
namespace lolbot\cli_cmds;
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;

use lolbot\config\ServiceLocator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand("service:list")]
class service_list extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $locator = new ServiceLocator($entityManager);
        foreach ($locator->serviceTypes() as $type) {
            $cfg = $locator->getServiceConfig($type);
            $state = $cfg === null ? '<comment>(unset)</comment>' : '<info>(set)</info>';
            $output->writeln("$type $state");
        }
        return Command::SUCCESS;
    }
}
