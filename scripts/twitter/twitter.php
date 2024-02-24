<?php
namespace scripts\twitter;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Carbon\Carbon;
use Amp\Http\Client\Response;
use Psr\EventDispatcher\ListenerProviderInterface;
use scripts\linktitles\UrlEvent;
use scripts\script_base;
use simplehtmldom\HtmlDocument;

class twitter extends script_base {
    public function setEventProvider(ListenerProviderInterface $eventProvider): void
    {
        $eventProvider->addListener($this->handleEvents(...));
    }

    function handleEvents(UrlEvent $event): void
    {
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
                $nitters = [
                    "https://nitter.net/",
                    "https://nitter.ktachibana.party/",
                    "https://nitter.kavin.rocks/",
                    "https://nitter.unixfox.eu/",
                    "https://nitter.moomoo.me/",
                    "https://nitter.mint.lgbt/",
                    "https://nitter.esmailelbob.xyz/",
                    "https://nitter.bird.froth.zone/",
                    "https://nitter.privacydev.net/",
                    "https://nitter.no-logs.com/",
                ];
                $nitters = array_map(fn ($it) => "{$it}$user/status/$id", $nitters);
                $responses = [];
                foreach ($nitters as $nitter) {
                    $responses[] = \Amp\Promise\timeout($client->request(new Request($nitter)), 5000);
                }
                $this->logger->info("starting requests...");
                [$fails, $responses] = yield \Amp\Promise\any($responses);
                $this->logger->info(count($responses) . " requests finished, " . count($fails) . " failed/timedout");
                $success = [];
                foreach($responses as $r) {
                    /** @var Response $r */
                    if($r->getStatus() == 200) {
                        $this->logger->info("200 from {$r->getOriginalRequest()->getUri()}");
                        $success[] = $r;
                    } else {
                        $this->logger->info("failure code {$r->getStatus()} from {$r->getOriginalRequest()->getUri()}");
                    }
                }
                if(count($success) == 0) {
                    $this->logger->notice("no nitters returned 200 OK");
                    return;
                }
                /** @var Response $response */
                $response = array_pop($success);
                $this->logger->info("processing 200 response from " .  $response->getOriginalRequest()->getUri());
                $body = yield $response->getBody()->buffer();
                
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
}
