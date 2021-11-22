<?php
namespace scripts\tools;
require_once 'library/async_get_contents.php';
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Irc\Exception;
use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Options;
use knivey\cmdr\attributes\Syntax;
use knivey\irctools;


#[Cmd("define", "dictionary")]
#[Syntax('<query>...')]
#[CallWrap("Amp\asyncCall")]
function dictionary($args, \Irc\Client $bot, \knivey\cmdr\Request $req)
{
    $word = rawurlencode($req->args['query']);
    try {
        $body = yield \async_get_contents("https://api.dictionaryapi.dev/api/v2/entries/en/$word");
    } catch (\async_get_exception $e) {
        if($e->getCode() == 404)
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
    if(isset($json->phonetics) && isset($json->phonetics[0]->text))
        $out .= " {$json->phonetics[0]->text}";
    $out .= " - ";
    foreach($json->meanings as $m) {
        $out .= "({$m->partOfSpeech}) {$m->definitions[0]->definition}";
        if(isset($m->definitions[0]->example))
            $out .= " Ex: {$m->definitions[0]->example}";
        $out .= " | ";
    }
    $out = rtrim($out, " |");
    $bot->pm($args->chan, $out);
}

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

#[Cmd("whois")]
#[Syntax('<nick>')]
function whois($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {

}

#[Cmd("choice", "choose")]
#[Syntax('<stuff>...')]
function choice($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    $opts = preg_split("/[,|]| +or( +|$)/", $req->args['stuff']);
    if($opts === false) {
        $bot->msg($args->chan, "i can't seem to decide :(");
        return;
    }
    $opts = array_filter(array_map('trim', $opts));
    if(count($opts) <2) {
        $bot->msg($args->chan, "gimme more than one option separated by: , or |");
        return;
    }
    $bot->msg($args->chan, "I choose: " . $opts[array_rand($opts)]);
}

#[Cmd("domaincheck", "dc")]
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
        $body = yield async_get_contents($url);
        $xml = simplexml_load_string($body);
        //var_dump($xml);
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
    } catch (\async_get_exception $error) {
        $bot->pm($args->chan, "\2DomainCheck:\2 Connection error :( try again later");
        echo $error->getMessage();
    } catch (\Exception $error) {
        $bot->pm($args->chan, "\2DomainCheck:\2 i unno some kinda error happen :(");
        echo $error->getMessage();
    }
}

#[Cmd("affirm")]
#[Syntax('[nick]...')]
#[CallWrap("Amp\asyncCall")]
function affirm($args, \Irc\Client $bot, \knivey\cmdr\Request $req)
{
    try {
        $body = yield async_get_contents("https://www.affirmations.dev");
        $j = json_decode($body);
        if(!isset($j->affirmation))
            throw new \Exception("affirmation not set: $body\n");
        $a = $j->affirmation;
        if(isset($req->args['nick'])) {
            $a = "{$req->args['nick']}, $a";
        }
        $bot->msg($args->chan, $a);
    } catch (\Exception $error) {
        echo $error->getMessage();
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

