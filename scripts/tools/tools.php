<?php
namespace scripts\tools;
require_once 'library/async_get_contents.php';

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Desc;
use knivey\cmdr\attributes\Syntax;
use knivey\irctools;
use scripts\script_base;

class tools extends script_base
{
    #[Cmd("define", "dictionary")]
    #[Syntax('<query>...')]
    #[Desc("lookup definitions from api.dictionaryapi.dev")]
    function dictionary($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        $word = rawurlencode($cmdArgs['query']);
        try {
            $body = \async_get_contents("https://api.dictionaryapi.dev/api/v2/entries/en/$word");
        } catch (\async_get_exception $e) {
            if ($e->getCode() == 404)
                $bot->msg($args->chan, "define: no definitions found");
            else
                $bot->msg($args->chan, "define error: {$e->getIRCMsg()}");
            return;
        } catch (\Exception $error) {
            echo $error->getMessage();
            $bot->pm($args->chan, "define error: {$error->getMessage()}");
            return;
        }
        $json = json_decode($body)[0];
        $out = "Define: {$json->word}";
        if (isset($json->phonetics[0]->text))
            $out .= " {$json->phonetics[0]->text}";
        $out .= " - ";
        foreach ($json->meanings as $m) {
            $out .= "({$m->partOfSpeech}) {$m->definitions[0]->definition}";
            if (isset($m->definitions[0]->example))
                $out .= " Ex: {$m->definitions[0]->example}";
            $out .= " | ";
        }
        $out = rtrim($out, " |");
        $bot->pm($args->chan, $out);
    }

    function getDnsType($type)
    {
        $rc = new \ReflectionClass(\Amp\Dns\DnsRecord::class);
        $ret = false;
        foreach (array_keys($rc->getConstants()) as $t) {
            if (strtolower($t) == strtolower($type))
                $ret = $t;
        }
        if ($ret === false) {
            return false;
        }
        return $rc->getConstant($ret);
    }

    #[Cmd("dns", "resolve")]
    #[Syntax('<query> [type]')]
    #[Desc("lookup dns records default is A, AAAA")]
    function dns($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        global $config;
        try {
            if (isset($cmdArgs['type'])) {
                $type = $this->getDnsType($cmdArgs['type']);
                if ($type === false) {
                    $bot->pm($args->chan, "Unsupported record type");
                    return;
                }

                $records = \Amp\Dns\query($cmdArgs['query'], $type);
            } else {
                $records = \Amp\Dns\resolve($cmdArgs['query']);
            }
            $recs = [];
            foreach ($records as $r) {
                $recs[] = $r->getValue();
            }
            if (count($recs) == 0)
                $recs = 'No records';
            else
                $recs = implode(' | ', $recs);
            $bot->pm($args->chan, "DNS for {$cmdArgs['query']} " . ($cmdArgs['type'] ?? 'A, AAAA') . " - $recs");
        } catch (\Exception $e) {
            $bot->pm($args->chan, "DNS Exception {$e->getMessage()}");
        }
    }

    #[Cmd("choice", "choose")]
    #[Syntax('<stuff>...')]
    #[Desc("let tthe bot make the hard desicions, choices separated by eiher: or , |")]
    function choice($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        $opts = preg_split("/[,|]| +or( +|$)/", $cmdArgs['stuff']);
        if ($opts === false) {
            $bot->msg($args->chan, "i can't seem to decide :(");
            return;
        }
        $opts = array_filter(array_map('trim', $opts));
        if (count($opts) < 2) {
            $bot->msg($args->chan, "gimme more than one option separated by: , or |");
            return;
        }
        $bot->msg($args->chan, "I choose: " . $opts[array_rand($opts)]);
    }

    #[Cmd("domaincheck", "dc")]
    #[Syntax('<domain>')]
    #[Desc("check namecheap to see if a domain can be registered")]
    function domaincheck($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        global $config;
        $key = $config['namecheap_key'] ?? false;
        $user = $config['namecheap_user'] ?? false;
        if (!$key || !$user) {
            $bot->pm($args->chan, "namecheap key or user not set on config");
            return;
        }
        $domain = $cmdArgs['domain'];
        $domain = urlencode($domain);
        //ClientIP 127.0.0.1 seems to work weird for API to want this..
        $url = "https://api.namecheap.com/xml.response?ApiUser=$user&ApiKey=$key&UserName=$user&Command=namecheap.domains.check&ClientIp=127.0.0.1&DomainList=$domain";
        try {
            $body = async_get_contents($url);
            $xml = simplexml_load_string($body);
            //var_dump($xml);
            if ($xml === false)
                throw new \Exception("Couldn't parse response as XML");
            if (isset($xml->Errors->Error)) {
                var_dump($xml);
                throw new \Exception($xml->Errors->Error);
            }
            if (!isset($xml->CommandResponse))
                throw new \Exception("API didnt include response");
            if ($xml->CommandResponse->DomainCheckResult["Available"] == "true") {
                $bot->pm($args->chan, "\2DomainCheck:\2 ({$cmdArgs['domain']}) That domain is available for register!");
            } else {
                $bot->pm($args->chan, "\2DomainCheck:\2 ({$cmdArgs['domain']}) That domain is already taken :(");
            }
        } catch (\async_get_exception $error) {
            // keys should only be leaked in this exception message, other one is from xml error message
            $bot->pm($args->chan, "\2DomainCheck:\2 ({$cmdArgs['domain']}) Connection error :( try again later");
            echo $error->getMessage();
        } catch (\Exception $error) {
            $bot->pm($args->chan, "\2DomainCheck:\2 ({$cmdArgs['domain']}) {$error->getMessage()}");
            echo $error->getMessage();
        }
    }

