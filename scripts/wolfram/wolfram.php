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

    // https://products.wolframalpha.com/api/documentation?scrollTo=controlling-width-of-results
    // width is how many pixels rendered text would fit in
    $query = waURL . urlencode($cmdArgs['query']) . '&appid=' . $config['waKey'] . 
    '&format=plaintext&location=Los+Angeles,+California&width=3000'.
    '&excludepodid=MakeChangeMoreThanOneCoin:QuantityData';
    try {
        $body = async_get_contents($query);

        $xml = simplexml_load_string($body);
        $input = '';
        $result = '';
        $decimalAprox = '';
        if ($xml['parsetimedout'] == 'true') {
            throw new \Exception("Error, query took too long to parse.");
        }
        if ($xml['error'] == 'true') {
            throw new \Exception("Error, " . @$xml->error->msg);
        }

        if ($xml['success'] == 'false') {
            // The input wasn't understood, show tips or didyoumeans
            if(isset($xml->tips))
                $result = ", " . @$xml->tips->tip[0]['text'];
            elseif(isset($xml->didyoumeans))
                $result .= ", Did you mean: " . @$xml->didyoumeans->didyoumean[0];
            throw new \Exception("Query not understood" . $result);
        }
        $topPod = '';
        foreach ($xml->pod as $pod) {
            switch($pod['id']) {
            case 'Input':
                $input = str_replace("\n", "\2;\2 ", $pod->subpod->plaintext);
                break;
            case 'Result':
                $result = str_replace("\n", "\2;\2 ", $pod->subpod->plaintext);
                break;
            case 'DecimalApproximation':
                $decimalAprox = substr($pod->subpod->plaintext, 0, 200);
                break;
            default:
                if($topPod == '')
                    $topPod = str_replace("\n", "\2;\2 ", $pod->subpod->plaintext);
            }
        }
        if($result == "") {
            $result = $topPod;
        }
        if($result == "" && $decimalAprox != "") {
            $result = $decimalAprox;
            $decimalAprox = "";
        }
        $res = "$input = $result";
        if($decimalAprox != "") {
            $res .= " ($decimalAprox)";
        }
        $out = explode("\n", wordwrap($res, 400, "\n", true));
        $cnt = 0;
        foreach ($out as $msg) {
            $bot->pm($args->chan, "\2WA:\2 " . $msg);
            if($cnt++ > 4) break;
        }
    } catch (\async_get_exception $error) {
        echo $error->getMessage();
        $bot->pm($args->chan, "\2WA:\2 {$error->getIRCMsg()}");
        return;
    } catch (\Exception $error) {
        echo $error->getMessage();
        $bot->pm($args->chan, "\2WA:\2 {$error->getMessage()}");
    }
}