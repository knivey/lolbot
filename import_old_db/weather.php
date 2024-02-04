<?php
require_once __DIR__ . '/../bootstrap.php';

global $entityManager;

use lolbot\entities\Network;
use scripts\alias\entities\alias;
use function Symfony\Component\String\u;

if(isset($argv[2])) {
    if(!file_exists($argv[1]) || !is_file($argv[1]))
        die("File not found\n");
    $dbfile = $argv[1];
    $network_id = $argv[2];
} else {
    die("Usage: ".__FILE__." <weather.db> <network_id>\n");
}

$network = $entityManager->getRepository(Network::class)->find($network_id);
if(!$network)
    die("Couldn't find that network_id\n");

$locs = unserialize(file_get_contents($dbfile));

foreach($locs as $nick => $l) {
    $location = new \scripts\weather\entities\location();
    $location->nick = u(strtoupper($nick))->lower();
    $location->si = $l['si'];
    $location->name = $l['location'];
    $location->lat = $l['lat'];
    $location->long = $l['lon'];
    $location->network = $network;
    $entityManager->persist($location);
}

$entityManager->flush();

echo "Weather locations imported\n";
