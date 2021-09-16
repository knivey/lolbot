<?php
namespace scripts\twitter;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Carbon\Carbon;
use Irc\Exception;
use scripts\linktitles\UrlEvent;

global $config;
if(!isset($config['twitter_bearer']))
    return; // neat we can do this and it stops the rest of the file from executing

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
                $req = new Request("https://api.twitter.com/2/tweets/$id?tweet.fields=created_at,public_metrics&expansions=author_id");
                $bearer = $config['twitter_bearer'];
                $req->addHeader('Authorization', "Bearer $bearer");
                /** @var Response $response */
                $response = yield $client->request($req);
                $body = yield $response->getBody()->buffer();
                if ($response->getStatus() != 200) {
                    echo "twitter url lookup failed with code {$response->getStatus()}\n";
                    var_dump($body);
                    return;
                }
                $j = json_decode($body);

                if(isset($j->errors)) {
                    if(!is_array($j->errors)) {
                        echo "twitter errors set but not an array\n";
                        var_dump($j);
                        return;
                    }
                    $err = $j->errors[0];
                    $event->reply("[Twitter error] {$err->detail}");
                    return;
                }

                $date = Carbon::createFromTimeString($j->data->created_at, 'utc');
                $ago = $date->shortRelativeToNowDiffForHumans(null, 3);
                if (is_array($j->includes->users)) {
                    $user = $j->includes->users[0]->name;
                    $user = html_entity_decode($user, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $user = str_replace(["\r","\n"], "  ", $user);
                }
                $text = $j->data->text;
                $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $text = str_replace(["\r","\n"], "  ", $text);
                $event->reply("[Twitter] $ago $user tweeted: $text | {$j->data->public_metrics->like_count} likes, {$j->data->public_metrics->reply_count} replies");
            } catch (Exception $e) {
                echo "twitter exception {$e->getMessage()}\n";
                return;
            }
        });
    }
);

