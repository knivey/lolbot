<?php
namespace scripts\wolfram;

use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;

const waURL = 'https://api.wolframalpha.com/v2/query?input=';

#[Cmd("calc", "wa")]
#[Syntax('<query>...')]
function calc($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    global $config;
    if(!isset($config['waKey'])) {
        echo "waKey not set in config\n";
        return;
    }

    //note width means pixels not text len
    $query = waURL . urlencode($cmdArgs['query']) . '&appid=' . $config['waKey'] . '&format=plaintext&location=Los+Angeles,+California&format=plaintext&width=3000';
    try {
        $body = async_get_contents($query);

        $xml = simplexml_load_string($body);
        $res = '';
        $resa = "";
        $resb = "";

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
                if($pod->subpod->plaintext == '')
                    continue;
                if ($count == 1) {
                    $resb = str_replace("\n", "\2;\2 ", $pod->subpod->plaintext);
                }
                if ($count != 1 && $pod['id'] == 'DecimalApproximation') {
                    $resb .= " \2DecimalApproximation:\2 " . substr($pod->subpod->plaintext, 0, 200);
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
        $out = explode("\n", wordwrap($res, 400, "\n", true));
        $cnt = 0;
        foreach ($out as $msg) {
            $bot->pm($args->chan, "\2WA:\2 " . $msg);
            if($cnt++ > 4) break;
        }
    } catch (\async_get_exception $error) {
        echo $error->getMessage();
        $bot->pm($args->chan, "\2wz:\2 {$error->getIRCMsg()}");
        return;
    } catch (\Exception $error) {
        echo $error->getMessage();
        $bot->pm($args->chan, "\2WA:\2 {$error->getMessage()}");
    }
}