<?php
namespace lolbot\cli_cmds;
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand("apikey:del")]
class apikey_del extends Command
{
    protected function configure(): void
    {
        $this->addArgument("id", InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager, \lolbot\config\build_change_notifier());

        $id = $input->getArgument('id');
        if (!is_string($id)) {
            throw new \LogicException("'id' argument must be a string");
        }
        $apiKey = $svc->getApiKey((int)$id);
        if ($apiKey === null) {
            throw new \InvalidArgumentException("Couldn't find that API key ID ($id)");
        }
        $svc->deleteApiKey($apiKey);

        showdb::showdb();
        return Command::SUCCESS;
    }
}
