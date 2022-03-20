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

class ignore_add extends Command
{
    public function __construct()
    {
        parent::__construct('ignore:add', $this->handle(...));
        $this->addOption(Option::create('b', 'bot', GetOpt::MULTIPLE_ARGUMENT));
        $this->addOption(Option::create('n', 'network', GetOpt::MULTIPLE_ARGUMENT));
        $this->addOperand(Operand::create('hostmask', Operand::REQUIRED)->setValidation(fn ($it) => $it != ''));
        $this->addOperand(Operand::create('reason', Operand::OPTIONAL));
    }

    public function handle(GetOpt $getOpt): void {
        global $entityManager;
        $ignore = new Ignore();
        $ignore->setHostmask($getOpt->getOperand('hostmask'));
        if($getOpt->getOperand('reason') !== null)
        $ignore->setReason($getOpt->getOperand('reason'));
        $entityManager->persist($ignore);
        $entityManager->flush();
    }
}
