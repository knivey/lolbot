<?php
namespace lolbot\cli_cmds;
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager, $config;

use lolbot\config\ConfigService;
use lolbot\config\ServiceLocator;
use lolbot\entities\AiServiceConfig;
use lolbot\entities\Bot;
use lolbot\entities\PasteServiceConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand("config:import")]
class config_import extends Command
{
    protected function configure(): void
    {
        $this->setDescription("Import legacy config.yaml values (ai_vision_*, paste_*, per-bot linktitles/url_log_chan) into the database.");
        $this->addOption("force", "f", InputOption::VALUE_NONE, "Overwrite existing DB rows");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        /**
         * @psalm-suppress InvalidGlobal
         */
        global $entityManager, $config;
        if (!is_array($config)) {
            throw new \LogicException('config.yaml did not parse to an array');
        }
        /** @var array<string, mixed> $config */
        $force = (bool)$input->getOption("force");
        $svc = new ConfigService($entityManager);
        $locator = new ServiceLocator($entityManager);

        $imported = 0;

        // --- AI service ---
        $aiMap = [
            'apiKey'  => ['ai_vision_key',           null],
            'baseUrl' => ['ai_vision_base_url',      null],
            'maxDim'  => ['ai_vision_max_dim',       1024],
            'jpgQuality' => ['ai_vision_jpg_quality', 85],
            'timeout' => ['ai_vision_timeout',       10],
            'reasoningEffort' => ['ai_vision_reasoning_effort', null],
            'reasoning' => ['ai_vision_reasoning',   null],
        ];
        foreach ($aiMap as $prop => [$yamlKey, $default]) {
            if (!array_key_exists($yamlKey, $config)) {
                continue;
            }
            $val = $config[$yamlKey];
            if (!$force && $default !== null && $val === $default) {
                continue; // skip default-valued
            }
            $existing = $locator->getServiceConfig('ai');
            if (!$force && $existing instanceof AiServiceConfig) {
                $current = self::aiPropertyValue($existing, $prop);
                if ($current !== null && !self::isAiDefault($prop, $current)) {
                    $output->writeln("<comment>skip ai.$prop (already set, use --force)</comment>");
                    continue;
                }
            }
            $svc->setServiceConfigValue('ai', $prop, $val);
            $output->writeln("imported ai.$prop");
            $imported++;
        }

        // --- Paste service ---
        foreach (['host' => 'paste_host', 'key' => 'paste_key'] as $prop => $yamlKey) {
            if (!array_key_exists($yamlKey, $config)) {
                continue;
            }
            $existing = $locator->getServiceConfig('paste');
            if (!$force && $existing instanceof PasteServiceConfig && self::pastePropertyValue($existing, $prop) !== null) {
                $output->writeln("<comment>skip paste.$prop (already set, use --force)</comment>");
                continue;
            }
            $svc->setServiceConfigValue('paste', $prop, $config[$yamlKey]);
            $output->writeln("imported paste.$prop");
            $imported++;
        }

        // --- Per-bot linktitles / url_log_chan (network-scoped rows) ---
        $bots = $config['bots'] ?? [];
        if (!is_array($bots)) {
            $bots = [];
        }
        foreach ($bots as $botId => $botCfg) {
            if (!is_array($botCfg)) {
                continue;
            }
            $bot = $entityManager->getRepository(Bot::class)->find((int)$botId);
            if ($bot === null) {
                $output->writeln("<comment>skip bots.$botId (not found in DB)</comment>");
                continue;
            }
            $network = $bot->network;
            if (isset($botCfg['linktitles']) && $botCfg['linktitles'] === true) {
                $svc->setLinktitlesSetting($network, null, 'enabled', true);
                $output->writeln("imported linktitles enabled for network {$network->name} (from bot $botId)");
                $imported++;
            }
            if (isset($botCfg['url_log_chan'])) {
                $svc->setLinktitlesSetting($network, null, 'url_log_chan', $botCfg['url_log_chan']);
                $output->writeln("imported url_log_chan for network {$network->name} (from bot $botId)");
                $imported++;
            }
        }

        // --- Global AI model/prompt are now linktitles (network-scoped) settings ---
        foreach ($entityManager->getRepository(\lolbot\entities\Network::class)->findAll() as $network) {
            if (isset($config['ai_vision_model']) && is_string($config['ai_vision_model'])) {
                $svc->setLinktitlesSetting($network, null, 'ai_vision_model', $config['ai_vision_model']);
                $output->writeln("imported ai_vision_model for network {$network->name}");
                $imported++;
            }
            if (isset($config['ai_vision_prompt']) && is_string($config['ai_vision_prompt'])) {
                $svc->setLinktitlesSetting($network, null, 'ai_vision_prompt', $config['ai_vision_prompt']);
                $output->writeln("imported ai_vision_prompt for network {$network->name}");
                $imported++;
            }
        }

        $output->writeln("<info>Imported $imported value(s).</info>");
        return Command::SUCCESS;
    }

    private static function isAiDefault(string $prop, mixed $val): bool
    {
        return match ($prop) {
            'maxDim' => $val === 1024,
            'jpgQuality' => $val === 85,
            'timeout' => $val === 10,
            default => false,
        };
    }

    /**
     * Reads a typed AI service-config property by name (keeps PHPStan level 9
     * happy by avoiding dynamic $existing->$prop access on a plain object).
     */
    private static function aiPropertyValue(AiServiceConfig $cfg, string $prop): mixed
    {
        return match ($prop) {
            'apiKey' => $cfg->apiKey,
            'baseUrl' => $cfg->baseUrl,
            'maxDim' => $cfg->maxDim,
            'jpgQuality' => $cfg->jpgQuality,
            'timeout' => $cfg->timeout,
            'reasoningEffort' => $cfg->reasoningEffort,
            'reasoning' => $cfg->reasoning,
            default => null,
        };
    }

    /**
     * Reads a typed paste service-config property by name.
     */
    private static function pastePropertyValue(PasteServiceConfig $cfg, string $prop): mixed
    {
        return match ($prop) {
            'host' => $cfg->host,
            'key' => $cfg->key,
            default => null,
        };
    }
}
