<?php
namespace scripts\help;

use knivey\cmdr\attributes\Cmd;

#[Cmd("help")]
function help($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    global $router;
    $bot->notice($args->nick, "Here is a list of my commands, there is no further help");
    $first = 0;
    foreach ($router->cmds as $cmd) {
        if(!$first++)
            $bot->notice($args->nick, '---');
        foreach(explode("\n", (string)$cmd) as $line) {
            $bot->notice($args->nick, $line);
        }
    }
}