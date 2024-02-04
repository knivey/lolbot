<?php
namespace scripts\linktitles\cli_cmds;
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;

use Doctrine\Common\Collections\Criteria;
use lolbot\entities\Bot;
use lolbot\entities\Network;
use scripts\linktitles\entities\hostignore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Doctrine\ORM\EntityRepository;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand("linktitles:hostignore:test")]
class hostignore_test extends Command
{
    protected function configure(): void
    {
        $this->addArgument("host", InputArgument::REQUIRED, "The host to test for ignores");
        $this->addOption("network", "N", InputOption::VALUE_REQUIRED, "Filter by network ID");
        $this->addOption("bot", "B", InputOption::VALUE_REQUIRED, "Filter by bot ID");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        global $entityManager;
        $ignores = $entityManager->getRepository(hostignore::class);
        $criteria = Criteria::create();
        if($input->getOption("network") !== null) {
            $network = $entityManager->getRepository(Network::class)->find($input->getOption("network"));
            if($network === null) {
                throw new \InvalidArgumentException("Couldn't find that network ID");
            }
            $criteria->andWhere(Criteria::expr()->eq("network", $network));
        }
        if($input->getOption("bot") !== null) {
            $bot = $entityManager->getRepository(Bot::class)->find($input->getOption("bot"));
            if($bot === null) {
                throw new \InvalidArgumentException("Couldn't find that network ID");
            }
            $criteria->andWhere(Criteria::expr()->eq("bot", $bot));
        }

        $ignores = $ignores->matching($criteria)->toArray();

        $ignores = array_filter($ignores, function($ignore) use ($input) {
            if (preg_match(\knivey\tools\globToRegex($ignore->hostmask) . 'i', $input->getArgument("host"))) {
                return true;
            }
            return false;
        });

        $io = new SymfonyStyle($input, $output);
        $table_head = ["id","hostmask","type","network","bot","chan","created"];
        $table = [];
        foreach($ignores as $ignore) {
            $table[] = [$ignore->id, $ignore->hostmask, $ignore->type->toString(), $ignore->network?->id, $ignore->bot?->id, "", $ignore->created->format('r')];
        }
        $io->table($table_head, $table);

        return Command::SUCCESS;
    }
}





























