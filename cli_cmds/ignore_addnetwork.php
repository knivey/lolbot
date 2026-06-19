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
        $svc = new \lolbot\config\ConfigService($entityManager);
        $nets = $input->getOption("network");
        if (!is_array($nets) || count($nets) == 0) {
            throw new \InvalidArgumentException("Must specify a network");
        }

        $ignoreId = $input->getArgument("ignore");
        $ignore = $svc->getIgnore(is_string($ignoreId) ? (int)$ignoreId : 0);
        if ($ignore === null) {
            throw new \InvalidArgumentException("Couldn't find that ignore ID");
        }

        $networks = [];
        foreach ($nets as $netId) {
            if (!is_string($netId)) {
                throw new \LogicException("'network' option values must be strings");
            }
            $network = $svc->getNetwork((int)$netId);
            if ($network === null) {
                throw new \InvalidArgumentException("Couldn't find that network ID ($netId)");
            }
            $networks[] = $network;
        }
        $svc->addIgnoreNetworks($ignore, $networks);

        showdb::showdb();
        return Command::SUCCESS;
    }
}
