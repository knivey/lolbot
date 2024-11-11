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
use scripts\script_base;
use Symfony\Component\Yaml\Yaml;

class codesand extends script_base
{
    function createStateJson($args, \Irc\Client $bot) {
        $channel = $this->chans->getChan($args->chan);
        return json_encode([
            'caller' => [
                'chan' => $args->chan,
                'nick' => $args->nick,
                'host' => $args->host,
            ],
            'chan' => [
                'nicks' => $channel->nicks,
            ],
        ]);
    }

    function getRun($ep, $code, $extraParam = '', $stateData = '')
    {
        global $config;
        $maxlines = $config['bots'][$this->bot->id]['codesand_maxlines'] ?? 10;
        $csConfig = Yaml::parseFile(__DIR__ . '/config.yaml');
        $ep = "/run/$ep?maxlines=$maxlines{$extraParam}";
        //for now put it in a query until api change
        if($stateData != '')
            $ep .= "&state=" . base64_encode($stateData);
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
        if (!is_array($output)) {
            echo "codesand $ep returned non array:\n";
            var_dump($output);
            return ["something went badly"];
        }
        return $output;
    }

    function canRun($args): bool
    {
        global $config;
        if (!($config['bots'][$this->bot->id]['codesand'] ?? false)) {
            return false;
        }
        if (isset($config['bots'][$this->bot->id]['codesandMinAccess'])) {
            if (!is_string($config['bots'][$this->bot->id]['codesandMinAccess']) ||
                strlen($config['bots'][$this->bot->id]['codesandMinAccess']) > 1 ||
                !str_contains('~&@%+', $config['bots'][$this->bot->id]['codesandMinAccess'])
            ) {
                echo "codesandMinAccess configured incorrectly, must be one of ~&@%+\n";
                return false;
            }
            switch ($config['bots'][$this->bot->id]['codesandMinAccess']) {
                case '~':
                    return $this->nicks->isOwner($args->nick, $args->channel);
                case '&':
                    return $this->nicks->isAdminOrHigher($args->nick, $args->channel);
                case '@':
                    return $this->nicks->isOpOrHigher($args->nick, $args->channel);
                case '%':
                    return $this->nicks->isHalfOpOrHigher($args->nick, $args->channel);
                case '+':
                    return $this->nicks->isVoiceOrHigher($args->nick, $args->channel);
            }
        }
        return true;
    }

    #[Cmd("php")]
    #[Syntax("<code>...")]
    #[CallWrap("\Amp\asyncCall")]
    function runPHP($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (!$this->canRun($args)) {
            return;
        }
        $output = yield from $this->getRun("php", $cmdArgs['code'], stateData: $this->createStateJson($args, $bot));
        yield $this->sendOut($bot, $args->chan, $output);
    }

    #[Cmd("bash")]
    #[Syntax("<code>...")]
    #[CallWrap("\Amp\asyncCall")]
    function runBash($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (!$this->canRun($args)) {
            return;
        }
        $output = yield from $this->getRun("bash", $cmdArgs['code']);
        yield $this->sendOut($bot, $args->chan, $output);
    }

    #[Cmd("py3", "py", "python", "python3")]
    #[Syntax("<code>...")]
    #[CallWrap("\Amp\asyncCall")]
    function runPy3($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (!$this->canRun($args)) {
            return;
        }
        $output = yield from $this->getRun("python3", $cmdArgs['code']);
        yield $this->sendOut($bot, $args->chan, $output);
    }

    #[Cmd("py2", "python2")]
    #[Syntax("<code>...")]
    #[CallWrap("\Amp\asyncCall")]
    function runPy2($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (!$this->canRun($args)) {
            return;
        }
        $output = yield from $this->getRun("python2", $cmdArgs['code']);
        yield $this->sendOut($bot, $args->chan, $output);
    }

    #[Cmd("perl")]
    #[Syntax("<code>...")]
    #[CallWrap("\Amp\asyncCall")]
    function runPerl($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (!$this->canRun($args)) {
            return;
        }
        $output = yield from $this->getRun("perl", $cmdArgs['code']);
        yield $this->sendOut($bot, $args->chan, $output);
    }

    #[Cmd("java")]
    #[Syntax("<code>...")]
    #[CallWrap("\Amp\asyncCall")]
    function runJava($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (!$this->canRun($args)) {
            return;
        }
        $output = yield from $this->getRun("java", $cmdArgs['code']);
        yield $this->sendOut($bot, $args->chan, $output);
    }

