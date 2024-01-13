<?php
require_once 'bootstrap.php';
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;

//this is apparently a legacy config so the migration will find the config based on default naming
//this file is still needed as of migrations 3.7

return \Doctrine\ORM\Tools\Console\ConsoleRunner::createHelperSet($entityManager);
