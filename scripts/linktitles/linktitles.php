<?php

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;

$link_history = [];
$link_ratelimit = 0;
function linktitles(\Irc\Client $bot, $chan, $text)
{
    global $link_history, $link_ratelimit;
    foreach(explode(' ', $text) as $word) {
        if (filter_var($word, FILTER_VALIDATE_URL) === false) {
            continue;
        }
        //Skip youtubes
        $URL = '/^((?:https?:)?\/\/)?((?:www|m)\.)?((?:youtube\.com|youtu.be))(\/(?:[\w\-]+\?v=|embed\/|v\/)?)([\w\-]+)(\S+)?$/';
        if (preg_match($URL, $word)) {
            continue;
        }

        if(($link_history[$chan] ?? "") == $word) {
            continue;
        }
        $link_history[$chan] = $word;

        if(time() < $link_ratelimit) {
            return;
        }
        $link_ratelimit = time() + 2;

        try {
            $client = HttpClientBuilder::buildDefault();
            $req = new Request($word);
            $req->setTransferTimeout(4000);
            $req->setBodySizeLimit(1024 * 1024 * 8);
            /** @var Response $response */
            $response = yield $client->request($req);
            $body = yield $response->getBody()->buffer();
            if ($response->getStatus() != 200) {
                $bot->pm($chan, "LinkTitles Error (" . $response->getStatus() . ") " . substr($body, 0, 200));
                var_dump($body);
                return;
            }

            if(!preg_match("/<title[^>]*>([^<]+)<\/title>/im", $body, $m)) {
                continue;
            }

            $title = strip_tags($m[1]);
            $title = html_entity_decode($title,  ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $title = htmlspecialchars_decode($title);
            $title = str_replace("\n", " ", $title);
            $title = str_replace("\r", " ", $title);
            $title = str_replace("\x01", "[CTCP]", $title);
            $title = substr(trim($title), 0, 300);
            $bot->pm($chan, "[ $title ]");
        } catch (Exception $error) {
            // If something goes wrong Amp will throw the exception where the promise was yielded.
            // The HttpClient::request() method itself will never throw directly, but returns a promise.
            echo "$error\n";
            //$bot->pm($chan, "LinkTitles Exception: " . $error);
        }
    }
}
