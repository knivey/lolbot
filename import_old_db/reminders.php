<?php
require_once __DIR__ . '/../bootstrap.php';

global $entityManager;

use lolbot\entities\Network;
use scripts\alias\entities\alias;
use \RedBeanPHP\R as R;
use scripts\remindme\entities\reminder;
use Symfony\Component\String\UnicodeString as U;

if(isset($argv[2])) {
    if(!file_exists($argv[1]) || !is_file($argv[1]))
        die("File not found\n");
    $dbfile = $argv[1];
    $network_id = $argv[2];
} else {
    die("Usage: ".__FILE__." <reminder.db> <network_id>\n");
}

$network = $entityManager->getRepository(Network::class)->find($network_id);
if(!$network)
    die("Couldn't find that network_id\n");

R::addDatabase("reminders", "sqlite:{$dbfile}");
R::selectDatabase("reminders");
$reminders = R::findAll("reminder");
foreach($reminders as $r) {
    $newReminder = new reminder();
    $newReminder->nick = $r->nick;
    $newReminder->chan = $r->chan;
    $newReminder->at = $r->at;
    $newReminder->sent = $r->sent;
    $newReminder->msg = $r->msg;
    $newReminder->network = $network;
    $entityManager->persist($newReminder);
}

$entityManager->flush();

echo "Reminders imported\n";