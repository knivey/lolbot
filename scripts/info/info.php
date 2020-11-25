<?php

$router->add('help', 'help');
function help($args, $nick, $chan, \Irc\Client $bot)
{
    global $router;
    $bot->notice($nick, "Here is a list of my commands, there is no further help");
    foreach ($router->getRoutes() as $route) {
        $bot->notice($nick, (string)$route);
    }
}