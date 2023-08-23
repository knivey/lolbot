<?php

namespace scripts\codesand;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Desc;
use knivey\cmdr\attributes\Syntax;
use League\Uri\UriString;
use Symfony\Component\Yaml\Yaml;

function getRun($ep, $code, $extraParam = '') {
    global $config;
    $maxlines = $config['codesand_maxlines'] ?? 10;
    $csConfig = Yaml::parseFile(__DIR__.'/config.yaml');
    $ep = "/run/$ep?maxlines=$maxlines{$extraParam}";
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
        return ["\2Server Error:\2 " . substr($error, 0, strpos($error, "\n"))];
    }

    $output = json_decode($body, true);
    if(!is_array($output)) {
        echo "codesand $ep returned non array:\n";
        var_dump($output);
        return ["something went badly"];
    }
    return $output;
}

function canRun($args): bool {
    global $nicks, $config;
    if(!($config['codesand'] ?? false)) {
        return false;
    }
    if(isset($config['codesandMinAccess'])) {
        if(!is_string($config['codesandMinAccess']) ||
            strlen($config['codesandMinAccess']) > 1 ||
            !str_contains('~&@%+', $config['codesandMinAccess'])
        ) {
            echo "codesandMinAccess configured incorrectly, must be one of ~&@%+\n";
            return false;
        }
        switch($config['codesandMinAccess']) {
            case '~':
                return $nicks->isOwner($args->nick, $args->channel);
            case '&':
                return $nicks->isAdminOrHigher($args->nick, $args->channel);
            case '@':
                return $nicks->isOpOrHigher($args->nick, $args->channel);
            case '%':
                return $nicks->isHalfOpOrHigher($args->nick, $args->channel);
            case '+':
                return $nicks->isVoiceOrHigher($args->nick, $args->channel);
        }
    }
    return true;
}

#[Cmd("php")]
#[Syntax("<code>...")]
#[CallWrap("\Amp\asyncCall")]
function runPHP($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
    if(!canRun($args)) {
        return;
    }
    $output = yield from getRun("php", $cmdArgs['code']);
    yield sendOut($bot, $args->chan, $output);
}

#[Cmd("bash")]
#[Syntax("<code>...")]
#[CallWrap("\Amp\asyncCall")]
function runBash($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
    if(!canRun($args)) {
        return;
    }
    $output = yield from getRun("bash", $cmdArgs['code']);
    yield sendOut($bot, $args->chan, $output);
}

#[Cmd("py3", "py", "python", "python3")]
#[Syntax("<code>...")]
#[CallWrap("\Amp\asyncCall")]
function runPy3($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
    if(!canRun($args)) {
        return;
    }
    $output = yield from getRun("python3", $cmdArgs['code']);
    yield sendOut($bot, $args->chan, $output);
}

#[Cmd("py2", "python2")]
#[Syntax("<code>...")]
#[CallWrap("\Amp\asyncCall")]
function runPy2($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
    if(!canRun($args)) {
        return;
    }
    $output = yield from getRun("python2", $cmdArgs['code']);
    yield sendOut($bot, $args->chan, $output);
}

#[Cmd("perl")]
#[Syntax("<code>...")]
#[CallWrap("\Amp\asyncCall")]
function runPerl($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
    if(!canRun($args)) {
        return;
    }
    $output = yield from getRun("perl", $cmdArgs['code']);
    yield sendOut($bot, $args->chan, $output);
}

#[Cmd("java")]
#[Syntax("<code>...")]
#[CallWrap("\Amp\asyncCall")]
function runJava($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
    if(!canRun($args)) {
        return;
    }
    $output = yield from getRun("java", $cmdArgs['code']);
    yield sendOut($bot, $args->chan, $output);
}

/*
#[Cmd("fish-shell")]
#[Syntax("<code>...")]
#[CallWrap("\Amp\asyncCall")]
function runFish($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
    if(!canRun($args)) {
        return;
    }
    $output = yield from getRun("fish", $cmdArgs['code']);
    yield sendOut($bot, $args->chan, $output);
}
*/

#[Cmd("ruby")]
#[Syntax("<code>...")]
#[Desc("Run ruby code")]
#[CallWrap("\Amp\asyncCall")]
function runRuby($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
    if(!canRun($args)) {
        return;
    }
    $output = yield from getRun("ruby", $cmdArgs['code']);
    yield sendOut($bot, $args->chan, $output);
}

