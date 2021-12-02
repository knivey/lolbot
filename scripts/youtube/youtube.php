<?php
namespace scripts\youtube;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Options;
use knivey\cmdr\attributes\Syntax;
use League\Uri\UriString;
use Carbon\Carbon;
use scripts\linktitles\UrlEvent;

function ytDuration($input) {
    try {
        $di = new \DateInterval($input);
        $dur = '';
        if ($di->s > 0) {
            $dur = "{$di->s}s";
        }
        if ($di->i > 0) {
            $dur = "{$di->i}m $dur";
        }
        if ($di->h > 0) {
            $dur = "{$di->h}h $dur";
        }
        if ($di->d > 0) {
            $dur = "{$di->d}d $dur";
        }
        //Seems unlikely, months and years
        if ($di->m > 0) {
            $dur = "{$di->m}M $dur";
        }
        if ($di->y > 0) {
            $dur = "{$di->y}y $dur";
        }
        $dur = trim($dur);
        if ($dur == '') {
            $dur = '\x0204⚫ LIVE';
        }
    } catch (\Exception $e) {
        return '???';
    }
    return $dur;
}

function getLiveVideos($channelId) {
    return \Amp\call(function () use ($channelId) {
        global $config;
        if(!isset($config['gkey'])) {
            echo "No gkey set for youtube lookup\n";
            return null;
        }
        $body = yield async_get_contents("https://www.googleapis.com/youtube/v3/search?part=snippet&channelId=$channelId&eventType=live&type=video&key={$config['gkey']}");
        $data = json_decode($body, false);

        if (!is_object($data)) {
            echo "Youtube getLiveVideos for $channelId, bad or no data\n";
            var_dump($data);
            return null;
        }
        if(!is_array($data->items) || count($data->items) < 1)
            return null;
        return $data->items;
    });
}

/**
 * @param $id
 * @throws \async_get_exception
 * @return \Amp\Promise
 */
function getVideoInfo($id) {
    return \Amp\call(function () use ($id) {
        global $config;
        if(!isset($config['gkey'])) {
            echo "No gkey set for youtube lookup\n";
            return null;
        }
        $body = yield async_get_contents("https://www.googleapis.com/youtube/v3/videos?id=$id&part=snippet%2CcontentDetails%2Cstatistics&key={$config['gkey']}");
        $data = json_decode($body, false);

        if (!is_object($data)) {
            echo "Youtube $id bad or no data\n";
            var_dump($data);
            return null;
        }
        if(!is_array($data->items) || count($data->items) < 1)
            return null;
         return $data->items[0];
    });
}


