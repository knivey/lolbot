<?php
namespace knivey\lolbot\tools;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
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
function dns($nick, $chan, \Irc\Client $bot, \knivey\cmdr\Request $req)
{
    global $config;
    try {
        if(isset($req->args['type'])) {
            $type = getDnsType($req->args['type']);
            if($type === false) {
                $bot->pm($chan, "Unsupported record type");
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
        $bot->pm($chan, "DNS for {$req->args['query']} ".($req->args['type'] ?? 'A, AAAA')." - $recs");
    } catch (\Exception $e) {
        $bot->pm($chan, "DNS Exception {$e->getMessage()}");
    }
}

#[Cmd("rainbow", "rnb", "nes")]
#[Syntax('<input>...')]
function nes($nick, $chan, \Irc\Client $bot, \knivey\cmdr\Request $req)
{
    $text = str_replace('\n', "\n", $req->args[0]);
    $text = irctools\diagRainbow($text);
    foreach(explode("\n", $text) as $line) {
        $bot->pm($chan, $line);
    }
}

#[Cmd("url", "img")]
#[Syntax('<input>')]
#[CallWrap("Amp\asyncCall")]
#[Options("--rainbow", "--rnb")]
function url($nick, $chan, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    global $config;
    $url = $req->args[0] ?? '';
    if(!filter_var($url, FILTER_VALIDATE_URL)) {
        $bot->pm($chan, "invalid url");
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
            $bot->pm($chan, "Error (" . $response->getStatus() . ") $body");
            return;
        }

        $type = explode("/", $response->getHeader('content-type'));
        if(!isset($type[0])) {
            $bot->pm($chan, "content-type not provided");
            return;
        }
        if($type[0] == 'image') {
            if(!isset($config['p2u'])) {
                $bot->pm($chan, "p2u hasn't been configued");
                return;
            }
            $ext = $type[1] ?? 'jpg'; // /shrug
            $filename = "url_thumb.$ext";
            echo "saving to $filename\n";
            file_put_contents($filename, $body);
            $width = 55;
            $filename_safe = escapeshellarg($filename);
            $thumbnail = `$config[p2u] -f m -p x -w $width $filename_safe`;
            unlink($filename);
            $cnt = 0;
            $thumbnail = explode("\n", $thumbnail);
            foreach ($thumbnail as $line) {
                if($line == '')
                    continue;
                $bot->pm($chan, $line);
                if($cnt++ > ($config['url_max'] ?? 100)) {
                    $bot->pm($chan, "wow thats a pretty big image, omitting ~" . count($thumbnail)-$cnt . "lines ;-(");
                    return;
                }
            }
        }
        if($type[0] == 'text') {
            var_dump($type);
            if(isset($type[1]) && !preg_match("/^plain;?/", $type[1])) {
                $bot->pm($chan, "content-type was ".implode('/', $type)." should be text/plain or image/* (pastebin.com maybe works too)");
                return;
            }
            if($req->args->getOpt('--rainbow') || $req->args->getOpt('--rnb'))
                $body = irctools\diagRainbow($body);
            $cnt = 0;
            $body = explode("\n", $body);
            foreach ($body as $line) {
                if($line == '')
                    continue;
                $bot->pm($chan, $line);
                if($cnt++ > ($config['url_max'] ?? 100)) {
                    $bot->pm($chan, "wow thats a pretty big text, omitting ~" . count($body)-$cnt . "lines ;-(");
                    return;
                }
            }
        }

    } catch (\Amp\MultiReasonException $errors) {
        foreach ($errors->getReasons() as $error) {
            echo $error;
            $bot->pm($chan, "\2URL Error:\2 " . substr($error, 0, strpos($error, "\n")));
        }
    } catch (\Exception $error) {
        // If something goes wrong Amp will throw the exception where the promise was yielded.
        // The HttpClient::request() method itself will never throw directly, but returns a promise.
        echo $error;
        $bot->pm($chan, "\2URL Error:\2 " . substr($error, 0, strpos($error, "\n")));
    }
}
