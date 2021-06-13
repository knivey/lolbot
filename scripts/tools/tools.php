<?php
namespace knivey\lolbot\tools;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Irc\Exception;
use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Options;
use knivey\cmdr\attributes\Syntax;
use knivey\irctools;

function getDnsType($type) {
    $rc = new \ReflectionClass(\Amp\Dns\Record::class);
    $ret = false;
    foreach(array_keys($rc->getConstants()) as $t) {
        if(strtolower($t) == strtolower($type))
            $ret = $t;
    }
    if($ret === false) {
        return false;
    }
    return $rc->getConstant($ret);
}

#[Cmd("dns", "resolve")]
#[Syntax('<query> [type]')]
#[CallWrap("Amp\asyncCall")]
function dns($args, \Irc\Client $bot, \knivey\cmdr\Request $req)
{
    global $config;
    try {
        if(isset($req->args['type'])) {
            $type = getDnsType($req->args['type']);
            if($type === false) {
                $bot->pm($args->chan, "Unsupported record type");
                return;
            }

            /** @var \Amp\Dns\Record[] $records */
            $records = yield \Amp\Dns\query($req->args['query'], $type);
        } else {
            /** @var \Amp\Dns\Record[] $records */
            $records = yield \Amp\Dns\resolve($req->args['query']);
        }
        $recs = [];
        foreach ($records as $r) {
            $recs[] = $r->getValue();
        }
        if(count($recs) == 0)
            $recs = 'No records';
        else
            $recs = implode(' | ', $recs);
        $bot->pm($args->chan, "DNS for {$req->args['query']} ".($req->args['type'] ?? 'A, AAAA')." - $recs");
    } catch (\Exception $e) {
        $bot->pm($args->chan, "DNS Exception {$e->getMessage()}");
    }
}

function simpleUrlAsync($url) {
    return \Amp\call(function () use ($url) {
        $client = HttpClientBuilder::buildDefault();
        $request = new Request($url);
        /** @var Response $response */
        $response = yield $client->request($request);
        $body = yield $response->getBody()->buffer();
        if ($response->getStatus() != 200) {
            var_dump($body);
            // Just in case its huge or some garbage
            $body = substr($body, 0, 200);
            $body = str_replace(["\n", "\r"], "", $body);
            throw new \Exception("Error (" . $response->getStatus() . ") $body");
        }
        return $body;
    });
}

#[Cmd("domaincheck")]
#[Syntax('<domain>')]
#[CallWrap("Amp\asyncCall")]
function domaincheck($args, \Irc\Client $bot, \knivey\cmdr\Request $req)
{
    global $config;
    $key = $config['namecheap_key'] ?? false;
    $user = $config['namecheap_user'] ?? false;
    if(!$key || !$user) {
        $bot->pm($args->chan, "namecheap key or user not set on config");
        return;
    }
    $domain = $req->args['domain'];
    $domain = urlencode($domain);
    //ClientIP 127.0.0.1 seems to work weird for API to want this..
    $url = "https://api.namecheap.com/xml.response?ApiUser=$user&ApiKey=$key&UserName=$user&Command=namecheap.domains.check&ClientIp=127.0.0.1&DomainList=$domain";
    try {
        $body = yield simpleUrlAsync($url);
        $xml = simplexml_load_string($body);
        var_dump($xml);
        if($xml === false)
            throw new \Exception("Couldn't parse response as XML");
        if(isset($xml->Errors->Error)) {
            var_dump($xml);
            throw new \Exception($xml->Errors->Error);
        }
        if(!isset($xml->CommandResponse))
            throw new \Exception("API didnt include response");
        if($xml->CommandResponse->DomainCheckResult["Available"] == "true") {
            $bot->pm($args->chan, "\2DomainCheck:\2 That domain is available for register!");
        } else {
            $bot->pm($args->chan, "\2DomainCheck:\2 That domain is already taken :(");
        }
    } catch (\Exception $error) {
        $bot->pm($args->chan, "\2DomainCheck Error:\2 " . substr($error->getMessage(), 0, 200));
    }
}

#[Cmd("rainbow", "rnb", "nes")]
#[Syntax('<input>...')]
function nes($args, \Irc\Client $bot, \knivey\cmdr\Request $req)
{
    $text = str_replace('\n', "\n", $req->args[0]);
    $text = irctools\diagRainbow($text);
    foreach(explode("\n", $text) as $line) {
        $bot->pm($args->chan, $line);
    }
}