$youtube_history = [];
global $eventProvider;
$eventProvider->addListener(
    function (UrlEvent $event) {
        global $config;

        //Avoiding clobber of jewbirds radio adverts
        if (str_contains($event->text, "                     https://twitch.tv/hughbord")) {
            return;
        }

        if (!isset($config['gkey'])) {
            echo "No gkey set for youtube lookup\n";
            return;
        }
        //TODO good god what a complicated regex, maybe i should use the Uri parser instead
        $URL = '@^((?:https?:)?//)?((?:www|m)\.)?((?:youtube\.com|youtu.be))(/(?:[\w\-]+\?v=|shorts/|embed/|v/)?)([\w\-]+)(\S+)?$@i';
        if (!preg_match($URL, $event->url, $m)) {
            return;
        }

        if (!array_key_exists(5, $m)) {
            return;
        }
        $id = $m[5];

        $event->addPromise(\Amp\call(function () use ($event, $id) {
            global $config, $youtube_history;
            // Get this with https://www.youtube.com/watch?time_continue=165&v=Bfdy5a_R4K4
            if ($id == "watch") {
                parse_str(parse_url($event->url, PHP_URL_QUERY), $params);
                foreach ($params as $lhs => $rhs) {
                    if ($lhs == 'v') {
                        $id = $rhs;
                    }
                }
            }

            $repost = '';
            if (($youtube_history[$event->chan] ?? "") == $id) {
                $repost = "\x0307,01[\x0304,01REPOST\x0307,01]\x03 ";
            }
            $youtube_history[$event->chan] = $id;
            echo "Looking up youtube video $id\n";

            try {
                $v = yield getVideoInfo($id);
            } catch (\async_get_exception $error) {
                echo $error->getMessage();
                $event->reply("\2YouTube:\2 {$error->getIRCMsg()}");
                return;
            } catch (\Exception $error) {
                echo $error->getMessage();
                $event->reply("\2YouTube:\2 {$error->getMessage()}");
                return;
            }
            //dont want to spam on lots of errors with videos
            if ($v == null)
                return;

            try {
                $title = $v->snippet->title;
                $dur = ytDuration($v->contentDetails->duration);
                $chanTitle = $v->snippet->channelTitle;
                //$datef = 'M j, Y';
                //$date = date($datef, strtotime($v->snippet->publishedAt));
                // 2021-09-09T22:52:30Z believe this is zulu but carbon wasnt paying attention to the Z
                $date = Carbon::createFromTimeString($v->snippet->publishedAt, 'utc');
                $ago = $date->shortRelativeToNowDiffForHumans(null, 3);
                //$views = number_format($v->statistics->viewCount);
                //$likes = number_format($v->statistics->likeCount);
                //$hates = number_format($v->statistics->dislikeCount);

                $sent = false;
                $msg = "\2\3" . "01,00You" . "\3" . "00,04Tube\3\2 {$repost}$title | $chanTitle | $ago | $dur";
                $thumbnail = $v?->snippet?->thumbnails?->high?->url;
                if ($thumbnail != null && ($config['youtube_thumb'] ?? false) && isset($config['p2u']) && $repost == '') {
                    $ext = explode('.', $thumbnail);
                    $ext = array_pop($ext);
                    try {
                        echo "fetching thumbnail at $thumbnail\n";
                        $body = yield async_get_contents($thumbnail);
                        $filename = "thumb_$id.$ext";
                        echo "saving to $filename\n";
                        file_put_contents($filename, $body);
                        $width = $config['youtube_thumbwidth'] ?? 40;
                        $filename_safe = escapeshellarg($filename);
                        $thumbnail = `$config[p2u] -f m -p x -w $width $filename_safe`;
                        unlink($filename);
                    } catch (\Exception $error) {
                        echo "yt thumb $error\n";
                        $thumbnail = '';
                    }
                    if ($thumbnail != '') {
                        $thumbnail = explode("\n", trim($thumbnail));
                        foreach ([count($thumbnail) - 1, count($thumbnail) - 2, 1, 0] as $i) {
                            if (trim($thumbnail[$i]) == "\x031,1") {
                                unset($thumbnail[$i]);
                            }
                        }
                        if (isset($config['youtube_pump_host']) && isset($config['youtube_pump_key'])) {
                            try {
                                $client = HttpClientBuilder::buildDefault();
                                $host = $config['youtube_pump_host'];
                                $pumpchan = substr($event->chan, 1);
                                $pumpUrl = UriString::parse($host);
                                $pumpUrl['path'] .= "/privmsg/$pumpchan";
                                $pumpUrl['path'] = preg_replace("@/+@", "/", $pumpUrl['path']);
                                $pumpUrl = UriString::build($pumpUrl);

                                $request = new Request($pumpUrl, "POST");
                                $sendBody = implode("\n", $thumbnail);
                                $sendBody .= "\n$msg";
                                $request->setBody($sendBody);
                                $request->setHeader('key', $config['youtube_pump_key']);
                                /** @var Response $response */
                                $response = yield $client->request($request);
                                //$body = yield $response->getBody()->buffer();
                                if ($response->getStatus() == 200) {
                                    $sent = true;
                                    $event->handled = true;
                                } else {
                                    echo "Problem sending youtube to $pumpUrl response: {$response->getStatus()}\n";
                                }
                            } catch (\Exception $e) {
                                echo "Problem sending youtube to pumpers\n";
                                echo $e;
                            }
                        }
                        if (!$sent) {
                            foreach ($thumbnail as $line) {
                                $event->reply($line);
                            }
                        }
                    }
                }
                if (!$sent) {
                    $event->reply($msg);
                }
            } catch (\async_get_exception $e) {
                $event->reply("\2YouTube Error:\2 {$e->getIRCMsg()}");
                echo "YouTube Error: $e\n";
            } catch (\Exception $e) {
                $event->reply("\2YouTube Error:\2 Unknown data received.");
                echo "YouTube Error: Unknown data received.\n";
                var_dump($v);
                echo $e->getMessage();
            }
        }));
    }
);


#[Cmd("yt", "ytsearch", "youtube")]
#[Syntax('[query]...')]
#[CallWrap("Amp\asyncCall")]
#[Options("--amt")]
function ytsearch($args, \Irc\Client $bot, \knivey\cmdr\Request $req)
{
    global $config;
    $reply = function($msg) use($bot, $args) {$bot->pm($args->chan, "\2ytsearch:\2 $msg");};
    $key = $config['gkey'] ?? false;
    if(!$key) {
        $reply("youtube key not set on config");
        return;
    }
    $amt = 1;
    if($req->args->getOpt("--amt")) {
        $amt = $req->args->getOptVal("--amt");
        if($amt < 1 || $amt > 5) { //If greater than 5 should increase maxResults in api call
            $reply("Result --amt should be from 1 to 5");
            return;
        }
    }

    $q = urlencode($req->args['query']);
    // search only supports snippet part :(
    $url = "https://www.googleapis.com/youtube/v3/search?q=$q&key=$key&part=snippet&safeSearch=none&type=video";
    try {
        $body = yield async_get_contents($url);
    }  catch (\async_get_exception $error) {
        echo $error;
        $reply($error->getIRCMsg());
        return;
    } catch (\Exception $error) {
        echo $error->getMessage();
        $reply($error->getMessage());
        return;
    }
    $res = json_decode($body, true);
    if(!isset($res['items']) || count($res['items']) == 0) {
        $reply("no results");
        return;
    }
    $cnt = 0;
    foreach ($res['items'] as $i) {
        if($cnt++ >=$amt)
            break;
        $s = $i['snippet'];
        $url = "https://youtu.be/{$i['id']['videoId']}";
        $title = html_entity_decode($s['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = htmlspecialchars_decode($title);
        $channel = html_entity_decode($s['channelTitle'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $channel = htmlspecialchars_decode($channel);
        //$desc = html_entity_decode($s['description'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        //$desc = htmlspecialchars_decode($desc);
        $dur = null;
        try {
            $v = yield getVideoInfo($i['id']['videoId']);
            if($v != null) {
                $dur = ytDuration($v->contentDetails->duration);
                //if for some reason duration fails format it so it wont look bad missing
                $dur = " | $dur";
            }
        } catch (\Exception $e) {
        }
        $reply("$url - $title | $channel{$dur}");
    }
    if($cnt < $amt) {
        $reply("No more results :(");
    }
}



























