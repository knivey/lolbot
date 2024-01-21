<?php
$autoloader = require_once "vendor/autoload.php";

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\EntityManager;
use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
use Doctrine\Migrations\Configuration\Migration\YamlFile;
use Doctrine\Migrations\DependencyFactory;

$isDevMode = true;

// keeping scripts entities in separate dirs so the script files are not autoloaded at the wrong time
$paths = [
    __DIR__ . "/entities",
    __DIR__ . "/scripts/linktitles/entities",
    __DIR__ . "/scripts/weather/entities",
    __DIR__ . "/scripts/lastfm/entities"
];

$ORMconfig = ORMSetup::createAttributeMetadataConfiguration($paths, $isDevMode);

// database configuration parameters

$conn = DriverManager::getConnection(array(
    'driver' => 'pdo_pgsql',
    'user' => 'lolbot',
    'dbname' => 'lolbot',
//    'password' => 'lolpass',
//    'host' => 'localhost',
//    'port' => 5432,
//    'charset' => 'utf-8'
), $ORMconfig);


/*
$conn = DriverManager::getConnection([
    'driver' => 'pdo_sqlite',
    'path' => __DIR__ . '/db.sqlite',
], $ORMconfig);
*/

if($conn->getDatabasePlatform()::class == \Doctrine\DBAL\Platforms\SqlitePlatform::class)
    $conn->executeStatement("PRAGMA foreign_keys=ON");
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;
$entityManager = new EntityManager($conn, $ORMconfig);


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
