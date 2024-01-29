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

#[AsCommand("bot:addchannel")]
class bot_addchannel extends Command
{
    protected function configure(): void
    {
        $this->addArgument("bot", InputArgument::REQUIRED, "ID of the bot");
        $this->addArgument("channel", InputArgument::REQUIRED);
        //$this->addOption("key", "k", InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $bot = $entityManager->getRepository(Bot::class)->find($input->getArgument("bot"));
        if(!$bot) {
            throw new \InvalidArgumentException("Bot ID not found");
        }

        $channel = new Channel();
        $channel->name = $input->getArgument("channel");
        $bot->addChannel($channel);

        $entityManager->persist($channel);
        $entityManager->flush();

        showdb::showdb();

        return Command::SUCCESS;
    }
}
