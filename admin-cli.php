<?php
require_once 'bootstrap.php';

/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;

use lolbot\cli_cmds;
use Symfony\Component\Console\Application;

$application = new Application();

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
