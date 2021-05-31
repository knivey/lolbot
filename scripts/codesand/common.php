<?php

namespace codesand;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;
use Symfony\Component\Yaml\Yaml;

function getRun($ep, $code) {
    $csConfig = Yaml::parseFile(__DIR__.'/config.yaml');

    try {
        $client = HttpClientBuilder::buildDefault();
        /** @var Response $response */
        $request = new Request("{$csConfig['server']}$ep", 'POST');
        $request->setInactivityTimeout(15000);
        $request->setHeader("key", $csConfig["key"]);
        $request->setBody($code); // html chars and such seem to not be a problem
        $response = yield $client->request($request);
        $body = yield $response->getBody()->buffer();
        if ($response->getStatus() != 200) {
            var_dump($body);
            // Just in case its huge or some garbage
            $body = str_replace(["\n", "\r"], '', substr($body, 0, 200));
            return ["Error (" . $response->getStatus() . ") $body"];
        }
    } catch (\Exception $error) {
        echo $error;
        return ["\2python3:\2 " . substr($error, 0, strpos($error, "\n"))];
    }

    $output = json_decode($body, true);
    if(!is_array($output)) {
        echo "codesand $ep returnen non array:\n";
        var_dump($output);
        return ["something went badly"];
    }
    return $output;
}

#[Cmd("php")]
#[Syntax("<code>...")]
#[CallWrap("\Amp\asyncCall")]
function runPHP($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    global $config;
    if(!($config['codesand'] ?? false)) {
        return;
    }
    $output = yield from getRun('/run/php', $req->args['code']);
    foreach ($output as $line)
        $bot->pm($args->chan, $line);
}

#[Cmd("bash")]
#[Syntax("<code>...")]
#[CallWrap("\Amp\asyncCall")]
function runBash($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    global $config;
    if(!($config['codesand'] ?? false)) {
        return;
    }

    $output = yield from getRun('/run/bash', $req->args['code']);
    foreach ($output as $line)
        $bot->pm($args->chan, $line);
}

#[Cmd("py3", "py", "python", "python3")]
#[Syntax("<code>...")]
#[CallWrap("\Amp\asyncCall")]
function runPy3($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    global $config;
    if(!($config['codesand'] ?? false)) {
        return;
    }
    $output = yield from getRun('/run/python3', $req->args['code']);
    foreach ($output as $line)
        $bot->pm($args->chan, $line);
}

#[Cmd("py2", "python2")]
#[Syntax("<code>...")]
#[CallWrap("\Amp\asyncCall")]
function runPy2($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    global $config;
    if(!($config['codesand'] ?? false)) {
        return;
    }
    $output = yield from getRun('/run/python2', $req->args['code']);
    foreach ($output as $line)
        $bot->pm($args->chan, $line);
}
