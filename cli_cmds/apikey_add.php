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

use lolbot\entities\ApiKey;

#[AsCommand("apikey:add")]
class apikey_add extends Command
{

    protected function configure(): void
    {
        $this->addArgument("key", InputArgument::REQUIRED)
            ->addOption('label', "l", InputOption::VALUE_REQUIRED, 'Label for this key')
            ->addOption('scope', "s", InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Scope to grant (' . implode('|', ApiKey::SCOPES) . ')')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager, \lolbot\config\build_change_notifier());

        $key = $input->getArgument('key');
        if (!is_string($key)) {
            throw new \LogicException("'key' argument must be a string");
        }
        $label = $input->getOption('label');
        $scopes = $input->getOption('scope');
        if (!is_array($scopes) || count($scopes) == 0) {
            throw new \InvalidArgumentException("Must specify at least one --scope (" . implode('|', ApiKey::SCOPES) . ")");
        }
        $scopeList = [];
        foreach ($scopes as $scope) {
            if (!is_string($scope)) {
                throw new \LogicException("'scope' option values must be strings");
            }
            $scopeList[] = $scope;
        }
        $svc->addApiKey($key, is_string($label) ? $label : null, $scopeList);

        showdb::showdb();
        return Command::SUCCESS;
    }
}
