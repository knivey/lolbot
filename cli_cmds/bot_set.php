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
        $bot = $entityManager->getRepository(Bot::class)->find($input->getArgument("bot"));
        if(!$bot) {
            throw new \InvalidArgumentException("Server by that ID not found");
        }

        if($input->getArgument("setting") === null) {
            $this->showsets($input, $output, $bot);
            return Command::SUCCESS;
        }

        if(!in_array($input->getArgument("setting"), $this->settings)) {
            throw new \InvalidArgumentException("No setting by that name");
        }

        if($input->getArgument("value") === null) {
            $this->showsets($input, $output, $bot);
            return Command::SUCCESS;
        }

        $bot->{$input->getArgument("setting")} = $input->getArgument("value");


        $entityManager->persist($bot);
        $entityManager->flush();

        $this->showsets($input, $output, $bot);

        return Command::SUCCESS;
    }

    function showsets(InputInterface $input, OutputInterface $output, $bot) {
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
