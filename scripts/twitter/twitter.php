<?php
namespace scripts\twitter;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Carbon\Carbon;
use scripts\linktitles\UrlEvent;
use simplehtmldom\HtmlDocument;

global $config;

global $eventProvider;
$eventProvider->addListener(
    function (UrlEvent $event) {
        if ($event->handled)
            return;
        if(!preg_match("@^https?://(?:mobile\.)?twitter\.com/([^/]+)/status/(\d+).*$@i", $event->url, $m))
            return;
        $user = $m[1];
        $id = $m[2];
        $event->promises[] = \Amp\call(function() use ($event, $id, $user) {
            global $config;
            try {
                $client = HttpClientBuilder::buildDefault();
                //$req = new Request("https://nitter.net/$user/status/$id");
                $req = new Request("https://nitter.ktachibana.party/$user/status/$id");
                $response = yield $client->request($req);
                $body = yield $response->getBody()->buffer();
                if ($response->getStatus() != 200) {
                    echo "twitter url lookup failed with code {$response->getStatus()}\n";
                    var_dump($body);
                    return;
                }
                
                $html = new HtmlDocument();
                $html->load($body);
                $text = $html->find("div.tweet-content", 0)->plaintext;
                $date = $html->find("p.tweet-published", 0)->plaintext;
                $date = str_replace("· ", "", $date);
                $date = Carbon::createFromTimeString($date, 'utc');
                $ago = $date->shortRelativeToNowDiffForHumans(null, 3);
                $like_count = $html->find('div.icon-container span.icon-heart', 0)->parent()->plaintext;
                $reply_count = $html->find('div.icon-container span.icon-comment', 0)->parent()->plaintext;

                $event->reply("[Twitter] $ago $user tweeted: $text | {$like_count} likes, {$reply_count} replies");
            } catch (\Exception $e) {
                echo "twitter exception {$e->getMessage()}\n";
                return;
            }
        });
    }
);
