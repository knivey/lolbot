<?php
namespace scripts\linktitles;

use Amp\Http\Client\Connection\ConnectionLimitingPool;
use Amp\Http\Client\Connection\DefaultConnectionFactory;
use Amp\Http\Client\Cookie\CookieInterceptor;
use Amp\Http\Client\Cookie\LocalCookieJar;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Socket\Socks5SocketConnector;
use Doctrine\Common\Collections\Criteria;
use lolbot\entities\Network;
use scripts\linktitles\entities\hostignore;
use scripts\linktitles\entities\ignore_type;
use scripts\linktitles\entities\ignore;
use scripts\script_base;

use function Amp\Future\awaitAll;

use Amp\TimeoutCancellation;
use Knivey\OpenAi\HttpClient as OpenAiHttpClient;
use Knivey\OpenAi\OpenAiClient;
use Knivey\OpenAi\Request\ChatRequest;
use Knivey\OpenAi\Request\Message;
use Knivey\OpenAi\Request\Content\TextPart;
use Knivey\OpenAi\Request\Content\ImagePart;

class linktitles extends script_base
{
    public \Psr\EventDispatcher\EventDispatcherInterface $eventDispatcher;

    //adding buffer limit is an extra precaution to the body size limit
    const bufferLimit = 1024*1024*40;
    const defaultVisionPrompt = 'very short summary on one line. dont describe the format e.g. "the image", "the chart", "a meme", just the subject/content/data. dont add unnecessary moral judgments like "outdated", "controversial", "offensive", "antisemitic". keep it short!';

//feature requested by terps
//sends all urls into a log channel for easier viewing url history
//TODO take url as param to highlight it here
    /**
     * @param string[] $title
     */
    function logUrl(\Irc\Client $bot, string $nick, string $chan, string $line, string|array $title): void
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

