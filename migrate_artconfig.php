#!/usr/bin/env php
<?php

use Symfony\Component\Yaml\Yaml;

if(php_sapi_name() !== 'cli')
    die("This script must be run from the command line\n");

require_once 'vendor/autoload.php';

$botKeys = ['name', 'server', 'port', 'ssl', 'throttle', 'bindIp', 'pass'];
$dbKeys = ['quotedb'];

function usageAndExit(string $msg = ''): never {
    if($msg !== '')
        fwrite(STDERR, "Error: {$msg}\n");
    fwrite(STDERR, "Usage: ".__FILE__." -n <name> config1.yaml [-n <name> config2.yaml ...] [-o output.yaml]\n");
    fwrite(STDERR, "  -n <name>     Network name (required before each config file)\n");
    fwrite(STDERR, "  -o <path>     Output file (default: artbotsconfig.yaml)\n");
    exit(1);
}

$inputs = [];
$outputFile = 'artbotsconfig.yaml';
$currentName = null;

for($i = 1; $i < count($argv); $i++) {
    if($argv[$i] === '-n') {
        if(!isset($argv[$i + 1]))
            usageAndExit("-n requires a network name");
        $currentName = $argv[++$i];
    } elseif($argv[$i] === '-o') {
        if(!isset($argv[$i + 1]))
            usageAndExit("-o requires an output path");
        $outputFile = $argv[++$i];
    } else {
        if($currentName === null)
            usageAndExit("Network name (-n) is required before config file: {$argv[$i]}");
        $inputs[] = ['file' => $argv[$i], 'name' => $currentName];
        $currentName = null;
    }
}

if(empty($inputs))
    usageAndExit("No input files specified");

foreach($inputs as $input) {
    if(!file_exists($input['file']) || !is_file($input['file']))
        usageAndExit("Input file not found: {$input['file']}");
}

$existingNetworks = [];
if(file_exists($outputFile)) {
    $existing = Yaml::parseFile($outputFile);
    if(!is_array($existing) || !isset($existing['networks']) || !is_array($existing['networks']))
        usageAndExit("Output file {$outputFile} exists but is not a valid artbotsconfig (missing networks array)");
    foreach($existing['networks'] as $net)
        $existingNetworks[$net['name']] = $net;
}

$dbTracker = [];
$dbKeyMap = [];
$warnings = [];
$networksAdded = [];

foreach($inputs as $input) {
    $inputFile = $input['file'];
    $networkName = $input['name'];

    if(isset($existingNetworks[$networkName])) {
        fwrite(STDERR, "Error: Network '{$networkName}' already exists in {$outputFile}. Remove or rename it first.\n");
        exit(1);
    }

    $config = Yaml::parseFile($inputFile);
    if(!is_array($config)) {
        fwrite(STDERR, "Error: Failed to parse {$inputFile}\n");
        exit(1);
    }

    if(isset($config['networks'])) {
        $warnings[] = "{$inputFile} already uses the networks array format, skipping.";
        continue;
    }

    if(isset($config['bots'])) {
        $networkConfig = $config;
        unset($networkConfig['bots']);
        $networkConfig['name'] = $networkName;
        $networkConfig['bots'] = $config['bots'];
    } else {
        if(!isset($config['name']) || !isset($config['server'])) {
            fwrite(STDERR, "Error: {$inputFile} does not appear to be a valid artconfig (missing name/server).\n");
            exit(1);
        }
        $botEntry = [];
        foreach($botKeys as $key) {
            if(array_key_exists($key, $config)) {
                $botEntry[$key] = $config[$key];
                unset($config[$key]);
            }
        }
        $config['name'] = $networkName;
        $config['bots'] = [$botEntry];
        $networkConfig = $config;
    }

    $inputDir = realpath(dirname($inputFile));
    foreach($dbKeys as $dbKey) {
        if(!isset($networkConfig[$dbKey]))
            continue;
        $dbPath = $networkConfig[$dbKey];
        if($dbPath[0] !== '/')
            $dbPath = $inputDir . '/' . $dbPath;
        $resolved = realpath($dbPath);
        if($resolved === false) {
            $warnings[] = "DB file not found on disk: {$dbPath} (referenced by {$networkName}/{$dbKey})";
            $resolved = $dbPath;
        }
        if(!isset($dbTracker[$resolved]))
            $dbTracker[$resolved] = [];
        $dbTracker[$resolved][] = $networkName;
        $dbKeyMap[$resolved] = $dbKey;
    }

    $existingNetworks[$networkName] = $networkConfig;
    $networksAdded[] = $networkName;
}

