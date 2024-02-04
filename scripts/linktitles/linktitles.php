<?php
namespace scripts\linktitles;

use Amp\Http\Client\Cookie\CookieInterceptor;
use Amp\Http\Client\Cookie\InMemoryCookieJar;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Doctrine\Common\Collections\Criteria;
use lolbot\entities\Network;
use scripts\linktitles\entities\hostignore;
use scripts\linktitles\entities\ignore_type;
use scripts\linktitles\entities\ignore;
use scripts\script_base;

class linktitles extends script_base
{
    public $eventDispatcher;

//feature requested by terps
//sends all urls into a log channel for easier viewing url history
//TODO take url as param to highlight it here
    /**
     * @param $bot
     * @param $nick
     * @param $chan
     * @param $line
     * @param string|array $title
     * @return void
     */
    function logUrl($bot, $nick, $chan, $line, string|array $title)
    {
        global $config;
        if (!isset($config['bots'][$this->bot->id]['url_log_chan']))
            return;
        $logChan = $config['bots'][$this->bot->id]['url_log_chan'];
        static $max = 0;
        $max = max(strlen($chan), $max);
        $chan = str_pad($chan, $max);
        $bot->pm($logChan, "$chan | <$nick> $line");
        if (is_string($title))
            $title = [$title];
        foreach ($title as $msg)
            $bot->pm($logChan, "  $msg");
    }

