<?php
namespace knivey\lolbot\help;

use knivey\cmdr\attributes\Cmd;

#[Cmd("help")]
function help($args, \Irc\Client $bot, $req)
{
    global $router;
    $bot->notice($args->nick, "Here is a list of my commands, there is no further help");
    foreach ($router->cmds as $name => $cmd) {
        $bot->notice($args->nick, "$name {$cmd->syntax}");
    }
}