$existingDbPaths = [];
foreach($existingNetworks as $name => $net) {
    foreach($dbKeys as $dbKey) {
        if(!isset($net[$dbKey]))
            continue;
        $dbPath = $net[$dbKey];
        if(strpos($dbPath, 'db/') === 0 || strpos($dbPath, 'db\\') === 0) {
            $existingDbPaths[$name] = $dbPath;
            continue;
        }
        if($dbPath[0] !== '/')
            $dbPath = realpath(dirname($outputFile)) . '/' . $dbPath;
        $resolved = realpath($dbPath);
        if($resolved === false)
            continue;
        if(!isset($dbTracker[$resolved]))
            $dbTracker[$resolved] = [];
        if(!in_array($name, $dbTracker[$resolved]))
            $dbTracker[$resolved][] = $name;
        $dbKeyMap[$resolved] = $dbKey;
    }
}

$dbDir = dirname($outputFile) . '/db';
$dbCopyOps = [];
foreach($dbTracker as $sourcePath => $networkNames) {
    $networkNames = array_unique($networkNames);
    sort($networkNames);
    $dbKey = $dbKeyMap[$sourcePath] ?? 'quotedb';
    $type = str_replace('db', '', $dbKey);
    $prefix = implode('_', $networkNames);
    $targetName = "{$prefix}_{$type}.db";
    $targetPath = $dbDir . '/' . $targetName;

    $sourceExists = file_exists($sourcePath);

    if(file_exists($targetPath)) {
        if($sourceExists && md5_file($sourcePath) === md5_file($targetPath)) {
            $warnings[] = "DB file {$targetName} already exists with identical content, skipping copy.";
        } else {
            fwrite(STDERR, "Error: DB target {$targetPath} already exists with different content. Refusing to overwrite.\n");
            exit(1);
        }
    } else {
        if(!$sourceExists) {
            $warnings[] = "Source DB file not found: {$sourcePath} (referenced by " . implode(', ', $networkNames) . ")";
            continue;
        }
        $dbCopyOps[] = ['source' => $sourcePath, 'target' => $targetPath, 'name' => $targetName];
    }

    foreach($networkNames as $netName) {
        if(isset($existingNetworks[$netName]) && !isset($existingDbPaths[$netName]))
            $existingNetworks[$netName][$dbKey] = "db/{$targetName}";
    }
}

if(!empty($dbCopyOps)) {
    if(!is_dir($dbDir))
        mkdir($dbDir, 0755, true);
    foreach($dbCopyOps as $op) {
        if(!copy($op['source'], $op['target'])) {
            fwrite(STDERR, "Error: Failed to copy {$op['source']} -> {$op['target']}\n");
            exit(1);
        }
    }
}

$newConfig = ['networks' => array_values($existingNetworks)];
$yaml = Yaml::dump($newConfig, 4, 2);
file_put_contents($outputFile, $yaml);

echo "Migrated " . count($networksAdded) . " network(s) -> {$outputFile}\n";
foreach($inputs as $input)
    echo "  {$input['name']}: {$input['file']}\n";
echo "Total networks in output: " . count($existingNetworks) . "\n";
foreach($dbCopyOps as $op)
    echo "  Copied DB: {$op['name']}\n";
foreach($warnings as $w)
    echo "  Warning: {$w}\n";
