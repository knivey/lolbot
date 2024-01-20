<?php
namespace scripts\linktitles\cli_cmds;
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;

use lolbot\entities\Bot;
use lolbot\entities\Network;
use scripts\linktitles\entities\hostignore;
use scripts\linktitles\entities\ignore_type;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Doctrine\ORM\EntityRepository;

#[AsCommand("linktitles:hostignore:add")]
class hostignore_add extends Command
{
    protected function configure(): void
    {
        $this->addArgument("type", InputArgument::REQUIRED);
        $this->addArgument("hostmask", InputArgument::REQUIRED);
        $this->addOption("network", "N", InputOption::VALUE_REQUIRED, "Network ID");
        $this->addOption("bot", "B", InputOption::VALUE_REQUIRED, "Bot ID");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        try {
            $type = ignore_type::fromString($input->getArgument("type"));
        } catch (\UnhandledMatchError) {
            throw new \InvalidArgumentException("Type must be one of: global, network, bot, channel");
        }

        $ignore = new hostignore($type);
        $ignore->hostmask = $input->getArgument("hostmask");

        switch($type) {
            case ignore_type::global:
                break;
            case ignore_type::network:
                $network = $entityManager->getRepository(Network::class)->find($input->getOption("network"));
                if($network == null) {
                    throw new \InvalidArgumentException("Couldn't find that network ID");
                }
                $ignore->network = $network;
                break;
            case ignore_type::bot:
                $bot = $entityManager->getRepository(Bot::class)->find($input->getOption("bot"));
                if($bot == null) {
                    throw new \InvalidArgumentException("Couldn't find that network ID");
                }
                $ignore->bot = $bot;
                break;
            case ignore_type::channel:
                throw new \Exception('Channel type not implemented yet');
        }

        $entityManager->persist($ignore);
        $entityManager->flush();

        $output->writeln("Host Ignore added");

        return Command::SUCCESS;
    }
}
