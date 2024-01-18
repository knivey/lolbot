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
use lolbot\entities\Ignore;

#[AsCommand("ignore:del")]
class ignore_del extends Command
{
    protected function configure(): void
    {
        $this->addArgument("ignore", InputArgument::REQUIRED, "ID of the ignore");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $repo = $entityManager->getRepository(Ignore::class);
        $ignore = $repo->find($input->getArgument('ignore'));
        if($ignore == null) {
            throw new \InvalidArgumentException("Couldn't find and ignore by that ID");
        }
        $entityManager->remove($ignore);
        $entityManager->flush();

        showdb::showdb();

        return Command::SUCCESS;
    }
}
