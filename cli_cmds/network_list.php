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

class network_list extends Command
{
    public function __construct()
    {
        parent::__construct('network:list', $this->handle(...));
    }

    public function handle(GetOpt $getOpt) {
        global $entityManager;
        $repo = $entityManager->getRepository(Network::class);
        $networks = $repo->findAll();
        foreach ($networks as $network) {
            echo $network . "\n";
        }
    }
}
