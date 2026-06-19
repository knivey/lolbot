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

use lolbot\entities\Bot;
use lolbot\entities\Network;

#[AsCommand("bot:add")]
class bot_add extends Command
{

    protected function configure(): void
    {
        $this->addOption('network', "N", InputOption::VALUE_REQUIRED, 'Network ID')
            ->addArgument("name", InputArgument::REQUIRED)
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager);
        $netOpt = $input->getOption("network");
        if (!is_string($netOpt)) {
            throw new \InvalidArgumentException("Must specify a network");
        }
        $network = $svc->getNetwork((int)$netOpt);
        if ($network === null) {
            throw new \InvalidArgumentException("Couldn't find that network ID");
        }
        $name = $input->getArgument("name");
        if (!is_string($name)) {
            throw new \LogicException("'name' argument must be a string");
        }
        $svc->createBot($network, $name);
        showdb::showdb();
        return Command::SUCCESS;
    }
}
