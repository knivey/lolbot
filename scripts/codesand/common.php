<?php

namespace codesand;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;
use Symfony\Component\Yaml\Yaml;


#[Cmd("php")]
#[Syntax("<code>...")]
#[CallWrap("\Amp\asyncCall")]
function runPHP($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    global $config;
    if(!($config['codesand'] ?? false)) {
        return;
    }
    $csConfig = Yaml::parseFile(__DIR__.'/config.yaml');

    try {
        $client = HttpClientBuilder::buildDefault();
        /** @var Response $response */
        $request = new Request("{$csConfig['server']}/run/php", 'POST');
        $request->setInactivityTimeout(15000);
        $request->setHeader("key", $csConfig["key"]);
        $request->setBody($req->args['code']);
        $response = yield $client->request($request);
        $body = yield $response->getBody()->buffer();
        if ($response->getStatus() != 200) {
            var_dump($body);
            // Just in case its huge or some garbage
            $body = str_replace(["\n", "\r"], '', substr($body, 0, 200));
            $bot->pm($args->chan, "Error (" . $response->getStatus() . ") $body");
            return;
        }
    } catch (\Exception $error) {
        echo $error;
        $bot->pm($args->chan, "\2php:\2 " . substr($error, 0, strpos($error, "\n")));
        return;
    }

    $output = json_decode($body, true);

    if(!is_array($output)) {
        var_dump($output);
        $bot->pm($args->chan, "something went badly");
        return;
    }
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
    $csConfig = Yaml::parseFile(__DIR__.'/config.yaml');

    try {
        $client = HttpClientBuilder::buildDefault();
        /** @var Response $response */
        $request = new Request("{$csConfig['server']}/run/bash", 'POST');
        $request->setInactivityTimeout(15000);
        $request->setHeader("key", $csConfig["key"]);
        $request->setBody($req->args['code']);
        $response = yield $client->request($request);
        $body = yield $response->getBody()->buffer();
        if ($response->getStatus() != 200) {
            var_dump($body);
            // Just in case its huge or some garbage
            $body = str_replace(["\n", "\r"], '', substr($body, 0, 200));
            $bot->pm($args->chan, "Error (" . $response->getStatus() . ") $body");
            return;
        }
    } catch (\Exception $error) {
        echo $error;
        $bot->pm($args->chan, "\2bash:\2 " . substr($error, 0, strpos($error, "\n")));
        return;
    }

    $output = json_decode($body, true);

    if(!is_array($output)) {
        var_dump($output);
        $bot->pm($args->chan, "something went badly");
        return;
    }
    foreach ($output as $line)
        $bot->pm($args->chan, $line);
}