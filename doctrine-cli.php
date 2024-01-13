#!/usr/bin/env php
<?php

use Doctrine\ORM\Tools\Console\ConsoleRunner;
use Doctrine\ORM\Tools\Console\EntityManagerProvider\SingleManagerProvider;

// replace with path to your own project bootstrap file
require_once 'bootstrap.php';


$commands = [
    // If you want to add your own custom console commands,
    // you can do so here.
];
global $entityManager;
ConsoleRunner::run(new SingleManagerProvider($entityManager), $commands);