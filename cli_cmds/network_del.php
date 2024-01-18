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
use lolbot\entities\Network;

class network_del extends Command
{
    public function __construct()
    {
        parent::__construct('network:del', $this->handle(...));
        $this->addOperand(Operand::create('network_id', Operand::REQUIRED)->setValidation(fn ($it) => $it != ''));
    }

    public function handle(GetOpt $getOpt): void {
        global $entityManager;
        $repo = $entityManager->getRepository(Network::class);
        $network = $repo->find($getOpt->getOperand('network_id'));
        if($network == null)
            die("couldn't find that network id\n");

        $entityManager->remove($network);
        $entityManager->flush();

        showdb::showdb();
    }
}
