<?php
require_once 'bootstrap.php';
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;

/*
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\Setup;
use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
use Doctrine\Migrations\Configuration\Migration\YamlFile;
use Doctrine\Migrations\DependencyFactory;
*/

//this is apparently a legacy config so the migration will find the config based on default naming
//$config = new YamlFile('migrations.yml');
//return DependencyFactory::fromEntityManager($config, new ExistingEntityManager($entityManager));


return \Doctrine\ORM\Tools\Console\ConsoleRunner::createHelperSet($entityManager);
