<?php
namespace lolbot\cli_cmds;
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;

use Doctrine\Common\Collections\Criteria;
use GetOpt\Command;
use GetOpt\GetOpt;
use GetOpt\Operand;
use GetOpt\Option;
use lolbot\entities\Ignore;
use lolbot\entities\Network;

class ignore_list extends Command
{
    public function __construct()
    {
        parent::__construct('ignore:list', $this->handle(...));
        $this->addOption(Option::create('o', 'orphaned', GetOpt::NO_ARGUMENT));
        $this->addOption(Option::create('n', 'network_id', GetOpt::REQUIRED_ARGUMENT));
    }

    public function handle(GetOpt $getOpt) {
        global $entityManager;
        if(null !== $id = $getOpt->getOption("network_id")) {
            if (null === $network = $entityManager->getRepository(Network::class)->find($id))
                die("network by that id not found\n");
            $this->print_ignores($network->getIgnores());
            return;
        }
        $repo = $entityManager->getRepository(Ignore::class);
        $ignores = $repo->findAll();
        if($getOpt->getOption("orphaned") !== null){
            $ignores = array_filter($ignores, fn ($i) => count($i->getNetworks()) == 0 );
        }
        $this->print_ignores($ignores);
    }

    function print_ignores($ignores) {
        foreach ($ignores as $ignore) {
            echo $ignore . "\n";
        }
    }
}