#[Cmd("authname")]
#[Syntax('<nick>')]
#[CallWrap("Amp\asyncCall")]
function authname($args, \Irc\Client $bot, \knivey\cmdr\Request $req)
{
    $who = $req->args['nick'];
    try {
        $auth = yield getUserAuthServ($who, $bot);
    } catch(\Exception $e) {
        $bot->pm($args->chan, "Exception getting authserv account: $e");
        return;
    }
    if($auth == null) {
        $bot->pm($args->chan, "$who doesn't appear to be authed");
        return;
    }
    $bot->pm($args->chan, "$who authed to: $auth");
}

#[Cmd("chanaccess")]
#[Syntax('<nick>')]
#[CallWrap("Amp\asyncCall")]
function chanaccess($args, \Irc\Client $bot, \knivey\cmdr\Request $req)
{
    $who = $req->args['nick'];
    try {
        $access = yield getUserChanAccess($who, $args->chan, $bot);
    } catch(\Exception $e) {
        $bot->pm($args->chan, "Exception getting authserv account: $e");
        return;
    }
    $bot->pm($args->chan, "$who has access $access in {$args->chan}");
}

//TODO code syntax highlighting
#[Cmd("url", "img")]
#[Syntax('<input>')]
#[CallWrap("Amp\asyncCall")]
#[Options("--rainbow", "--rnb")]
function url($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    global $config;
    $url = $req->args[0] ?? '';
    if(!filter_var($url, FILTER_VALIDATE_URL)) {
        $bot->pm($args->chan, "invalid url");
        return;
    }

    if(preg_match('/^https?:\/\/pastebin.com\/([^\/]+)$/i', $url, $m)) {
        if(strtolower($m[1]) != 'raw') {
            $url = "https://pastebin.com/raw/$m[1]";
        }
    }
    echo "Fetching URL: $url\n";

    try {
        $client = HttpClientBuilder::buildDefault();
        $request = new Request($url);

        /** @var Response $response */
        $response = yield $client->request($request);
        $body = yield $response->getBody()->buffer();
        if ($response->getStatus() != 200) {
            $body = substr($body, 0, 200);
            $bot->pm($args->chan, "Error (" . $response->getStatus() . ") $body");
            return;
        }

        $type = explode("/", $response->getHeader('content-type'));
        if(!isset($type[0])) {
            $bot->pm($args->chan, "content-type not provided");
            return;
        }
        if($type[0] == 'image') {
            if(!isset($config['p2u'])) {
                $bot->pm($args->chan, "p2u hasn't been configued");
                return;
            }
            $ext = $type[1] ?? 'jpg'; // /shrug
            $filename = "url_thumb.$ext";
            echo "saving to $filename\n";
            file_put_contents($filename, $body);
            $width = ($config['url_default_width'] ?? 55);
            $filename_safe = escapeshellarg($filename);
            $thumbnail = `$config[p2u] -f m -p x -w $width $filename_safe`;
            unlink($filename);
            $cnt = 0;
            $thumbnail = explode("\n", $thumbnail);
            foreach ($thumbnail as $line) {
                if($line == '')
                    continue;
                $bot->pm($args->chan, $line);
                if($cnt++ > ($config['url_max'] ?? 100)) {
                    $bot->pm($args->chan, "wow thats a pretty big image, omitting ~" . count($thumbnail)-$cnt . "lines ;-(");
                    return;
                }
            }
        }
        if($type[0] == 'text') {
            var_dump($type);
            if(isset($type[1]) && !preg_match("/^plain;?/", $type[1])) {
                $bot->pm($args->chan, "content-type was ".implode('/', $type)." should be text/plain or image/* (pastebin.com maybe works too)");
                return;
            }
            if($req->args->getOpt('--rainbow') || $req->args->getOpt('--rnb'))
                $body = irctools\diagRainbow($body);
            $cnt = 0;
            $body = explode("\n", $body);
            foreach ($body as $line) {
                if($line == '')
                    continue;
                $bot->pm($args->chan, $line);
                if($cnt++ > ($config['url_max'] ?? 100)) {
                    $bot->pm($args->chan, "wow thats a pretty big text, omitting ~" . count($body)-$cnt . "lines ;-(");
                    return;
                }
            }
        }

    } catch (\Amp\MultiReasonException $errors) {
        foreach ($errors->getReasons() as $error) {
            echo $error;
            $bot->pm($args->chan, "\2URL Error:\2 " . substr($error, 0, strpos($error, "\n")));
        }
    } catch (\Exception $error) {
        // If something goes wrong Amp will throw the exception where the promise was yielded.
        // The HttpClient::request() method itself will never throw directly, but returns a promise.
        echo $error;
        $bot->pm($args->chan, "\2URL Error:\2 " . substr($error, 0, strpos($error, "\n")));
    }
}
