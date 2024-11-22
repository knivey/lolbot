<?php
namespace scripts\youtube;

use Amp\Http\Client\Body\FormBody;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Process\Process;
use Amp\Promise;
use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Options;
use knivey\cmdr\attributes\Syntax;
use League\Uri\UriString;
use Carbon\Carbon;
use Psr\EventDispatcher\ListenerProviderInterface;
use scripts\linktitles\UrlEvent;
use scripts\script_base;

require_once 'library/async_get_contents.php';

class youtube extends script_base
{
    function ytDuration($input): string
    {
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
                $dur = "\x02\x0304🔴 LIVE";
            }
        } catch (\Exception $e) {
            return '???';
        }
        return $dur;
    }

    function getLiveVideos($channelId): Promise
    {
        return \Amp\call(function () use ($channelId) {
            global $config;
            if (!isset($config['gkey'])) {
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
            if (!is_array($data->items) || count($data->items) < 1)
                return null;
            return $data->items;
        });
    }

    /**
     * @param $id
     * @return Promise
     */
    function getVideoInfo($id): Promise
    {
        return \Amp\call(function () use ($id) {
            global $config;
            if (!isset($config['gkey'])) {
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
            if (!is_array($data->items) || count($data->items) < 1)
                return null;
            return $data->items[0];
        });
    }

    function hostToFilehole(string $filename): Promise
    {
        return \Amp\call(function () use ($filename) {
            if (!file_exists($filename))
                throw new \Exception("hostToFilehole called with non existant filename: $filename");
            $client = HttpClientBuilder::buildDefault();
            $request = new Request("https://filehole.org", "POST");
            $body = new FormBody();
            $body->addField('url_len', '5');
            $body->addField('expiry', '86400');
            $body->addFile('file', $filename);
            $request->setBody($body);
            //var_dump($request);
            /** @var Response $response */
            $response = yield $client->request($request);
            //var_dump($response);
            if ($response->getStatus() != 200) {
                throw new \Exception("filehole.org returned {$response->getStatus()}");
            }
            $respBody = yield $response->getBody()->buffer();
            return $respBody;
        });
    }

    function isShort($duration)
    {
        try {
            $di = new \DateInterval($duration);
            if ($di->y > 0 || $di->m > 0 || $di->h > 0 || $di->d > 0) {
                return false;
            }
            $s = 0;
            if ($di->s > 0) {
                $s += $di->s;
            }
            if ($di->i > 0) {
                $s += $di->i * 60;
            }
            if ($s < 90 && $s > 0)
                return true;
        } catch (\Throwable $e) {
            var_dump($e);
            return false;
        }
        return false;
    }


    private $youtube_history = [];
    public ListenerProviderInterface $eventProvider;
    function setEventProvider(ListenerProviderInterface $eventProvider): void
    {
        $this->eventProvider = $eventProvider;
        $this->eventProvider->addListener($this->eventHandler(...));
    }

    function eventHandler (UrlEvent $event): void
    {
        global $config;

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
            global $config;
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
            if (($this->youtube_history[$event->chan] ?? "") == $id) {
                $repost = "\x0307,01[\x0304,01REPOST\x0307,01]\x03 ";
            }
            $this->youtube_history[$event->chan] = $id;
            echo "Looking up youtube video $id\n";

            try {
                $v = yield $this->getVideoInfo($id);
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

            $shorts = "";
            if (($config['youtube_download_shorts'] ?? false)) {
                if ($this->isShort($v->contentDetails->duration) && str_contains($event->url, '/shorts/') &&
                    (($config['youtube_upload_shorts'] ?? false) || ($config['youtube_host_shorts'] ?? false))) {
                    try {
                        //TODO check if file was already downloaded
                        $proc = new Process("yt-dlp --no-playlist --no-progress -q --no-simulate -j -o '%(id)s.%(ext)s' " . escapeshellarg($event->url));
                        yield $proc->start();
                        $ytjson_raw = yield \Amp\ByteStream\buffer($proc->getStdout());
                        $ytjsonerr = yield \Amp\ByteStream\buffer($proc->getStderr());
                        $code = yield $proc->join();
                        $ytjson = json_decode($ytjson_raw);
                        if (isset($ytjson->filename)) {
                            echo "file: {$ytjson->filename}\n";
                            if (($config['youtube_host_shorts'] ?? false)) {
                                rename($ytjson->filename, $config['youtube_host_shorts'] . '/' . $ytjson->filename);
                                $shorts = " | " . ($config['youtube_host_shorts_url'] ?? "https://localhost/") . $ytjson->filename;
                            } else {
                                $shorts = " | " . yield $this->hostToFilehole((new \SplFileInfo($ytjson->filename))->getRealPath());
                                unlink($ytjson->filename);
                            }
                            echo "shorts: $shorts\n";
                        } else {
                            file_put_contents("ytjson", $ytjson_raw);
                            file_put_contents("ytjsonerr", $ytjsonerr);
                            echo "ytjson filename missing! retcode: $code output saved to ytjson and ytjsonerr";
                        }
                    } catch (\Throwable $e) {
                        echo "Exception:\n";
                        var_dump($e);
                    }
                }
            }

            try {
                $title = $v->snippet->title;
                $dur = $this->ytDuration($v->contentDetails->duration);
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
                $msg = "\2\3" . "01,00You" . "\3" . "00,04Tube\3\2 {$repost}$title | $chanTitle | $ago | $dur $shorts";
                $thumbnail = $v?->snippet?->thumbnails?->high?->url;
                if ($thumbnail != null && ($config['bots'][$this->bot->id]['youtube_thumb'] ?? false) && isset($config['p2u']) && $repost == '') {
                    $ext = explode('.', $thumbnail);
                    $ext = array_pop($ext);
                    try {
                        echo "fetching thumbnail at $thumbnail\n";
                        $body = yield async_get_contents($thumbnail);
                        $filename = "thumb_$id.$ext";
                        echo "saving to $filename\n";
                        file_put_contents($filename, $body);
                        $width = $config['bots'][$this->bot->id]['youtube_thumbwidth'] ?? 40;
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
                        if (isset($config['bots'][$this->bot->id]['youtube_pump_host']) && isset($config['bots'][$this->bot->id]['youtube_pump_key'])) {
                            try {
                                $client = HttpClientBuilder::buildDefault();
                                $host = $config['bots'][$this->bot->id]['youtube_pump_host'];
                                $pumpchan = urlencode(substr($event->chan, 1));
                                $pumpUrl = UriString::parse($host);
                                $pumpUrl['path'] .= "/privmsg/$pumpchan";
                                $pumpUrl['path'] = preg_replace("@/+@", "/", $pumpUrl['path']);
                                $pumpUrl = UriString::build($pumpUrl);

                                $request = new Request($pumpUrl, "POST");
                                $sendBody = implode("\n", $thumbnail);
                                $sendBody .= "\n$msg";
                                $request->setBody($sendBody);
                                $request->setHeader('key', $config['bots'][$this->bot->id]['youtube_pump_key']);
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
                echo "YouTube async Exception: $e\n";
            } catch (\Exception $e) {
                $event->reply("\2YouTube Error:\2 Unknown data received.");
                echo "YouTube Exception:\n";
                //var_dump($v);
                echo $e->getMessage();
            }
        }));
    }

    #[Cmd("yt", "ytsearch", "youtube")]
    #[Syntax('<query>...')]
    #[CallWrap("Amp\asyncCall")]
    #[Options("--amt")]
    function ytsearch($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
    {
        global $config;
        $reply = function ($msg) use ($bot, $args) {
            $bot->pm($args->chan, "\2ytsearch:\2 $msg");
        };
        $key = $config['gkey'] ?? false;
        if (!$key) {
            $reply("youtube key not set on config");
            return;
        }
        $amt = 1;
        if ($cmdArgs->optEnabled("--amt")) {
            $amt = $cmdArgs->getOpt("--amt");
            if ($amt < 1 || $amt > 5) { //If greater than 5 should increase maxResults in api call
                $reply("Result --amt should be from 1 to 5");
                return;
            }
        }
        /**
         * @psalm-suppress NullArgument
         */
        $q = urlencode($cmdArgs['query']);
        // search only supports snippet part :(
        $url = "https://www.googleapis.com/youtube/v3/search?q=$q&key=$key&part=snippet&safeSearch=none&type=video";
        try {
            $body = yield async_get_contents($url);
        } catch (\async_get_exception $error) {
            echo $error;
            $reply($error->getIRCMsg());
            return;
        } catch (\Exception $error) {
            echo $error->getMessage();
            $reply($error->getMessage());
            return;
        }
        $res = json_decode($body, true);
        if (!isset($res['items']) || count($res['items']) == 0) {
            $reply("no results");
            return;
        }
        $cnt = 0;
        foreach ($res['items'] as $i) {
            if ($cnt++ >= $amt)
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
                $v = yield $this->getVideoInfo($i['id']['videoId']);
                if ($v != null) {
                    $dur = $this->ytDuration($v->contentDetails->duration);
                    //if for some reason duration fails format it so it wont look bad missing
                    $dur = " | $dur";
                }
            } catch (\Exception $e) {
            }
            $reply("$url - $title | $channel{$dur}");
        }
        if ($cnt < $amt) {
            $reply("No more results :(");
        }
    }
}


























