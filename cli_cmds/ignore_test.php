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

class ignore_test extends Command
{
    public function __construct()
    {
        parent::__construct('ignore:test', $this->handle(...));
        $this->addOption(Option::create('b', 'bot', GetOpt::MULTIPLE_ARGUMENT));
        $this->addOption(Option::create('n', 'network', GetOpt::MULTIPLE_ARGUMENT));
        $this->addOperand(Operand::create('host', Operand::REQUIRED));
    }

    public function handle(GetOpt $getOpt) {
        global $entityManager;
        /**
         * @var \lolbot\entities\IgnoreRepository $repo
         */
        $repo = $entityManager->getRepository(Ignore::class);
        $ignores = $repo->findByHost($getOpt->getOperand('host'));
        foreach ($ignores as $ignore) {
            echo $ignore . "\n";
        }
    }
}
