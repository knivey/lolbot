#!/usr/bin/env php
<?php

if(php_sapi_name() !== 'cli')
    die("This script must be run from the command line\n");

use Symfony\Component\Yaml\Yaml;

require_once 'vendor/autoload.php';

$botKeys = ['name', 'server', 'port', 'ssl', 'throttle', 'bindIp', 'pass'];

$inputFile = $argv[1] ?? 'artconfig.yaml';
$outputFile = $argv[2] ?? 'multiartconfig.yaml';

if(!file_exists($inputFile) || !is_file($inputFile)) {
    fwrite(STDERR, "Usage: ".__FILE__." [input.yaml] [output.yaml]\n  ({$inputFile} does not exist or is not a file)\n");
    exit(1);
}

if(file_exists($outputFile) && !isset($argv[2])) {
    fwrite(STDERR, "Output file {$outputFile} already exists. Specify an output path as the second argument to overwrite.\n");
    exit(1);
}

$config = Yaml::parseFile($inputFile);
if(!is_array($config)) {
    fwrite(STDERR, "Failed to parse {$inputFile}\n");
    exit(1);
}

if(isset($config['bots'])) {
    fwrite(STDERR, "{$inputFile} already uses the bots array format, nothing to do.\n");
    exit(0);
}

if(!isset($config['name']) || !isset($config['server'])) {
    fwrite(STDERR, "{$inputFile} does not appear to be a valid artconfig (missing name/server).\n");
    exit(1);
}

$botEntry = [];
foreach ($botKeys as $key) {
    if(array_key_exists($key, $config)) {
        $botEntry[$key] = $config[$key];
        unset($config[$key]);
    }
}

$newConfig = [];
$newConfig['bots'] = [$botEntry];
foreach ($config as $key => $value) {
    $newConfig[$key] = $value;
}

$yaml = Yaml::dump($newConfig, 4, 2);
file_put_contents($outputFile, $yaml);

echo "Migrated {$inputFile} -> {$outputFile}\n";
echo "Bot entry created from keys: " . implode(', ', array_keys($botEntry)) . "\n";
