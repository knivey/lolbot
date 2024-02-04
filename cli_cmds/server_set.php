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
        $server = $entityManager->getRepository(Server::class)->find($input->getArgument("server"));
        if(!$server) {
            throw new \InvalidArgumentException("Server by that ID not found");
        }

        if($input->getArgument("setting") === null) {
            $output->writeln($server);
            return Command::SUCCESS;
        }

        if(!in_array($input->getArgument("setting"), $this->settings)) {
            throw new \InvalidArgumentException("No setting by that name");
        }

        if($input->getArgument("value") === null) {
            $output->writeln($server);
            return Command::SUCCESS;
        }

        $server->{$input->getArgument("setting")} = $input->getArgument("value");


        $entityManager->persist($server);
        $entityManager->flush();

        showdb::showdb();

        return Command::SUCCESS;
    }
}