    /**
     * @var array<string, string>
     */
    private array $link_history = [];
    /**
     * @var array<string, list<int>>
     */
    private array $link_ratelimit = [];
    function linktitles(\Irc\Client $bot, string $nick, string $chan, string $identhost, string $text): void
    {
        global $config;
        foreach (explode(' ', $text) as $word) {
            if (filter_var($word, FILTER_VALIDATE_URL) === false) {
                continue;
            }
            if(!preg_match("/^https?:\/\/.+/i", $word)) {
                continue;
            }
            if ($this->urlIsIgnored($chan, "$nick!$identhost", $word))
                continue;

            if (($this->link_history[$chan] ?? "") == $word) {
                continue;
            }
            $this->link_history[$chan] = $word;

            $maxUrls = (int)($config['linktitles_rate_urls'] ?? 2);
            $window = (int)($config['linktitles_rate_seconds'] ?? 2);
            $now = time();
            $this->link_ratelimit[$chan] = array_values(array_filter(
                $this->link_ratelimit[$chan] ?? [],
                fn($ts) => $now - $ts < $window
            ));
            if (count($this->link_ratelimit[$chan]) >= $maxUrls) {
                $this->logUrl($bot, $nick, $chan, $text, "Err: Rate limit exceeded");
                continue;
            }
            $this->link_ratelimit[$chan][] = $now;

            $urlEvent = new UrlEvent();
            $urlEvent->url = $word;
            $urlEvent->chan = $chan;
            $urlEvent->nick = $nick;
            $urlEvent->text = $text;
            $this->eventDispatcher->dispatch($urlEvent);

            $urlEvent->awaitAll();
            
            if ($urlEvent->handled) {
                $urlEvent->sendReplies($bot, $chan);
                $urlEvent->doLog($this, $bot);
                continue;
            }
            $this->logger->info("no URL events handled, falling back to normal title extraction");

            $word = preg_replace("@^https?://(www\.)?reddit.com@i", "https://old.reddit.com", $word);

            try {
                $client = $this->buildHttpClient($word);
                $req = new Request($word);
                $userAgent = $config['linktitles_useragent'] ?? "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/78.0.3904.108 Safari/537.36";
                $req->setHeader("User-Agent", $userAgent);
                $req->setHeader("Accept", "text/html, application/xhtml+xml, application/xml;q=0.9, */*;q=0.8");
                $req->setHeader("Accept-Language", "en-US, en;q=0.9");
                $req->setTransferTimeout(4000);
                $req->setBodySizeLimit(1024 * 1024 * 8);
                /** @var Response $response */
                $response = $client->request($req);
                $body = $response->getBody()->buffer(limit: self::bufferLimit);
                if ($response->getStatus() != 200) {
                    $this->logUrl($bot, $nick, $chan, $text, "Err: {$response->getStatus()} {$response->getReason()}");
                    continue;
                }

                if (preg_match("@^image/(.*)$@i", $response->getHeader("content-type"), $m)) {
                    $size = $response->getHeader("content-length");
                    if ($size !== null && is_numeric($size))
                        $size = \knivey\tools\convert((int)$size);
                    else
                        $size = "?b";
                    $d = getimagesizefromstring($body);
                    if (!$d) {
                        $out = "[ $m[1] image $size ]";
                    } else {
                        $out = "[ $m[1] image $size $d[0]x$d[1] ]";
                    }
                    $aiDesc = $this->getAiDescription($body);
                    if ($aiDesc !== null) {
                        $out = "$out — $aiDesc";
                    }
                    $bot->pm($chan, "  $out");
                    $this->logUrl($bot, $nick, $chan, $text, $out);
                    continue;
                }
                if (preg_match("@^video/(.*)$@i", $response->getHeader("content-type"), $m)) {
                    $size = $response->getHeader("content-length");
                    if ($size !== null && is_numeric($size))
                        $size = \knivey\tools\convert((int)$size);
                    else
                        $size = "?b";

                    if (!shell_exec('which mediainfo')) {
                        echo "mediainfo not found, only giving basic url info\n";
                        $out = "[ $m[1] {$response->getHeader("content-type")} ]";
                        $bot->pm($chan, "  $out");
                        $this->logUrl($bot, $nick, $chan, $text, $out);
                        continue;
                    }
                    $fn = "tmp_" . bin2hex(random_bytes(8)) . ".{$m[1]}";
                    file_put_contents($fn, $body);
                    $mi = simplexml_load_string(shell_exec("mediainfo $fn --Output=XML"));
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
                        $dur = \Duration_toString((int)round((float)$vt->Duration)) . ' long';
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


    private function getAiDescription(string $body): ?string
    {
        global $config;
        if (!isset($config['ai_vision_key'])) {
            return null;
        }

        try {
            $maxDim = (int)($config['ai_vision_max_dim'] ?? 1024);
            $quality = (int)($config['ai_vision_jpg_quality'] ?? 85);

            $img = new \Imagick();
            try {
                $img->readImageBlob($body);
                $img->setImageFormat('jpeg');
                $img->setJPEGCompressionQuality($quality);
                $img->thumbnailImage($maxDim, $maxDim, true);
                $base64 = base64_encode($img->getImageBlob());
            } finally {
                $img->clear();
            }

            $ampClient = HttpClientBuilder::buildDefault();
            $openAiHttp = new OpenAiHttpClient($config['ai_vision_key'], $ampClient, new TimeoutCancellation(10));
            $client = new OpenAiClient(
                apiKey: $config['ai_vision_key'],
                baseUrl: $config['ai_vision_base_url'] ?? 'https://api.openai.com/v1',
                httpClient: $openAiHttp,
            );

            $prompt = $config['ai_vision_prompt'] ?? self::defaultVisionPrompt;
            $model = $config['ai_vision_model'] ?? 'gpt-4o';

            $response = $client->chatCompletion(new ChatRequest(
                model: $model,
                messages: [
                    Message::user([
                        new TextPart($prompt),
                        ImagePart::base64($base64, 'image/jpeg'),
                    ]),
                ],
            ));

            $description = $response->choices[0]->message->content ?? null;
            if ($description === null || trim($description) === '') {
                return null;
            }
            $description = trim($description);
            $description = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F]/', '', $description);
            if (mb_strwidth($description) > 200) {
                $description = mb_strimwidth($description, 0, 197, '...');
            }
            return $description;
        } catch (\Exception $e) {
            $this->logger->warning("AI vision description failed: " . $e->getMessage());
            return null;
        }
    }

    private function isProxyExcluded(string $host): bool
    {
        global $config;
        $excludes = $config['linktitles_proxy_exclude'] ?? [];
        foreach ($excludes as $pattern) {
            if (preg_match(\knivey\tools\globToRegex($pattern) . 'i', $host)) {
                return true;
            }
        }
        return false;
    }

    private function buildHttpClient(string $url): \Amp\Http\Client\HttpClient
    {
        global $config;
        $cookieJar = new LocalCookieJar;
        $builder = (new HttpClientBuilder)
            ->interceptNetwork(new CookieInterceptor($cookieJar));

        $proxy = $config['linktitles_proxy'] ?? null;
        if ($proxy !== null) {
            $host = parse_url($url, PHP_URL_HOST);
            if ($host !== false && $host !== null && !$this->isProxyExcluded($host)) {
                $user = $config['linktitles_proxy_user'] ?? null;
                $pass = $config['linktitles_proxy_pass'] ?? null;
                $connector = new Socks5SocketConnector($proxy, $user, $pass);
                $factory = new DefaultConnectionFactory($connector);
                $pool = ConnectionLimitingPool::byAuthority(PHP_INT_MAX, $factory);
                $builder = $builder->usingPool($pool);
            }
        }

        return $builder->build();
    }

//TODO can add cache for this
    function urlIsIgnored(string $chan, string $fullhost, string $url): bool
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