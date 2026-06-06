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
        $this->addOption("network", "N", InputOption::VALUE_REQUIRED, "Network ID (required)");
        $this->addOption("channel", "C", InputOption::VALUE_REQUIRED, "Channel ID (optional, for per-channel setting)");
        $this->addArgument("setting", InputArgument::OPTIONAL, "Setting name");
        $this->addArgument("value", InputArgument::OPTIONAL, "New value");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;

        $networkId = $input->getOption("network");
        if ($networkId === null) {
            throw new \InvalidArgumentException("--network is required");
        }

        $network = $entityManager->getRepository(Network::class)->find($networkId);
        if (!$network) {
            throw new \InvalidArgumentException("Network by that ID not found");
        }

        $channelId = $input->getOption("channel");
        $channel = null;
        if ($channelId !== null) {
            $channel = $entityManager->getRepository(Channel::class)->find($channelId);
            if (!$channel) {
                throw new \InvalidArgumentException("Channel by that ID not found");
            }
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
            "ai_vision_disabled" => $setting->ai_vision_disabled = filter_var($val, FILTER_VALIDATE_BOOLEAN),
        };

        $entityManager->persist($setting);
        $entityManager->flush();

        $this->showSettings($input, $output, $setting, $network, $channel);

        return Command::SUCCESS;
    }

    private function showSettings(InputInterface $input, OutputInterface $output, ?linktitles_setting $setting, Network $network, ?Channel $channel): void
    {
        $io = new SymfonyStyle($input, $output);
        $scope = $channel ? "network:{$network->name} channel:{$channel->name}" : "network:{$network->name}";
        $io->title("Linktitles settings ($scope)");

        $rows = [];
        foreach ($this->settings as $s) {
            $val = $setting?->$s ?? ($s === 'ai_vision_disabled' ? 'false' : '');
            $rows[] = [$s, is_bool($val) ? ($val ? 'true' : 'false') : (string)$val];
        }
        $io->table(["Setting", "Value"], $rows);
    }
}
