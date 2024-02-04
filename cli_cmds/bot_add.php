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
        if($input->getOption("network") === null) {
            throw new \InvalidArgumentException("Must specify a network");
        }

        $network = $entityManager->getRepository(Network::class)->find($input->getOption("network"));
        if($network === null) {
            throw new \InvalidArgumentException("Couldn't find that network ID");
        }

        $bot = new Bot();
        $bot->name = $input->getArgument("name");
        $bot->network = $network;
        $entityManager->persist($bot);
        $entityManager->flush();

        showdb::showdb();

        return Command::SUCCESS;
    }
}
