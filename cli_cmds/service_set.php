<?php
namespace lolbot\cli_cmds;
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;

use lolbot\config\ConfigService;
use lolbot\config\ServiceLocator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand("service:set")]
class service_set extends Command
{
    protected function configure(): void
    {
        $this->addArgument("type", InputArgument::REQUIRED, "Service type (e.g. ai, paste)");
        $this->addArgument("key", InputArgument::REQUIRED, "Setting key");
        $this->addArgument("value", InputArgument::REQUIRED, "New value");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $type = $input->getArgument("type");
        $key = $input->getArgument("key");
        $raw = $input->getArgument("value");
        if (!is_string($type) || !is_string($key) || !is_string($raw)) {
            throw new \LogicException("'type', 'key' and 'value' arguments must be strings");
        }

        $locator = new ServiceLocator($entityManager);
        $class = $locator->entityClassFor($type);
        if ($class === null) {
            throw new \InvalidArgumentException("Unknown service type: $type");
        }
        if (!property_exists($class, $key)) {
            throw new \InvalidArgumentException("No key '$key' on service '$type'");
        }

        // Coerce ints/bools/json by reflection of the entity property type.
        $value = self::coerce(new \ReflectionProperty($class, $key), $raw);

        $svc = new ConfigService($entityManager, \lolbot\config\build_change_notifier());
        $svc->setServiceConfigValue($type, $key, $value);

        if (is_array($value)) {
            $encoded = json_encode($value);
            $shown = $encoded === false ? '[]' : $encoded;
        } else {
            $shown = self::scalarToString($value);
        }
        $output->writeln("<info>Set $type.$key = $shown</info>");
        return Command::SUCCESS;
    }

    private static function coerce(\ReflectionProperty $prop, string $raw): mixed
    {
        $type = $prop->getType();
        if ($type instanceof \ReflectionNamedType) {
            return match ($type->getName()) {
                'int' => (int)$raw,
                'bool' => self::parseBool($raw),
                'array' => self::parseArray($raw),
                default => $raw,
            };
        }
        return $raw;
    }

    private static function parseBool(string $raw): bool
    {
        $result = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($result === null) {
            throw new \InvalidArgumentException("Value must be true or false");
        }
        return $result;
    }

    /**
     * @return array<string|int, mixed>
     */
    private static function parseArray(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException("Value must be valid JSON for an array key");
        }
        return $decoded;
    }

    private static function scalarToString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_scalar($value)) {
            return (string)$value;
        }
        return '<non-scalar>';
    }
}
