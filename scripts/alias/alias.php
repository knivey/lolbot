<?php
namespace scripts\alias;

use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Options;
use knivey\cmdr\attributes\Syntax;
use \RedBeanPHP\R as R;


global $config;
$aliasdb = 'alias-' . uniqid();
$dbfile = $config['aliasdb'] ?? "alias.db";
R::addDatabase($aliasdb, "sqlite:{$dbfile}");

#[Cmd("alias")]
#[Syntax("<name> <value>...")]
#[Options("--me", "--act")]
function alias($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    global $aliasdb;
    list($rpl, $rpln) = makeRepliers($args, $bot, "alias");
    R::selectDatabase($aliasdb);
    $alias = R::findOne("alias", " `name_lowered` = ? AND `chan_lowered` = ? ",
        [strtolower($req->args['name']), strtolower($args->chan)]);
    $msg='';
    if ($alias != null) {
        $msg = "That alias already exists, updating it... ";
    } else {
        $alias = R::dispense("alias");
    }
    $alias->name = $req->args['name'];
    $alias->name_lowered = strtolower($req->args['name']);
    $alias->value = $req->args['value'];
    $alias->chan = $args->chan;
    $alias->chan_lowered = strtolower($args->chan);
    $alias->fullhost = $args->fullhost;
    $alias->act = ($req->args->getOpt('--act') || $req->args->getOpt('--me'));
    R::store($alias);
    $rpl("{$msg}alias saved");
}

#[Cmd("unalias")]
#[Syntax("<name>")]
function unalias($args, \Irc\Client $bot, \knivey\cmdr\Request $req)
{
    global $aliasdb;
    list($rpl, $rpln) = makeRepliers($args, $bot, "alias");
    R::selectDatabase($aliasdb);
    $alias = R::findOne("alias", " `name_lowered` = ? AND `chan_lowered` = ? ",
        [strtolower($req->args['name']), strtolower($args->chan)]);
    if ($alias != null) {
        $rpl("That alias not found");
        return;
    }
    R::trash($alias);
    $rpl("Alias removed");
}

function handleCmd($args, $bot, $cmd, $cmdArgs, $opts) {
    global $aliasdb;
    R::selectDatabase($aliasdb);
    $alias = R::findOne("alias", " `name_lowered` = ? AND `chan_lowered` = ? ",
        [strtolower($cmd), strtolower($args->chan)]);
    if($alias == null)
        return false;
    $value = $alias->value;
    // Just keeping this very simple atm, may build a proper parser later
    // using str_replace the order is important
    $vars = [
        '$0-' => "$cmd " . implode(" ", $cmdArgs),
        '$1-' => implode(" ", $cmdArgs),
        '$2-' => implode(" ", array_slice($cmdArgs, 1)),
        '$3-' => implode(" ", array_slice($cmdArgs, 2)),
        '$4-' => implode(" ", array_slice($cmdArgs, 3)),
        '$5-' => implode(" ", array_slice($cmdArgs, 4)),
        '$6-' => implode(" ", array_slice($cmdArgs, 5)),
        '$7-' => implode(" ", array_slice($cmdArgs, 6)),
        '$8-' => implode(" ", array_slice($cmdArgs, 7)),
        '$9-' => implode(" ", array_slice($cmdArgs, 8)),

        '$0' => $cmd,
        '$1' => $cmdArgs[0] ?? "",
        '$2' => $cmdArgs[1] ?? "",
        '$3' => $cmdArgs[2] ?? "",
        '$4' => $cmdArgs[3] ?? "",
        '$5' => $cmdArgs[4] ?? "",
        '$6' => $cmdArgs[5] ?? "",
        '$7' => $cmdArgs[6] ?? "",
        '$8' => $cmdArgs[7] ?? "",
        '$9' => $cmdArgs[8] ?? "",
        //if any of these have $whatever that matches something that follows its gonna be replaceable by what follows
        '$nick' => $args->nick,
        '$chan' => $args->chan,
        '$target' => count($cmdArgs) > 0 ? implode(' ', $cmdArgs) : $args->nick,
    ];
    $value = str_replace(array_keys($vars), $vars, $value);
    var_dump($alias);
    if($alias->act) {
        $bot->msg($args->chan, "\x01ACTION $value\x01");
    } else {
        $bot->msg($args->chan, "\2{$alias->name}:\2 $value");
    }
    return true;
}