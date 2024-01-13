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

class network_add extends Command
{
    public function __construct()
    {
        parent::__construct('network:add', $this->handle(...));
        $this->addOperand(Operand::create('name', Operand::REQUIRED)->setValidation(fn ($it) => $it != ''));
    }

    public function handle(GetOpt $getOpt): void {
        global $entityManager;
        $network = $entityManager->getRepository(Network::class)->findOneBy(["name" => $getOpt->getOperand("name")]);
        if($network !== null)
            die("Network already exists with that name\n");

        $network = new Network();
        $network->setName($getOpt->getOperand("name"));
        $entityManager->persist($network);
        $entityManager->flush();

        showdb::showdb();
    }
}
