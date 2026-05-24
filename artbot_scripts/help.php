<?php
namespace scripts\help;

use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Desc;
use knivey\cmdr\attributes\Option;
use knivey\cmdr\attributes\Options;
use knivey\cmdr\attributes\Syntax;

require_once __DIR__ . '/../library/paste.php';

#[Cmd("help")]
#[Syntax("[command]")]
#[Option("--priv", "lookup private message commands")]
#[Desc("lookup help for commands")]
function help($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    $router = \NetworkContext::get($bot)->router;
    if(isset($cmdArgs['command'])) {
        if(!$cmdArgs->optEnabled("--priv")) {
            if (!$router->cmdExists($cmdArgs['command'])) {
                $bot->msg($args->chan, "no command by that name");
                return;
            }
            $help = (string)$router->cmds[$cmdArgs['command']];
        } else {
            if (!$router->cmdExistsPriv($cmdArgs['command'])) {
                $bot->msg($args->chan, "no command by that name");
                return;
            }
            $help = (string)$router->privCmds[$cmdArgs['command']];
        }
        showHelpDirect($bot, $args->chan, $help);
        return;
    }

    if($cmdArgs->optEnabled("--priv"))
        $cmds = $router->privCmds;
    else
        $cmds = $router->cmds;

    $out = formatHelpMarkdown($cmds);
    showHelpPaste($bot, $args->chan, $out);
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

function showHelpPaste($bot, $chan, string $content)
{
    global $config;
    if (!isset($config['paste_host']) || !isset($config['paste_key'])) {
        $bot->msg($chan, "help: paste service not configured");
        return;
    }
    try {
        $url = \createPaste($content, "Bot Commands", $config['paste_host'], $config['paste_key']);
        $bot->msg($chan, "help: $url");
    } catch (\Throwable $e) {
        $bot->msg($chan, "help: trouble creating paste :( " . $e->getMessage());
    }
}

function showHelpDirect($bot, $chan, string $lines)
{
    \pumpToChan($bot, $chan, explode("\n", $lines));
}
