<?php

global $router;
$router->add('help', 'help');
function help($nick, $chan, \Irc\Client $bot, $req)
{
    global $router;
    $bot->notice($nick, "Here is a list of my commands, there is no further help");
    foreach ($router->cmds as $name => $cmd) {
        $bot->notice($nick, "$name {$cmd->syntax}");
    }
}