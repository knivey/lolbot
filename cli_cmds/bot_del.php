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

class bot_del extends Command
{
    public function __construct()
    {
        parent::__construct('bot:del', $this->handle(...));
        $this->addOperand(Operand::create('bot_id', Operand::REQUIRED)->setValidation(fn ($it) => $it != ''));
    }

    public function handle(GetOpt $getOpt): void {
        global $entityManager;
        $repo = $entityManager->getRepository(Bot::class);
        $bot = $repo->find($getOpt->getOperand('bot_id'));
        if($bot == null)
            die("couldn't find that bot id\n");

        $entityManager->remove($bot);
        $entityManager->flush();

        showdb::showdb();
    }
}
