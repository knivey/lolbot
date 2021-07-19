<?php

use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;

#[Cmd("masshl")]
function masshl($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    $jewbirdHosts = [
        "*@vegan.fried.chicken.with.sodiumchlori.de",
        "*@relay.jewbird.live",
    ];
    foreach($jewbirdHosts as $hostmask) {
        if (preg_match(hostmaskToRegex($hostmask), $args->fullhost)) {
            goto pass;
        }
    }
    return;
    pass:
    \Amp\asyncCall(function () use ($args, $bot) {
        try {
            $names = yield getChanUsers($args->chan, $bot);
        } catch (\Exception $e) {
            $bot->pm($args->chan, "masshl timeout? something horribowl must have happened :( try again");
            return;
        }
        $line = '';
        foreach ($names as $name) {
            $name = ltrim($name, "@+");
            $line .= "$name ";
            if(strlen($line) > 100) {
                $bot->pm($args->chan, rtrim($line));
                $line = '';
            }
        }
        $bot->pm($args->chan, rtrim($line));
    });
}

function getChanUsers($chan, $bot): \Amp\Promise {
    return \Amp\call(function () use ($chan, $bot) {
        $idx = null;
        $def = new \Amp\Deferred();
        $cb = function ($args, \Irc\Client $bot) use (&$idx, $chan, &$def) {
            if($args->channel == $chan) {
                $bot->off('names', null, $idx);
                $def->resolve($args->names);
            }
        };
        $bot->on('names', $cb, $idx);
        $bot->send("NAMES $chan");
        try {
            $names = yield \Amp\Promise\timeout($def->promise(), 4000);
        } catch (\Amp\TimeoutException $e) {
            $bot->off('names', null, $idx);
            throw $e;
        }
        return $names->names;
    });
}