<?php
namespace scripts\zyzz;
/*
 * return Zyzz quotes
 */
use knivey\cmdr\attributes\Cmd;

#[Cmd("zyzz")]
function zyzz($args, \Irc\Client $bot, $req)
{
    $facts = file(__DIR__ . '/zyzz.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (empty($facts)) {
        $bot->pm($args->chan, "empty quotes file");
        return;
    }
    $fact = $facts[array_rand($facts)];

    $bot->pm($args->chan, $fact);
}