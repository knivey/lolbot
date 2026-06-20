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

#[AsCommand("server:set")]
class server_set extends Command
{
    /** @var array<string> */
    public array $settings = [
        "address",
        "port",
        "ssl",
        "throttle",
        "password"
    ];
    protected function configure(): void
    {
        $this->addArgument("server", InputArgument::REQUIRED, "Server ID");
        $this->addArgument("setting", InputArgument::OPTIONAL, "setting name");
        $this->addArgument("value", InputArgument::OPTIONAL, "New value");
    }

    //probably could make a generic base command class for settings

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager, \lolbot\config\build_change_notifier());
        $serverId = $input->getArgument("server");
        if (!is_string($serverId)) {
            throw new \LogicException("'server' argument must be a string");
        }
        $server = $svc->getServer((int)$serverId);
        if (!$server) {
            throw new \InvalidArgumentException("Server by that ID not found");
        }

        if ($input->getArgument("setting") === null) {
            $output->writeln($server);
            return Command::SUCCESS;
        }

        $setting = $input->getArgument("setting");
        if (!is_string($setting) || !in_array($setting, $this->settings, true)) {
            throw new \InvalidArgumentException("No setting by that name");
        }

        if ($input->getArgument("value") === null) {
            $output->writeln($server);
            return Command::SUCCESS;
        }

        $raw = $input->getArgument("value");
        $value = match ($setting) {
            'port' => is_string($raw) ? (int)$raw : 0,
            'ssl', 'throttle' => is_string($raw)
                ? (filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                    ?? throw new \InvalidArgumentException("Value must be true or false"))
                : false,
            default => is_string($raw) ? $raw : '',
        };
        $server->$setting = $value;
        $svc->update($server, "server");
        showdb::showdb();

        return Command::SUCCESS;
    }
}
