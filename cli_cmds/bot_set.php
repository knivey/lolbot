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
use lolbot\entities\Bot;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand("bot:set")]
class bot_set extends Command
{
    /** @var array<string> */
    public array $settings = [
        "name",
        "trigger",
        "trigger_re",
        "onConnect",
        "sasl_user",
        "sasl_pass",
        "bindIp"
    ];
    protected function configure(): void
    {
        $this->addArgument("bot", InputArgument::REQUIRED, "Bot ID");
        $this->addArgument("setting", InputArgument::OPTIONAL, "setting name");
        $this->addArgument("value", InputArgument::OPTIONAL, "New value");
    }

    //probably could make a generic base command class for settings

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;
        $svc = new \lolbot\config\ConfigService($entityManager);
        $idArg = $input->getArgument("bot");
        if (!is_string($idArg)) {
            throw new \LogicException("'bot' argument must be a string");
        }
        $bot = $svc->getBot((int)$idArg);
        if (!$bot) {
            throw new \InvalidArgumentException("Bot by that ID not found");
        }

        if ($input->getArgument("setting") === null) {
            $this->showsets($input, $output, $bot);
            return Command::SUCCESS;
        }

        $setting = $input->getArgument("setting");
        if (!is_string($setting) || !in_array($setting, $this->settings, true)) {
            throw new \InvalidArgumentException("No setting by that name");
        }

        if ($input->getArgument("value") === null) {
            $this->showsets($input, $output, $bot);
            return Command::SUCCESS;
        }

        $value = $input->getArgument("value");
        $bot->$setting = is_string($value) ? $value : '';
        $svc->update($bot, "bot");

        $this->showsets($input, $output, $bot);
        return Command::SUCCESS;
    }

    function showsets(InputInterface $input, OutputInterface $output, Bot $bot): void {
        $io = new SymfonyStyle($input, $output);
        $rows = [];
        foreach ($this->settings as $setting) {
            $rows[] = [$setting, $bot->$setting];
        }
        $io->table(
            ["Setting", "Value"],
            $rows
        );
    }
}
