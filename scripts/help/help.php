<?php
namespace scripts\help;

use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Desc;
use knivey\cmdr\attributes\Option;
use knivey\cmdr\attributes\Syntax;
use scripts\script_base;

require_once __DIR__ . '/../../library/paste.php';

class help extends script_base
{
    #[Cmd("help")]
    #[Syntax("[command]")]
    #[Option("--priv", "lookup private message commands")]
    #[Desc("lookup help for commands")]
    function help($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (isset($cmdArgs['command'])) {
            if (!$cmdArgs->optEnabled("--priv")) {
                if (!$this->router->cmdExists($cmdArgs['command'])) {
                    $bot->msg($args->chan, "no command by that name");
                    return;
                }
                $help = (string)$this->router->cmds[$cmdArgs['command']];
            } else {
                if (!$this->router->cmdExistsPriv($cmdArgs['command'])) {
                    $bot->msg($args->chan, "no command by that name");
                    return;
                }
                $help = (string)$this->router->privCmds[$cmdArgs['command']];
            }
            $this->showHelpDirect($args->chan, $bot, $help);
            return;
        }

        if ($cmdArgs->optEnabled("--priv"))
            $cmds = $this->router->privCmds;
        else
            $cmds = $this->router->cmds;

        $out = $this->formatHelpMarkdown($cmds);
        $this->showHelpPaste($args->chan, $bot, $out);
    }

    function formatHelpMarkdown($cmds): string
    {
        $out = "# Bot Commands\n\n";
        $first = true;
        foreach ($cmds as $cmd) {
            if (!$first)
                $out .= "\n---\n\n";
            $first = false;

            $syntax = trim($cmd->command . " " . $cmd->syntax);
            $out .= "## `{$syntax}`\n\n";
            foreach ($cmd->opts as $opt) {
                $out .= "- `{$opt->option}` {$opt->desc}\n";
            }
            if (!empty($cmd->opts))
                $out .= "\n";
            $out .= "{$cmd->desc}\n\n";
        }
        return $out;
    }

    function showHelpPaste(string $chan, $bot, string $content)
    {
        if (!isset($this->config['paste_host']) || !isset($this->config['paste_key'])) {
            $bot->msg($chan, "help: paste service not configured");
            return;
        }
        try {
            $url = \createPaste($content, "Bot Commands", $this->config['paste_host'], $this->config['paste_key']);
            $bot->msg($chan, "help: $url");
    } catch (\Throwable $e) {
        $bot->msg($chan, "help: trouble creating paste :( " . $e->getMessage());
    }
    }

    function showHelpDirect(string $chan, $bot, string $lines)
    {
        if (function_exists('pumpToChan')) {
            pumpToChan($bot, $chan, explode("\n", $lines));
            return;
        }
        foreach (explode("\n", $lines) as $line) {
            $bot->msg($chan, $line);
        }
    }
}
