<?php
namespace lolbot\cli_cmds;
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;

use GetOpt\Command;
use GetOpt\GetOpt;
use GetOpt\Operand;
use GetOpt\Option;
use lolbot\entities\Ignore;
use lolbot\entities\Network;

class ignore_test extends Command
{
    public function __construct()
    {
        parent::__construct('ignore:test', $this->handle(...));
        $this->addOption(Option::create('n', 'network_id', GetOpt::REQUIRED_ARGUMENT));
        $this->addOperand(Operand::create('host', Operand::REQUIRED));
    }

    public function handle(GetOpt $getOpt) {
        global $entityManager;
        /**
         * @var \lolbot\entities\IgnoreRepository $ignoreRepository
         */
        $ignoreRepository = $entityManager->getRepository(Ignore::class);

        if($getOpt->getOption("network_id") !== null) {
            $network = $entityManager->getRepository(Network::class)->find($getOpt->getOption("network_id"));
            if($network === null)
                die("coundn't find that network ID\n");
            $ignores = $ignoreRepository->findMatching($getOpt->getOperand('host'), $network);
        } else {
            $ignores = $ignoreRepository->findMatching($getOpt->getOperand('host'));
        }
        foreach ($ignores as $ignore) {
            echo $ignore . "\n";
        }
    }
}
