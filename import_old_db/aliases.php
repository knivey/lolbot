<?php
require_once __DIR__ . '/../bootstrap.php';

global $entityManager;

use lolbot\entities\Network;
use scripts\alias\entities\alias;
use \RedBeanPHP\R as R;

if(isset($argv[2])) {
    if(!file_exists($argv[1]) || !is_file($argv[1]))
        die("File not found\n");
    $dbfile = $argv[1];
    $network_id = $argv[2];
} else {
    die("Usage: ".__FILE__." <alias.db> <network_id>\n");
}

$network = $entityManager->getRepository(Network::class)->find($network_id);
if(!$network)
    die("Couldn't find that network_id\n");

R::addDatabase("aliases", "sqlite:{$dbfile}");
R::selectDatabase("aliases");
$aliases = R::findAll("alias");
foreach($aliases as $a) {
    $alias = new alias();
    $alias->name = $a->name;
    $alias->nameLowered = $a->name_lowered;
    $alias->value = $a->value;
    $alias->chan = $a->chan;
    $alias->chanLowered = $a->chan_lowered;
    $alias->fullhost = $a->fullhost;
    $alias->act = $a->act;
    $alias->cmd = $a->cmd;
    $alias->network = $network;
    $entityManager->persist($alias);
}

$entityManager->flush();

echo "Aliases imported\n";