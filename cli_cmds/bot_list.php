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

class bot_list extends Command
{
    public function __construct()
    {
        parent::__construct('bot:list', $this->handle(...));
    }

    public function handle(GetOpt $getOpt) {
        global $entityManager;
        $repo = $entityManager->getRepository(Bot::class);
        $bots = $repo->findAll();
        foreach ($bots as $bot) {
            echo $bot . "\n";
        }
    }
}
