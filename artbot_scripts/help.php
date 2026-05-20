<?php
namespace scripts\help;

use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Desc;
use knivey\cmdr\attributes\Option;
use knivey\cmdr\attributes\Options;
use knivey\cmdr\attributes\Syntax;
use PHPUnit\Exception;

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
        showHelp($bot, $args->chan, $help);
        return;
    }
    $first = 0;
    if($cmdArgs->optEnabled("--priv"))
        $cmds = $router->privCmds;
    else
        $cmds = $router->cmds;
    $out = "";
    foreach ($cmds as $cmd) {
        if($first++)
            $out .= "---\n";
        $out .= (string)$cmd . "\n";
    }
    showHelp($bot, $args->chan, $out);
}

function showHelp($bot, $chan, $lines) {
    \pumpToChan($bot, $chan, explode("\n", $lines));
}
