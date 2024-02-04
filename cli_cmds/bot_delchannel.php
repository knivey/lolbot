<?php
namespace lolbot\cli_cmds;
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;

use lolbot\entities\Channel;
use lolbot\entities\Server;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use lolbot\entities\Bot;

#[AsCommand("bot:delchannel")]
class bot_delchannel extends Command
{
    protected function configure(): void
    {
        $this->addArgument("channel", InputArgument::REQUIRED, "ID of the channel");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $channel = $entityManager->getRepository(Channel::class)->find($input->getArgument("channel"));
        if(!$channel) {
            throw new \InvalidArgumentException("Channel ID not found");
        }

        $entityManager->remove($channel);
        $entityManager->flush();

        showdb::showdb();

        return Command::SUCCESS;
    }
}
