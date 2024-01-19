<?php
require_once 'bootstrap.php';

/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager, $dependencyFactory;

use lolbot\cli_cmds;
use Symfony\Component\Console\Application;
use Doctrine\Migrations\Tools\Console\Command;

$application = new Application();

$application->addCommands(array(
    new Command\DumpSchemaCommand($dependencyFactory),
    new Command\ExecuteCommand($dependencyFactory),
    new Command\GenerateCommand($dependencyFactory),
    new Command\LatestCommand($dependencyFactory),
    new Command\ListCommand($dependencyFactory),
    new Command\MigrateCommand($dependencyFactory),
    new Command\RollupCommand($dependencyFactory),
    new Command\StatusCommand($dependencyFactory),
    new Command\SyncMetadataCommand($dependencyFactory),
    new Command\VersionCommand($dependencyFactory),
));

$application->add(new cli_cmds\ignore_add());
$application->add(new cli_cmds\ignore_del());
$application->add(new cli_cmds\ignore_list());
$application->add(new cli_cmds\ignore_addnetwork());
$application->add(new cli_cmds\ignore_test());

$application->add(new cli_cmds\bot_add());
$application->add(new cli_cmds\bot_del());
$application->add(new cli_cmds\bot_list());

$application->add(new cli_cmds\network_add());
$application->add(new cli_cmds\network_del());
$application->add(new cli_cmds\network_list());

$application->add(new cli_cmds\showdb());

$application->run();
