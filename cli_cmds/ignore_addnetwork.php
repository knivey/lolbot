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

use Doctrine\ORM\EntityRepository;
use lolbot\entities\Network;
use lolbot\entities\Ignore;

#[AsCommand("ignore:addnetwork")]
class ignore_addnetwork extends Command
{
    protected function configure(): void
    {
        $this->addOption('network', "N", InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Network ID')
            ->addArgument("ignore", InputArgument::REQUIRED, "ID of the ignore")
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        if(count($input->getOption("network")) == 0) {
            throw new \InvalidArgumentException("Must specify a network");
        }

        $ignore = $entityManager->getRepository(Ignore::class)->find($input->getArgument("ignore"));
        if($ignore === null) {
            throw new \InvalidArgumentException("Couldn't find that ignore ID");
        }

        /** @var EntityRepository<Network> $repo */
        $repo = $entityManager->getRepository(Network::class);
        foreach($input->getOption("network") as $net) {
            $network = $repo->find($net);
            if ($network === null) {
                throw new \InvalidArgumentException("Couldn't find that network ID ($net)");
            }
            $ignore->addToNetwork($network);
        }

        $entityManager->persist($ignore);
        $entityManager->flush();

        showdb::showdb();

        return Command::SUCCESS;
    }
}
