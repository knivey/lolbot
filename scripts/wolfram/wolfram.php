<?php
namespace knivey\lolbot\wolfram;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;

const waURL = 'https://api.wolframalpha.com/v2/query?input=';

#[Cmd("calc", "wa")]
#[Syntax('<query>...')]
#[CallWrap("Amp\asyncCall")]
function calc($args, \Irc\Client $bot, \knivey\cmdr\Request $req)
{
    global $config;
    if(!isset($config['waKey'])) {
        echo "waKey not set in config\n";
        return;
    }

    $query = waURL . urlencode(htmlentities($req->args['query'])) . '&appid=' . $config['waKey'] . '&format=plaintext&location=california';
    try {
        $body = yield async_get_contents($query);

        $xml = simplexml_load_string($body);
        $res = '';
        $resa = null;
        $resb = null;

        //first check if there was an error
        if ($xml['success'] == 'false') {
            $res = @$xml->tips->tip[0]['text'];
        } else {
            //the xml has things called pods so lets cycle through em
            //i decided to cycle here in case i want to look at more then 2 in future
            $count = 0;
            foreach ($xml->pod as $pod) {
                //I'm pretty sure our input pod will always be called Input
                //Or will be the first pod
                if ($count == 0) {
                    //input
                    $resa = str_replace("\n", "\2;\2 ", $pod->subpod->plaintext);
                }
                if ($count == 1) {
                    $resb = str_replace("\n", "\2;\2 ", $pod->subpod->plaintext);
                }
                if ($count != 1 && $pod['id'] == 'DecimalApproximation') {
                    $resb .= " \2DecimalApproximation:\2 " . $pod->subpod->plaintext;
                }
                $count++;
            }
            $res = "$resa = $resb";
        }
        $parsetime = $xml['parsetiming'];
        $outtatime = $xml['parsetimedout'];
        //we didn't have tips? try didyoumean
        if ($res == '') {
            $res = "No results for query";
            if (isset($xml->didyoumeans->didyoumean[0])) {
                $res .= ", Did you mean: " . $xml->didyoumeans->didyoumean[0];
            }
        }

        if ($outtatime != 'false') {
            $res = "Error, query took too long to parse.";
        }
        $bot->pm($args->chan, "\2WA:\2 " . $res);
    } catch (\async_get_exception $error) {
        echo $error;
        $bot->pm($args->chan, "\2wz:\2 {$error->getIRCMsg()}");
        return;
    } catch (\Exception $error) {
        echo $error;
        $bot->pm($args->chan, "\2WA:\2 " . substr($error, 0, strpos($error, "\n")));
    }
}