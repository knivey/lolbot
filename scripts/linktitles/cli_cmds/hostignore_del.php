<?php
namespace scripts\linktitles\cli_cmds;
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;

use scripts\linktitles\entities\hostignore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Doctrine\ORM\EntityRepository;

#[AsCommand("linktitles:hostignore:del")]
class hostignore_del extends Command
{
    protected function configure(): void
    {
        $this->addArgument("id", InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        global $entityManager;
        $ignore = $entityManager->getRepository(hostignore::class)->find($input->getArgument("id"));
        if($ignore == null) {
            throw new \InvalidArgumentException("Couldn't find a hostignore by that ID");
        }

        $entityManager->remove($ignore);
        $entityManager->flush();

        $output->writeln("Host Ignore removed");

        return Command::SUCCESS;
    }
}