#[Cmd("c", "tcc")]
#[Desc("Run C code using tcc compiler")]
#[Syntax("<code>...")]
#[CallWrap("\Amp\asyncCall")]
function runTcc($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
    if(!canRun($args)) {
        return;
    }
    $output = yield from getRun("tcc", $cmdArgs['code'], "&flags=-Wno-implicit-function-declaration");
    yield sendOut($bot, $args->chan, $output);
}

#[Cmd("gcc")]
#[Desc("Run ruby code using gcc compiler")]
#[Syntax("<code>...")]
#[CallWrap("\Amp\asyncCall")]
function runGcc($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
    if(!canRun($args)) {
        return;
    }
    $code = "#include <assert.h>
#include <complex.h>
#include <ctype.h>
#include <errno.h>
#include <fenv.h>
#include <float.h>
#include <inttypes.h>
#include <iso646.h>
#include <limits.h>
#include <locale.h>
#include <math.h>
#include <setjmp.h>
#include <signal.h>
#include <stdalign.h>
#include <stdarg.h>
#include <stdatomic.h>
#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <stdnoreturn.h>
#include <string.h>
#include <tgmath.h>
#include <threads.h>
#include <time.h>
#include <uchar.h>
#include <wchar.h>
#include <wctype.h>
{$cmdArgs['code']}";
    $output = yield from getRun("gcc", $code, "&flags=-Wno-implicit-function-declaration&flagsb=-lm");
    yield sendOut($bot, $args->chan, $output);
}

#[Cmd("tcl")]
#[Desc("Run tcl code")]
#[Syntax("<code>...")]
#[CallWrap("\Amp\asyncCall")]
function runTcl($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
    if(!canRun($args)) {
        return;
    }
    $output = yield from getRun("tcl", $cmdArgs['code']);
    yield sendOut($bot, $args->chan, $output);
}

#[Cmd("cpp", "g++")]
#[Desc("Run C++ code using g++")]
#[Syntax("<code>...")]
#[CallWrap("\Amp\asyncCall")]
function runGpp($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
    if(!canRun($args)) {
        return;
    }
    $code = "#include <bits/stdc++.h>
    #define FMT_HEADER_ONLY
    #include <fmt/core.h>
    #include <fmt/format.h>
    #include <fmt/format-inl.h>
    
using namespace std;
{$cmdArgs['code']}";
    //$gccArgs = urlencode("-Wfatal-errors -std=c++17");
    $gccArgs = urlencode("-Wfatal-errors");
    $output = yield from getRun("gpp", $code, "&flags=$gccArgs");
    yield sendOut($bot, $args->chan, $output);
}

function sendOut($bot, $chan, $data): \Amp\Promise {
    return \Amp\call(function () use ($bot, $chan, $data) {
        global $config;
        $data = array_map(function ($line) {
            if(str_starts_with($line, "OUT: "))
                $line = substr($line, strlen("OUT: "));
            if(str_starts_with($line, "ERR: "))
                $line = "\x0304". substr($line, strlen("OUT: "));
            return "\2\2$line";
        }, $data);
        if(isset($config['pump_host']) && isset($config['pump_key'])) {
            try {
                $client = HttpClientBuilder::buildDefault();
                $pumpchan = urlencode(substr($chan, 1));
                $pumpUrl = UriString::parse($config['pump_host']);
                $pumpUrl['path'] .= "/privmsg/$pumpchan";
                $pumpUrl['path'] = preg_replace("@/+@", "/", $pumpUrl['path']);
                $pumpUrl = UriString::build($pumpUrl);
    
                $request = new Request($pumpUrl, "POST");
                $request->setBody(implode("\n", $data));
                $request->setHeader('key', $config['pump_key']);
                /** @var Response $response */
                $response = yield $client->request($request);
                //$body = yield $response->getBody()->buffer();
                if ($response->getStatus() != 200) {
                    echo "Problem sending codesand to $pumpUrl response: {$response->getStatus()}\n";
                    $bot->pm($chan, "Error: problem sending output to pump bots");
                }
            } catch (\Exception $e) {
                $bot->pm($chan, "Error: problem sending output to pump bots");
                echo "Problem sending codesand to pumpers\n";
                echo $e;
                return;
            }
        } else {
            foreach ($data as $line) {
                $bot->pm($chan, $line);
            }
        }
    });
}
