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
use lolbot\entities\Ignore;
use lolbot\entities\Network;

class showdb
{
    static public function showdb()
    {
        global $entityManager;
        echo "networks:\n";
        $networks = $entityManager->getRepository(Network::class)->findAll();
        foreach ($networks as $network) {
            echo "  " . $network . "\n";
            echo "    bots:\n";
            foreach ($network->getBots() as $bot) {
                echo "      " . $bot . "\n";
            }
            echo "    ignores:\n";
            foreach ($network->getIgnores() as $ignore) {
                echo "      " . $ignore . "\n";
            }
        }

        echo "\nbots:\n";
        $bots = $entityManager->getRepository(Bot::class)->findAll();
        foreach ($bots as $bot) {
            echo "  " . $bot . "\n";
            echo "    network: " . $bot->getNetwork()->getName() . "\n";
        }

        echo "\nignores:\n";
        $ignores = $entityManager->getRepository(Ignore::class)->findAll();
        foreach ($ignores as $ignore) {
            echo "  " . $ignore . "\n";
            echo "    networks: ";
            foreach ($ignore->getNetworks() as $network)
                echo $network->getId() . ", ";
            echo "\n";
        }
    }
}
