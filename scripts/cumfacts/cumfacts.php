<?php

global $router; //lol makes ide not complain
$router->add('info', 'info');

function info($nick, $chan, \Irc\Client $bot, $req)
{
    $facts = file(__DIR__ . '/cumfacts.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $fact = $facts[array_rand($facts)];
    if ($fact == '') {
        $bot->pm($chan, "mmmm cum");
        return;
    }
    $bot->pm($chan, $fact);
}