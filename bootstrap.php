<?php
require_once "vendor/autoload.php";

use Doctrine\ORM\Tools\Setup;
use Doctrine\ORM\EntityManager;
use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
use Doctrine\Migrations\Configuration\Migration\YamlFile;
use Doctrine\Migrations\DependencyFactory;

$isDevMode = true;

// database configuration parameters
$conn = array(
    'driver' => 'pdo_pgsql',
    'user' => 'lolbot',
    'dbname' => 'lolbot',
    'password' => 'lolpass',
    'host' => 'localhost',
    'port' => 5432,
    'charset' => 'utf-8'
);


$paths = [
    __DIR__ . "/entities",
//    __DIR__ . "/scripts/*/entities"
];

$ORMconfig = Setup::createAttributeMetadataConfiguration($paths, $isDevMode);


/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;
$entityManager = EntityManager::create($conn, $ORMconfig);


$migrationConfig = new YamlFile('migrations.yml');
$dependencyFactory = DependencyFactory::fromEntityManager($migrationConfig, new ExistingEntityManager($entityManager));

function dieIfPendingMigration(): void
{
    global $dependencyFactory;
    $availMigrations = $dependencyFactory->getMigrationStatusCalculator()->getNewMigrations();
    if ($availMigrations->count() != 0) {
        die("EXITING: You have pending migrations to execute\n Use the vendor/bin/doctrine-migrations tool first\n");
    }
}