    private $link_history = [];
    private $link_ratelimit = 0;
    function linktitles(\Irc\Client $bot, $nick, $chan, $identhost, $text)
    {
        foreach (explode(' ', $text) as $word) {
            if (filter_var($word, FILTER_VALIDATE_URL) === false) {
                continue;
            }
            if ($this->urlIsIgnored($chan, "$nick!$identhost", $word))
                continue;

            if (($this->link_history[$chan] ?? "") == $word) {
                continue;
            }
            $this->link_history[$chan] = $word;

            if (time() < $this->link_ratelimit) {
                $this->logUrl($bot, $nick, $chan, $text, "Err: Rate limit exceeded");
                return;
            }
            $this->link_ratelimit = time() + 2;

            $urlEvent = new UrlEvent();
            $urlEvent->url = $word;
            $urlEvent->chan = $chan;
            $urlEvent->nick = $nick;
            $urlEvent->text = $text;
            $this->eventDispatcher->dispatch($urlEvent);

            yield \Amp\Promise\any($urlEvent->promises);
            if ($urlEvent->handled) {
                $urlEvent->sendReplies($bot, $chan);
                $urlEvent->doLog($this, $bot);
                continue;
            }

            $word = preg_replace("@^https?://(www\.)?reddit.com@i", "https://old.reddit.com", $word);

            try {
                $cookieJar = new InMemoryCookieJar;
                $client = (new HttpClientBuilder)
                    ->interceptNetwork(new CookieInterceptor($cookieJar))
                    ->build();
                $req = new Request($word);
                $req->setHeader("User-Agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/78.0.3904.108 Safari/537.36");
                $req->setHeader("Accept", "text/html, application/xhtml+xml, application/xml;q=0.9, */*;q=0.8");
                $req->setHeader("Accept-Language", "en-US, en;q=0.9");
                $req->setTransferTimeout(4000);
                $req->setBodySizeLimit(1024 * 1024 * 8);
                /** @var Response $response */
                $response = yield $client->request($req);
                $body = yield $response->getBody()->buffer();
                if ($response->getStatus() != 200) {
                    $this->logUrl($bot, $nick, $chan, $text, "Err: {$response->getStatus()} {$response->getReason()}");
                    continue;
                }

                if (preg_match("@^image/(.*)$@i", $response->getHeader("content-type"), $m)) {
                    $size = $response->getHeader("content-length");
                    if ($size !== null && is_numeric($size))
                        $size = \knivey\tools\convert($size);
                    else
                        $size = "?b";
                    $d = getimagesizefromstring($body);
                    if (!$d) {
                        $out = "[ $m[1] image $size ]";
                    } else {
                        $out = "[ $m[1] image $size $d[0]x$d[1] ]";
                    }
                    $bot->pm($chan, "  $out");
                    $this->logUrl($bot, $nick, $chan, $text, $out);
                    continue;
                }
                if (preg_match("@^video/(.*)$@i", $response->getHeader("content-type"), $m)) {
                    $size = $response->getHeader("content-length");
                    if ($size !== null && is_numeric($size))
                        $size = \knivey\tools\convert($size);
                    else
                        $size = "?b";

                    if (!`which mediainfo`) {
                        echo "mediainfo not found, only giving basic url info\n";
                        $out = "[ $m[1] {$response->getHeader("content-type")} ]";
                        $bot->pm($chan, "  $out");
                        $this->logUrl($bot, $nick, $chan, $text, $out);
                        continue;
                    }
                    $fn = "tmp_" . bin2hex(random_bytes(8)) . ".{$m[1]}";
                    file_put_contents($fn, $body);
                    $mi = simplexml_load_string(`mediainfo $fn --Output=XML`);
                    unlink($fn);

                    if (!isset($mi->media) || !isset($mi->media->track)) {
                        echo "linktitles video error\n";
                        var_dump($mi);
                    }
                    $vt = null;
                    $at = null;
                    foreach ($mi->media->track as $track) {
                        if ($track['type'] == 'Video')
                            $vt = $track;
                        if ($track['type'] == 'Audio')
                            $at = $track;
                    }
                    $videoFormat = $vt->Format;
                    if (isset($vt->FrameRate))
                        $frameRate = round((float)$vt->FrameRate) . 'fps';
                    else
                        $frameRate = $vt->FrameRate_Mode ?? '?';


                    $resX = $vt->Width ?? '?';
                    $resY = $vt->Height ?? '?';

                    if (isset($vt->Duration)) {
                        $dur = Duration_toString(round((float)$vt->Duration)) . ' long';
                    } else {
                        $dur = 'unknown duration';
                    }

                    if ($at == null) {
                        $audio = "No audio track";
                    } else {
                        $audio = "{$at->Format} audio";
                    }


                    $out = "[ $dur $m[1] video ({$videoFormat}) $size {$resX}x{$resY} @ {$frameRate}, $audio ]";
                    $bot->pm($chan, "  $out");
                    $this->logUrl($bot, $nick, $chan, $text, $out);
                    continue;
                }

                if (!preg_match("/<title[^>]*>([^<]+)<\/title>/im", $body, $m)) {
                    $this->logUrl($bot, $nick, $chan, $text, "Err: No <title>");
                    continue;
                }

                $title = strip_tags($m[1]);
                $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $title = htmlspecialchars_decode($title);
                $title = str_replace("\n", " ", $title);
                $title = str_replace("\r", " ", $title);
                $title = str_replace("\x01", "[CTCP]", $title);
                $title = substr(trim($title), 0, 300);
                $bot->pm($chan, "  [ $title ]");
                $this->logUrl($bot, $nick, $chan, $text, "[ $title ]");
            } catch (\Exception $error) {
                $this->logUrl($bot, $nick, $chan, $text, "Err: {$error->getMessage()}");
                echo "Link titles exception: {$error->getMessage()}\n";
            }
        }
    }


//TODO can add cache for this
    function urlIsIgnored($chan, $fullhost, $url): bool
    {
        global $entityManager;
        $criteria = Criteria::create();
        $criteria->where(Criteria::expr()->eq("type", ignore_type::global));
        $criteria->orWhere(Criteria::expr()->eq("network", $this->network));
        //todo bot would go here, and channel
        //$criteria->orWhere(Criteria::expr()->eq());

        /** @var ignore[] $ignores */
        $ignores = $entityManager->getRepository(ignore::class)->matching($criteria);
        foreach ($ignores as $ignore) {
            if (preg_match($ignore->regex, $url)) {
                return true;
            }
        }

        $hostignores = $entityManager->getRepository(hostignore::class)->matching($criteria);
        foreach ($hostignores as $hostignore) {
            $hostmask_re = \knivey\tools\globToRegex($hostignore->hostmask) . 'i';
            if (preg_match($hostmask_re, $fullhost)) {
                return true;
            }
        }
        return false;
    }

}