    function hash32($name)
    {
        $n = 42;
        $r = strlen($name);
        for ($o = 0; $o < $r; $o++) {
            $n = (($n << 5) - $n + ord($name[$o])) & 0xffffffff;
        }
        if ($n & 0x80000000) {
            $n = -((~$n & 0xFFFFFFFF) + 1);
        }
        return $n;
    }

    #[Cmd("tldcheck", "tc")]
    #[Syntax('<domain>')]
    function tldcheck($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        $domain = $cmdArgs['domain'];

        if (!preg_match('/^[a-zA-Z0-9-]+$/', $domain) || strlen($domain) > 25) {
            $bot->pm($args->chan, "grow up");
            return;
        }

        if ($this->server->throttle) {
            $bot->msg($args->chan, "output too big for this network :(");
            return;
        }

        $bot->pm($args->chan, "checking available tlds for {$domain}.*");

        $hash = $this->hash32($domain);

        $headers = [
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:109.0) Gecko/20100101 Firefox/113.0',
            'Accept' => 'application/x-ndjson',
            'Accept-Language' => 'en-US,en;q=0.5',
            'Accept-Encoding' => 'identity', // amphp http-client fails to handle compression from this site
            'Referer' => 'https://instantdomainsearch.com/domain/extensions?q=' . $domain,
            'Alt-Used' => 'instantdomainsearch.com',
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-origin',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'no-cache',
        ];

        $urls = [
            "https://instantdomainsearch.com/services/dns-names/$domain?hash=$hash&limit=1000&tldTags=all",
            "https://instantdomainsearch.com/services/zone-names/$domain?hash=$hash&limit=1000&tldTags=all"
        ];

        $lines = [];
        $client = HttpClientBuilder::buildDefault();
        foreach($urls as $url) {
            try {
                $request = new Request($url);
                foreach($headers as $header => $value)
                    $request->setHeader($header, $value);
                $response = $client->request($request);
                $result = $response->getBody()->buffer();
            } catch (\Exception $e) {
                $result = '';
                echo $e->getMessage() . "\n";
                dumpRequestTrace($request);
                if(isset($response))
                    dumpResponseTrace($response);
            }
            $results = explode("\n", $result);
            $lines = array_merge($lines, $results);
        }


        // funky handling of ndjson response - https://www.pragmanotdogma.com/26-handling-ndjson-with-javascript-and-php
        $json = array_map('json_decode', $lines);

        $msgString = '';
        $c = 0;
        foreach ($json as $tld) {
            if (!isset($tld->isRegistered) || !isset($tld->tld)) {
                continue;
            }

            if ($tld->isRegistered == false) {
                $string = str_pad("\x033 [✓] $domain.$tld->tld", 40, " ", STR_PAD_RIGHT);
            } else {
                $string = str_pad("\x034 [☓] $domain.$tld->tld", 40, " ", STR_PAD_RIGHT);
            }

            $msgString .= $string;

            if ($c % 3 == 0) {
                $bot->msg($args->chan, trim($msgString));
                $msgString = '';
            }

            $c++;
        }

    }

    #[Cmd("affirm")]
    #[Syntax('[nick]...')]
    function affirm($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        try {
            $body = async_get_contents("https://www.affirmations.dev");
            $j = json_decode($body);
            if (!isset($j->affirmation))
                throw new \Exception("affirmation not set: $body\n");
            $a = $j->affirmation;
            if (isset($cmdArgs['nick'])) {
                $a = "{$cmdArgs['nick']}, $a";
            }
            $bot->msg($args->chan, $a);
        } catch (\Exception $error) {
            echo $error->getMessage();
        }
    }

    #[Cmd("rainbow", "rnb", "nes")]
    #[Syntax('<input>...')]
    function nes($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        $text = str_replace('\n', "\n", $cmdArgs[0]);
        $text = irctools\diagRainbow($text);
        foreach (explode("\n", $text) as $line) {
            $bot->pm($args->chan, $line);
        }
    }
}

// taken from amphp example helpers
use \Amp\Http\Client\Response;
use \Amp\Http\Http1\Rfc7230;
function dumpRequestTrace(Request $request): void
{
    \printf(
        "%s %s HTTP/%s\r\n",
        $request->getMethod(),
        (string) $request->getUri(),
        \implode('+', $request->getProtocolVersions())
    );

    /** @noinspection PhpUnhandledExceptionInspection */
    print Rfc7230::formatHeaders($request->getHeaders()) . "\r\n\r\n";
}

function dumpResponseTrace(Response $response): void
{
    \printf(
        "HTTP/%s %d %s\r\n",
        $response->getProtocolVersion(),
        $response->getStatus(),
        $response->getReason()
    );

    /** @noinspection PhpUnhandledExceptionInspection */
    print Rfc7230::formatHeaders($response->getHeaders()) . "\r\n\r\n";
}