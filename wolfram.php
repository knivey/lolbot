<?php

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;

const waURL = 'https://api.wolframalpha.com/v2/query?input=';

function calc($a, $bot, $chan)
{
    global $config;
    echo "starting calc\n";
    unset($a[0]);
    $arg2 = implode(' ', $a);
    $query = waURL . urlencode(htmlentities($arg2)) . '&appid=' . $config['waKey'] . '&format=plaintext';
    try {
        $client = HttpClientBuilder::buildDefault();
        // Make an asynchronous HTTP request
        $promise = $client->request(new Request($query));
        // Client::request() is asynchronous! It doesn't return a response. Instead, it returns a promise to resolve the
        // response at some point in the future when we've received the headers of the response. Here we use yield which
        // pauses the execution of the current coroutine until the promise resolves. Amp will automatically continue the
        // coroutine then.
        /** @var Response $response */
        $response = yield $promise;

        $body = yield $response->getBody()->buffer();

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
        $bot->pm($chan, "\2WA:\2 " . $res);
    } catch (HttpException $error) {
        // If something goes wrong Amp will throw the exception where the promise was yielded.
        // The HttpClient::request() method itself will never throw directly, but returns a promise.
        echo $error;
        $bot->pm($chan, "\2WA:\2 " . $error->getMessage());
    }
}