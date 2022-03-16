<?php
require_once 'bootstrap.php';
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;

use lolbot\entities\Ignore;


$args = $argv;

array_shift($args);

switch (array_shift($args)) {
    case 'add':
        $hostmask = array_shift($args);
        if($hostmask === null)
            die("hostmask required\n");
        $reason = array_shift($args);
        $ignore = new Ignore();
        $ignore->setHostmask($hostmask);
        $ignore->setReason($reason);
        $entityManager->persist($ignore);
        $entityManager->flush();
        die("Ignore created! $ignore\n");
    case 'del':
    case 'delete':
        $id = array_shift($args);
        if(!is_numeric($id))
            die("provide a proper id to delete\n");
        $repo = $entityManager->getRepository(Ignore::class);
        $ignore = $repo->find($id);
        if($ignore == null)
            die("couldn't find that id\n");
        $entityManager->remove($ignore);
        $entityManager->flush();
        die("Ignore $id removed!\n");
    case 'list':
        $repo = $entityManager->getRepository(Ignore::class);
        $ignores = $repo->findAll();
        foreach ($ignores as $ignore) {
            echo $ignore . "\n";
        }
        die();
    case 'update':
        die("not implemented\n");
    case 'test':
        /**
         * @var \lolbot\entities\IgnoreRepository $repo
         */
        $repo = $entityManager->getRepository(Ignore::class);
        $ignores = $repo->findByHost(array_shift($args));
        foreach ($ignores as $ignore) {
            echo $ignore . "\n";
        }
        die();
    default:
        die(implode("\n", [
            "Usage:",
            "    add <hostmask> [reason]",
            "    delete <id>",
            "    list",
            "    test <host>"
        ])."\n");
}