    /*
    #[Cmd("fish-shell")]
    #[Syntax("<code>...")]
    #[CallWrap("\Amp\asyncCall")]
    function runFish($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
        if(!canRun($args)) {
            return;
        }
        $output = yield from $this->getRun("fish", $cmdArgs['code']);
        yield $this->sendOut($bot, $args->chan, $output);
    }
    */

    #[Cmd("ruby")]
    #[Syntax("<code>...")]
    #[Desc("Run ruby code")]
    #[CallWrap("\Amp\asyncCall")]
    function runRuby($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (!$this->canRun($args)) {
            return;
        }
        $output = yield from $this->getRun("ruby", $cmdArgs['code']);
        yield $this->sendOut($bot, $args->chan, $output);
    }

    #[Cmd("c", "tcc")]
    #[Desc("Run C code using tcc compiler")]
    #[Syntax("<code>...")]
    #[CallWrap("\Amp\asyncCall")]
    function runTcc($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (!$this->canRun($args)) {
            return;
        }
        $output = yield from $this->getRun("tcc", $cmdArgs['code'], "&flags=-Wno-implicit-function-declaration");
        yield $this->sendOut($bot, $args->chan, $output);
    }

    #[Cmd("go", "golang")]
    #[Desc("Run go code")]
    #[Syntax("<code>...")]
    #[CallWrap("\Amp\asyncCall")]
    function runGolang($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (!$this->canRun($args)) {
            return;
        }
        $code = "package main\nimport \"fmt\"\n{$cmdArgs['code']}\n";
        $output = yield from $this->getRun("golang", $code);
        yield $this->sendOut($bot, $args->chan, $output);
    }

    #[Cmd("js", "javascript")]
    #[Desc("Run javascript code")]
    #[Syntax("<code>...")]
    #[CallWrap("\Amp\asyncCall")]
    function runJavascript($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (!$this->canRun($args)) {
            return;
        }
        $output = yield from $this->getRun("javascript", $cmdArgs['code']);
        yield $this->sendOut($bot, $args->chan, $output);
    }

    #[Cmd("gcc")]
    #[Desc("Run ruby code using gcc compiler")]
    #[Syntax("<code>...")]
    #[CallWrap("\Amp\asyncCall")]
    function runGcc($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (!$this->canRun($args)) {
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
        $output = yield from $this->getRun("gcc", $code, "&flags=-Wno-implicit-function-declaration&flagsb=-lm");
        yield $this->sendOut($bot, $args->chan, $output);
    }

    #[Cmd("tcl")]
    #[Desc("Run tcl code")]
    #[Syntax("<code>...")]
    #[CallWrap("\Amp\asyncCall")]
    function runTcl($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (!$this->canRun($args)) {
            return;
        }
        $output = yield from $this->getRun("tcl", $cmdArgs['code']);
        yield $this->sendOut($bot, $args->chan, $output);
    }

    #[Cmd("cpp", "g++")]
    #[Desc("Run C++ code using g++")]
    #[Syntax("<code>...")]
    #[CallWrap("\Amp\asyncCall")]
    function runGpp($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (!$this->canRun($args)) {
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
        $output = yield from $this->getRun("gpp", $code, "&flags=$gccArgs");
        yield $this->sendOut($bot, $args->chan, $output);
    }

    function sendOut($bot, $chan, $data): \Amp\Promise
    {
        return \Amp\call(function () use ($bot, $chan, $data) {
            global $config;
            $data = array_map(function ($line) {
                if (str_starts_with($line, "OUT: "))
                    $line = substr($line, strlen("OUT: "));
                if (str_starts_with($line, "ERR: "))
                    $line = "\x0304" . substr($line, strlen("OUT: "));
                return "\2\2$line";
            }, $data);
            if (isset($config['bots'][$this->bot->id]['pump_host']) && isset($config['bots'][$this->bot->id]['pump_key'])) {
                try {
                    $client = HttpClientBuilder::buildDefault();
                    $pumpchan = urlencode(substr($chan, 1));
                    $pumpUrl = UriString::parse($config['bots'][$this->bot->id]['pump_host']);
                    $pumpUrl['path'] .= "/privmsg/$pumpchan";
                    $pumpUrl['path'] = preg_replace("@/+@", "/", $pumpUrl['path']);
                    $pumpUrl = UriString::build($pumpUrl);

                    $request = new Request($pumpUrl, "POST");
                    $request->setBody(implode("\n", $data));
                    $request->setHeader('key', $config['bots'][$this->bot->id]['pump_key']);
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
}