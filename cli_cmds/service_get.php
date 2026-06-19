<?php
namespace lolbot\cli_cmds;
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;

use lolbot\config\ServiceLocator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand("service:get")]
class service_get extends Command
{
    protected function configure(): void
    {
        $this->addArgument("type", InputArgument::REQUIRED, "Service type (e.g. ai, paste)");
        $this->addArgument("key", InputArgument::OPTIONAL, "Specific key; omit to show all");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $type = $input->getArgument("type");
        if (!is_string($type)) {
            throw new \LogicException("'type' argument must be a string");
        }
        $locator = new ServiceLocator($entityManager);
        $cfg = $locator->getServiceConfig($type);
        if ($cfg === null) {
            $output->writeln("<comment>No '$type' service config is set.</comment>");
            return Command::SUCCESS;
        }
        $key = $input->getArgument("key");
        if ($key !== null) {
            if (!is_string($key)) {
                throw new \LogicException("'key' argument must be a string");
            }
            if (!property_exists($cfg, $key)) {
                throw new \InvalidArgumentException("No key '$key' on service '$type'");
            }
            $output->writeln("$key=" . self::display($cfg->$key));
            return Command::SUCCESS;
        }
        if ($cfg instanceof \Stringable) {
            $output->writeln($cfg->__toString());
        }
        return Command::SUCCESS;
    }

    private static function display(mixed $v): string
    {
        if (is_array($v)) {
            $json = json_encode($v, JSON_UNESCAPED_SLASHES);
            return $json === false ? '[]' : $json;
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if ($v === null) {
            return 'null';
        }
        if (is_scalar($v)) {
            return (string)$v;
        }
        return '<non-scalar>';
    }
}
