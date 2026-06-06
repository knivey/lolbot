<?php
namespace lolbot\cli_cmds;
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
        "ai_vision_disabled"
    ];

    protected function configure(): void
    {
        $this->addOption("network", "N", InputOption::VALUE_REQUIRED, "Network ID (required unless --channel is given)");
        $this->addOption("channel", "C", InputOption::VALUE_REQUIRED, "Channel ID (optional, for per-channel setting)");
        $this->addOption("reset", "R", InputOption::VALUE_NONE, "Reset channel setting to inherited (deletes the settings row)");
        $this->addArgument("setting", InputArgument::OPTIONAL, "Setting name");
        $this->addArgument("value", InputArgument::OPTIONAL, "New value (use 'inherit' to reset to inherited)");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;

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
        } else {
            throw new \InvalidArgumentException("--network or --channel is required");
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
                $entityManager->remove($setting);
                $entityManager->flush();
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
        };

        $entityManager->persist($setting);
        $entityManager->flush();

        $this->showSettings($input, $output, $setting, $network, $channel);

        return Command::SUCCESS;
    }

    private function showSettings(InputInterface $input, OutputInterface $output, ?linktitles_setting $setting, Network $network, ?Channel $channel): void
    {
        $io = new SymfonyStyle($input, $output);
        global $entityManager;

        if ($channel) {
            $io->title("Linktitles settings (network:{$network->name} channel:{$channel->name})");
            $rows = [];
            foreach ($this->settings as $s) {
                $val = $setting?->$s ?? ($s === 'ai_vision_disabled' ? 'false' : '');
                $label = $setting !== null ? 'set' : 'inherited';
                $rows[] = [$s, (is_bool($val) ? ($val ? 'true' : 'false') : (string)$val), $label];
            }
            $io->table(["Setting", "Value", "Source"], $rows);
            return;
        }

        $io->title("Linktitles settings (network:{$network->name})");
        $rows = [];
        foreach ($this->settings as $s) {
            $val = $setting?->$s ?? ($s === 'ai_vision_disabled' ? 'false' : '');
            $rows[] = [$s, is_bool($val) ? ($val ? 'true' : 'false') : (string)$val];
        }
        $io->table(["Setting", "Value"], $rows);

        $networkVal = $setting?->ai_vision_disabled ?? false;

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
                        $rows[] = (is_bool($v) ? ($v ? 'true' : 'false') : (string)$v) . ' (set)';
                    } else {
                        $v = $s === 'ai_vision_disabled' ? $networkVal : false;
                        $rows[] = (is_bool($v) ? ($v ? 'true' : 'false') : (string)$v) . ' (inherited)';
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
