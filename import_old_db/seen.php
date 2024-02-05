<?php
require_once __DIR__ . '/../bootstrap.php';

global $entityManager;

use lolbot\entities\Network;
use \RedBeanPHP\R as R;
use Symfony\Component\String\UnicodeString as U;

if(isset($argv[2])) {
    if(!file_exists($argv[1]) || !is_file($argv[1]))
        die("File not found\n");
    $dbfile = $argv[1];
    $network_id = $argv[2];
} else {
    die("Usage: ".__FILE__." <seen.db> <network_id>\n");
}

$network = $entityManager->getRepository(Network::class)->find($network_id);
if(!$network)
    die("Couldn't find that network_id\n");

R::addDatabase("seen", "sqlite:{$dbfile}");
R::selectDatabase("seen");
$ss = R::findAll("seen");
$c = 0;
foreach($ss as $s) {
    $seen = new \scripts\seen\entities\seen();
    $seen->nick = $s->nick;
    $seen->orig_nick = $s->orig_nick;
    $seen->chan = $s->chan;
    $seen->text = $s->text;
    $seen->action = $s->action;
    $seen->time = new \DateTime($s->time);
    $seen->network = $network;
    $entityManager->persist($seen);
    $c++;
}

$entityManager->flush();

echo "$c Seens imported\n";