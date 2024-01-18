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

#[AsCommand("ignore:add")]
class ignore_add extends Command
{

    protected function configure(): void
    {
        $this->addOption('network', "N", InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Network ID')
            ->addArgument("hostmask", InputArgument::REQUIRED)
            ->addArgument("reason", InputArgument::OPTIONAL)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;

        if(count($input->getOption("network")) == 0) {
            throw new \InvalidArgumentException("Must specify a network");
        }

        $ignore = new Ignore();
        $ignore->setHostmask($input->getArgument('hostmask'));
        if($input->getArgument('reason') !== null)
            $ignore->setReason($input->getArgument('reason'));

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
