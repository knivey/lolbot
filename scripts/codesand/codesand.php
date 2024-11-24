<?php

namespace scripts\codesand;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Process\Process;
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
        try {
            $csConfig = Yaml::parseFile(__DIR__ . '/config.yaml');
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            return ["codesand not configured"];
        }
        $ep = "/run/$ep?maxlines=$maxlines{$extraParam}";
        //for now put it in a query until api change
        if($stateData != '')
            $ep .= "&state=" . base64_encode($stateData);
        try {
            $client = HttpClientBuilder::buildDefault();
            $request = new Request("{$csConfig['server']}$ep", 'POST');
            $request->setInactivityTimeout(15000);
            $request->setHeader("key", $csConfig["key"]);
            $request->setBody($code); // html chars and such seem to not be a problem
            $response = $client->request($request);
            $body = $response->getBody()->buffer();
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
    function runPHP($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (!$this->canRun($args)) {
            return;
        }
        $output = $this->getRun("php", $cmdArgs['code'], stateData: $this->createStateJson($args, $bot));
        $this->sendOut($bot, $args->chan, $output);
    }

    #[Cmd("bash")]
    #[Syntax("<code>...")]
    function runBash($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (!$this->canRun($args)) {
            return;
        }
        $output = $this->getRun("bash", $cmdArgs['code']);
        $this->sendOut($bot, $args->chan, $output);
    }

    #[Cmd("py3", "py", "python", "python3")]
    #[Syntax("<code>...")]
    function runPy3($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (!$this->canRun($args)) {
            return;
        }
        $output = $this->getRun("python3", $cmdArgs['code']);
        $this->sendOut($bot, $args->chan, $output);
    }

    #[Cmd("py2", "python2")]
    #[Syntax("<code>...")]
    function runPy2($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (!$this->canRun($args)) {
            return;
        }
        $output = $this->getRun("python2", $cmdArgs['code']);
        $this->sendOut($bot, $args->chan, $output);
    }

    #[Cmd("perl")]
    #[Syntax("<code>...")]
    function runPerl($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (!$this->canRun($args)) {
            return;
        }
        $output = $this->getRun("perl", $cmdArgs['code']);
        $this->sendOut($bot, $args->chan, $output);
    }

    #[Cmd("java")]
    #[Syntax("<code>...")]
    function runJava($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (!$this->canRun($args)) {
            return;
        }
        $output = $this->getRun("java", $cmdArgs['code']);
        $this->sendOut($bot, $args->chan, $output);
    }

    /*
    #[Cmd("fish-shell")]
    #[Syntax("<code>...")]
    function runFish($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
        if(!canRun($args)) {
            return;
        }
        $output = $this->getRun("fish", $cmdArgs['code']);
        $this->sendOut($bot, $args->chan, $output);
    }
    */

    #[Cmd("ruby")]
    #[Syntax("<code>...")]
    #[Desc("Run ruby code")]
    function runRuby($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (!$this->canRun($args)) {
            return;
        }
        $output = $this->getRun("ruby", $cmdArgs['code']);
        $this->sendOut($bot, $args->chan, $output);
    }

    #[Cmd("c", "tcc")]
    #[Desc("Run C code using tcc compiler")]
    #[Syntax("<code>...")]
    function runTcc($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (!$this->canRun($args)) {
            return;
        }
        $output = $this->getRun("tcc", $cmdArgs['code'], "&flags=-Wno-implicit-function-declaration");
        $this->sendOut($bot, $args->chan, $output);
    }

    #[Cmd("go", "golang")]
    #[Desc("Run go code")]
    #[Syntax("<code>...")]
    function runGolang($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (!$this->canRun($args)) {
            return;
        }
        $tmpfile = tempnam(sys_get_temp_dir(), 'codesand') . '.go';
        file_put_contents($tmpfile, "package main\n\n{$cmdArgs['code']}\n");
        Process::start("gofmt -w $tmpfile")->join();
        Process::start("goimports -w $tmpfile")->join();
        $code = file_get_contents($tmpfile);
        unlink($tmpfile);
        $output = $this->getRun("golang", $code);
        $this->sendOut($bot, $args->chan, $output);
    }

    #[Cmd("js", "javascript")]
    #[Desc("Run javascript code")]
    #[Syntax("<code>...")]
    function runJavascript($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (!$this->canRun($args)) {
            return;
        }
        $output = $this->getRun("javascript", $cmdArgs['code']);
        $this->sendOut($bot, $args->chan, $output);
    }

    #[Cmd("gcc")]
    #[Desc("Run ruby code using gcc compiler")]
    #[Syntax("<code>...")]
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
        $output = $this->getRun("gcc", $code, "&flags=-Wno-implicit-function-declaration&flagsb=-lm");
        $this->sendOut($bot, $args->chan, $output);
    }

    #[Cmd("tcl")]
    #[Desc("Run tcl code")]
    #[Syntax("<code>...")]
    function runTcl($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        if (!$this->canRun($args)) {
            return;
        }
        $output = $this->getRun("tcl", $cmdArgs['code']);
        $this->sendOut($bot, $args->chan, $output);
    }

    #[Cmd("cpp", "g++")]
    #[Desc("Run C++ code using g++")]
    #[Syntax("<code>...")]
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
        $output = $this->getRun("gpp", $code, "&flags=$gccArgs");
        $this->sendOut($bot, $args->chan, $output);
    }

    function sendOut(\Irc\Client $bot, string $chan, array $data): void
    {
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
                $response = $client->request($request);
                //$body = $response->getBody()->buffer();
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
    }
}