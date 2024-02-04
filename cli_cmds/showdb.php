<?php


namespace lolbot\cli_cmds;
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use lolbot\entities\Bot;
use lolbot\entities\Ignore;
use lolbot\entities\Network;
use function scripts\codesand\runTcc;

#[AsCommand("showdb")]
class showdb extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int {
        self::showdb();
        return Command::SUCCESS;
    }

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
            echo "    servers:\n";
            foreach ($network->getServers() as $server) {
                echo "      " . $server . "\n";
            }
        }

        echo "\nbots:\n";
        $bots = $entityManager->getRepository(Bot::class)->findAll();
        foreach ($bots as $bot) {
            echo "  " . $bot . "\n";
            echo "    network: " . $bot->network->name . "\n";
            echo "    channels: " . implode(", ", $bot->getChannels()->toArray()) . "\n";
        }

        echo "\nignores:\n";
        $ignores = $entityManager->getRepository(Ignore::class)->findAll();
        foreach ($ignores as $ignore) {
            echo "  " . $ignore . "\n";
            echo "    networks: ";
            foreach ($ignore->getNetworks() as $network)
                echo $network->id . ", ";
            echo "\n";
        }
    }
}
