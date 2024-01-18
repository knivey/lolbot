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
use lolbot\entities\Bot;
use lolbot\entities\Network;

class bot_add extends Command
{
    public function __construct()
    {
        parent::__construct('bot:add', $this->handle(...));
        $this->addOperand(Operand::create('name', Operand::REQUIRED)->setValidation(fn ($it) => $it != ''));
        $this->addOption(Option::create('n', 'network_id', GetOpt::REQUIRED_ARGUMENT));
    }

    public function handle(GetOpt $getOpt): void {
        global $entityManager;
        if($getOpt->getOption("network_id") === null)
            die("must specify a network\n");

        $network = $entityManager->getRepository(Network::class)->find($getOpt->getOption("network_id"));
        if($network === null)
            die("couldn't find that network\n");

        $bot = new Bot();
        $bot->setName($getOpt->getOperand("name"));
        $bot->setNetwork($network);
        $entityManager->persist($bot);
        $entityManager->flush();

        showdb::showdb();
    }
}
