<?php
require_once __DIR__ . '/../bootstrap.php';

global $entityManager;

use lolbot\entities\Network;
use scripts\tell\entities\tell;
use \RedBeanPHP\R as R;
use function Symfony\Component\String\u;

if(isset($argv[3])) {
    if(!file_exists($argv[1]) || !is_file($argv[1]))
        die("File not found\n");
    $dbfile = $argv[1];
    $network_id = $argv[2];
    $fromNet = $argv[3];
} else {
    die("Usage: ".__FILE__." <tell.db> <network_id> <fromNet>\n");
}

$network = $entityManager->getRepository(Network::class)->find($network_id);
if(!$network)
    die("Couldn't find that network_id\n");

/*
 *  $tell->date = R::isoDateTime();
    $tell->from = $from;
    $tell->msg = $msg;
    $tell->to = strtolower($nick);
    $tell->sent = 0;
    $tell->network = $network;
    $tell->chan = $chan;
    $tell->to_net = $toNet; null means global
*/

R::addDatabase("tells", "sqlite:{$dbfile}");
R::selectDatabase("tells");
$tells = R::findAll("msg", "`sent` = 0");
$otherNets = [];
$cnt = 0;
foreach($tells as $t) {
    if(strtolower($t->network) != strtolower($fromNet)) {
        $otherNets[strtolower($t->network)] = $t->network;
        continue;
    }
    $tell = new tell();
    $tell->created = new \DateTime($t->date);
    $tell->sender = $t->from;
    $tell->msg = $t->msg;
    $tell->target = u(strtoupper($t->to))->lower();
    $tell->chan = $t->chan;
    $tell->network = $network;
    if($t->to_net == null || $t->to_net == "")
        $tell->global = true;
    $cnt++;
    $entityManager->persist($tell);
}

$entityManager->flush();

echo "Skipped tells from networks: " . implode(', ', $otherNets) . "\n";
echo "$cnt Tells imported\n";