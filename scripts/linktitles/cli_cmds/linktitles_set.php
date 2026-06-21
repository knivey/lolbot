<?php
namespace scripts\linktitles\cli_cmds;
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;

use lolbot\entities\Channel;
use lolbot\entities\Network;
use scripts\linktitles\entities\linktitles_setting;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand("linktitles:set")]
class linktitles_set extends Command
{
    /** @var array<string> */
    public array $settings = [
        "ai_vision_disabled",
        "enabled",
        "url_log_chan",
        "ai_vision_model",
        "ai_vision_prompt",
        "ai_vision_reasoning_effort",
        "ai_vision_reasoning",
    ];

    protected function configure(): void
    {
        $this->addOption("global", "G", InputOption::VALUE_NONE, "Target the global linktitles tier (network=null, channel=null)");
        $this->addOption("network", "N", InputOption::VALUE_REQUIRED, "Network ID (required unless --channel or --global is given)");
        $this->addOption("channel", "C", InputOption::VALUE_REQUIRED, "Channel ID (optional, for per-channel setting)");
        $this->addOption("reset", "R", InputOption::VALUE_NONE, "Reset channel setting to inherited (deletes the settings row)");
        $this->addArgument("setting", InputArgument::OPTIONAL, "Setting name");
        $this->addArgument("value", InputArgument::OPTIONAL, "New value (use 'inherit' to reset to inherited)");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;

        $isGlobal = (bool)$input->getOption("global");
        $channelId = $input->getOption("channel");
        $channel = null;
        if ($channelId !== null) {
            $channel = $entityManager->getRepository(Channel::class)->find($channelId);
            if (!$channel) {
                throw new \InvalidArgumentException("Channel by that ID not found");
            }
        }

        $networkId = $input->getOption("network");
        if ($channelId !== null) {
            $network = $channel->bot->network;
        } elseif ($networkId !== null) {
            $network = $entityManager->getRepository(Network::class)->find($networkId);
            if (!$network) {
                throw new \InvalidArgumentException("Network by that ID not found");
            }
        } elseif ($isGlobal) {
            $network = null;
        } else {
            throw new \InvalidArgumentException("--network, --channel, or --global is required");
        }

        $repo = $entityManager->getRepository(linktitles_setting::class);
        $setting = $repo->findOneBy([
            'network' => $channel ? null : $network,
            'channel' => $channel,
        ]);

        if ($input->getArgument("setting") === null) {
            $this->showSettings($input, $output, $setting, $network, $channel);
            return Command::SUCCESS;
        }

        if (!in_array($input->getArgument("setting"), $this->settings)) {
            throw new \InvalidArgumentException("No setting by that name. Available: " . implode(", ", $this->settings));
        }

        if ($input->getOption("reset") || strtolower($input->getArgument("value") ?? '') === 'inherit') {
            if ($setting !== null) {
                $svc = new \lolbot\config\ConfigService($entityManager, \lolbot\config\build_change_notifier());
                $svc->deleteLinktitlesSettingScope($network, $channel);
                $output->writeln("Setting reset to inherited");
            } else {
                $output->writeln("No setting to reset (already inherited)");
            }
            $this->showSettings($input, $output, null, $network, $channel);
            return Command::SUCCESS;
        }

        if ($input->getArgument("value") === null) {
            $this->showSettings($input, $output, $setting, $network, $channel);
            return Command::SUCCESS;
        }

        if ($setting === null) {
            $setting = new linktitles_setting();
            $setting->network = $channel ? null : $network;
            $setting->channel = $channel;
        }

        $val = $input->getArgument("value");
        match ($input->getArgument("setting")) {
            "ai_vision_disabled" => $setting->ai_vision_disabled = filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? throw new \InvalidArgumentException("Value must be true or false"),
            "enabled" => $setting->enabled = filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? throw new \InvalidArgumentException("Value must be true or false"),
            "url_log_chan" => $setting->url_log_chan = is_string($val) ? $val : throw new \InvalidArgumentException("Value must be a string"),
            "ai_vision_model" => $setting->ai_vision_model = is_string($val) ? $val : throw new \InvalidArgumentException("Value must be a string"),
            "ai_vision_prompt" => $setting->ai_vision_prompt = is_string($val) ? $val : throw new \InvalidArgumentException("Value must be a string"),
            "ai_vision_reasoning_effort" => $setting->ai_vision_reasoning_effort = is_string($val) ? $val : throw new \InvalidArgumentException("Value must be a string"),
            "ai_vision_reasoning" => $setting->ai_vision_reasoning = self::parseReasoningJson($val),
        };

        $svc = new \lolbot\config\ConfigService($entityManager, \lolbot\config\build_change_notifier());
        $svc->saveLinktitlesSetting($setting);

        $this->showSettings($input, $output, $setting, $network, $channel);

        return Command::SUCCESS;
    }

