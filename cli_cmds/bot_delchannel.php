<?php
namespace lolbot\cli_cmds;
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;

use lolbot\entities\Channel;
use lolbot\entities\Server;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use lolbot\entities\Bot;

#[AsCommand("bot:delchannel")]
class bot_delchannel extends Command
{
    protected function configure(): void
    {
        $this->addArgument("channel", InputArgument::REQUIRED, "ID of the channel");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager);
        $idArg = $input->getArgument("channel");
        if (!is_string($idArg)) {
            throw new \LogicException("'channel' argument must be a string");
        }
        $channel = $entityManager->getRepository(\lolbot\entities\Channel::class)->find((int)$idArg);
        if (!$channel) {
            throw new \InvalidArgumentException("Channel ID not found");
        }
        $svc->deleteChannel($channel);
        showdb::showdb();
        return Command::SUCCESS;
    }
}
