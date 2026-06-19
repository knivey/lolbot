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

#[AsCommand("network:set")]
class network_set extends Command
{
    /** @var array<string> */
    public array $settings = [
        "name"
        ];
    protected function configure(): void
    {
        $this->addArgument("network", InputArgument::REQUIRED, "Network ID");
        $this->addArgument("setting", InputArgument::OPTIONAL, "setting name");
        $this->addArgument("value", InputArgument::OPTIONAL, "New value");
    }

    //probably could make a generic base command class for settings

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager);
        $idArg = $input->getArgument("network");
        if (!is_string($idArg)) {
            throw new \LogicException("'network' argument must be a string");
        }
        $network = $svc->getNetwork((int)$idArg);
        if (!$network) {
            throw new \InvalidArgumentException("Network by that ID not found");
        }

        if ($input->getArgument("setting") === null) {
            $output->writeln($network);
            return Command::SUCCESS;
        }

        if (!in_array($input->getArgument("setting"), $this->settings, true)) {
            throw new \InvalidArgumentException("No network setting by that name");
        }

        if ($input->getArgument("value") === null) {
            $output->writeln($network);
            return Command::SUCCESS;
        }

        $setting = $input->getArgument("setting");
        $network->$setting = $input->getArgument("value");
        $svc->update($network, "network");
        showdb::showdb();

        return Command::SUCCESS;
    }
}
