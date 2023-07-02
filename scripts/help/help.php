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
        showHelp($args->chan, $bot, $help);
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
    showHelp($args->chan, $bot, $out);
}

function showHelp(string $chan, $bot, string $lines) {
    if(function_exists('pumpToChan')) {
        pumpToChan($chan, explode("\n", $lines));
        return;
    }
    if(strlen($lines) > 1000) {
        \Amp\asyncCall(function () use ($chan, $bot, $lines) {
            try {
                $connectContext = (new \Amp\Socket\ConnectContext)
                    ->withConnectTimeout(15);
                $sock = yield \Amp\Socket\connect("tcp://termbin.com:9999", $connectContext);
                $sock->write($lines);
                $url = yield \Amp\ByteStream\buffer($sock);
                $sock->end();
                $bot->msg($chan, "help: $url");
            } catch (Exception $e) {
                $bot->msg($chan, "trouble uploading help :(");
            }
        });
        return;
    }
    foreach(explode("\n", $lines) as $line) {
        $bot->msg($chan, $line);
    }
}