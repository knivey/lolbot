<?php
require_once "vendor/autoload.php";

use Doctrine\ORM\Tools\Setup;
use Doctrine\ORM\EntityManager;

// Create a simple "default" Doctrine ORM configuration for Annotations
$isDevMode = true;

$paths = [
    __DIR__ . "/entities",
//    __DIR__ . "/scripts/*/entities"
];

$ORMconfig = Setup::createAttributeMetadataConfiguration($paths, $isDevMode);

// database configuration parameters
$conn = array(
    'driver' => 'pdo_sqlite',
    'path' => __DIR__ . '/data/db.sqlite',
);

$entityManager = EntityManager::create($conn, $ORMconfig);
