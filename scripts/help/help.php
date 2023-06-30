<?php
namespace scripts\help;

use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Desc;
use knivey\cmdr\attributes\Option;
use knivey\cmdr\attributes\Options;
use knivey\cmdr\attributes\Syntax;

#[Cmd("help")]
#[Syntax("[command]")]
#[Option("--priv", "lookup private message commands")]
#[Desc("lookup help for commands")]
function help($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    global $router;
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
        foreach(explode("\n", $help) as $line) {
            $bot->msg($args->chan, $line);
        }
    }
    $first = 0;
    if($cmdArgs->optEnabled("--priv"))
        $cmds = $router->privCmds;
    else
        $cmds = $router->cmds;
    foreach ($cmds as $cmd) {
        if($first++)
            $bot->msg($args->chan, '---');
        foreach(explode("\n", (string)$cmd) as $line) {
            $bot->msg($args->chan, $line);
        }
    }
}