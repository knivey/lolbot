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

#[AsCommand("bot:list")]
class bot_list extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $repo = $entityManager->getRepository(Bot::class);
        $bots = $repo->findAll();
        foreach ($bots as $bot) {
            $output->writeln($bot);
        }
        return  Command::SUCCESS;
    }
}
