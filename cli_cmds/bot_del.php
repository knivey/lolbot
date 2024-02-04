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

#[AsCommand("bot:del")]
class bot_del extends Command
{
    protected function configure(): void
    {
        $this->addArgument("bot", InputArgument::REQUIRED, "ID of the bot to delete");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $repo = $entityManager->getRepository(Bot::class);
        $bot = $repo->find($input->getArgument('bot'));
        if($bot == null) {
            throw new \InvalidArgumentException("Couldn't find a bot with that ID");
        }

        $entityManager->remove($bot);
        $entityManager->flush();

        showdb::showdb();

        return Command::SUCCESS;
    }
}