    private static function parseReasoningJson(mixed $val): array
    {
        if (!is_string($val)) {
            throw new \InvalidArgumentException("Value must be valid JSON for an array");
        }
        $decoded = json_decode($val, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException("Value must be a valid JSON array/object");
        }
        return $decoded;
    }

    private static function fmtVal(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_array($v)) {
            return json_encode($v, JSON_UNESCAPED_SLASHES) ?: '';
        }
        if (is_string($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return (string)$v;
        }
        return '';
    }

    private function showSettings(InputInterface $input, OutputInterface $output, ?linktitles_setting $setting, ?Network $network, ?Channel $channel): void
    {
        $io = new SymfonyStyle($input, $output);
        global $entityManager;

        if ($channel) {
            $netName = $network !== null ? $network->name : '?';
            $io->title("Linktitles settings (network:{$netName} channel:{$channel->name})");
            $rows = [];
            foreach ($this->settings as $s) {
                $val = $setting !== null ? $setting->$s : (in_array($s, ['ai_vision_disabled', 'enabled'], true) ? false : '');
                $label = $setting !== null ? 'set' : 'inherited';
                $rows[] = [$s, self::fmtVal($val), $label];
            }
            $io->table(["Setting", "Value", "Source"], $rows);
            return;
        }

        if ($network === null) {
            $io->title("Linktitles settings (global)");
            $rows = [];
            foreach ($this->settings as $s) {
                $val = $setting !== null ? $setting->$s : (in_array($s, ['ai_vision_disabled', 'enabled'], true) ? false : '');
                $rows[] = [$s, self::fmtVal($val)];
            }
            $io->table(["Setting", "Value"], $rows);
            return;
        }

        $io->title("Linktitles settings (network:{$network->name})");
        $rows = [];
        foreach ($this->settings as $s) {
            $val = $setting !== null ? $setting->$s : (in_array($s, ['ai_vision_disabled', 'enabled'], true) ? false : '');
            $rows[] = [$s, self::fmtVal($val)];
        }
        $io->table(["Setting", "Value"], $rows);

        $networkVal = $setting !== null ? $setting->ai_vision_disabled : false;

        $qb = $entityManager->getRepository(linktitles_setting::class)->createQueryBuilder('s');
        $channelSettings = $qb->where('s.channel IS NOT NULL')
            ->getQuery()
            ->getResult();
        $channelMap = [];
        foreach ($channelSettings as $cs) {
            if ($cs->channel !== null) {
                $channelMap[$cs->channel->id] = $cs;
            }
        }

        $channelRows = [];
        foreach ($network->getBots() as $bot) {
            foreach ($bot->getChannels() as $ch) {
                $cs = $channelMap[$ch->id] ?? null;
                $rows = ["{$ch->id}:{$ch->name}"];
                foreach ($this->settings as $s) {
                    if ($cs !== null) {
                        $v = $cs->$s;
                        $rows[] = self::fmtVal($v) . ' (set)';
                    } else {
                        $v = in_array($s, ['ai_vision_disabled', 'enabled'], true) ? ($s === 'ai_vision_disabled' ? $networkVal : false) : '';
                        $rows[] = self::fmtVal($v) . ' (inherited)';
                    }
                }
                $channelRows[] = $rows;
            }
        }

        if (count($channelRows) > 0) {
            $io->section("Channel settings");
            $io->table(array_merge(["Channel"], $this->settings), $channelRows);
        }
    }
}
