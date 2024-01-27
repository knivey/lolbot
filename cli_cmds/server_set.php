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

#[AsCommand("server:set")]
class server_set extends Command
{
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
        $network = $entityManager->getRepository(Network::class)->find($input->getArgument("network"));
        if(!$network) {
            throw new \InvalidArgumentException("Network by that ID not found");
        }

        if($input->getArgument("setting") === null) {
            //show current settings and values;
        }

        if(!in_array($input->getArgument("setting"), $this->settings)) {
            throw new \InvalidArgumentException("No network setting by that name");
        }

        if($input->getArgument("value") === null) {
            //show current setting and value;
        }

        $network->{$input->getArgument("setting")} = $input->getArgument("value");


        $entityManager->persist($network);
        $entityManager->flush();

        showdb::showdb();

        return Command::SUCCESS;
    }
}
