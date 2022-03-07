<?php
require_once 'bootstrap.php';

/*
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\Setup;
use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
use Doctrine\Migrations\Configuration\Migration\YamlFile;
use Doctrine\Migrations\DependencyFactory;
*/

//this is apparently a legacy config so the migration will find the config based on default naming
//$config = new YamlFile('migrations.yml');


/**
 * @var Doctrine\ORM\EntityManager $entityManager
 */
//return DependencyFactory::fromEntityManager($config, new ExistingEntityManager($entityManager));
return \Doctrine\ORM\Tools\Console\ConsoleRunner::createHelperSet($entityManager);
