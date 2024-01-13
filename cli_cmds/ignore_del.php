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

class ignore_del extends Command
{
    public function __construct()
    {
        parent::__construct('ignore:del', $this->handle(...));
        //$this->addOption(Option::create('b', 'bot', GetOpt::MULTIPLE_ARGUMENT));
        //$this->addOption(Option::create('n', 'network', GetOpt::MULTIPLE_ARGUMENT));
        $this->addOperand(Operand::create('id', Operand::REQUIRED)->setValidation(is_numeric(...)));
    }

    public function handle(GetOpt $getOpt) {
        global $entityManager;
        $repo = $entityManager->getRepository(Ignore::class);
        $ignore = $repo->find($getOpt->getOperand('id'));
        if($ignore == null)
            die("couldn't find that id\n");
        $entityManager->remove($ignore);
        $entityManager->flush();
    }
}
