<?php
namespace scripts\cumfacts;

use knivey\cmdr\attributes\Cmd;

#[Cmd("info")]
function info($args, \Irc\Client $bot, $req)
{
    $facts = file(__DIR__ . '/cumfacts.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (empty($facts)) {
        $bot->pm($args->chan, "mmmm cum");
        return;
    }
    $fact = $facts[array_rand($facts)];
    $bot->pm($args->chan, $fact);
}