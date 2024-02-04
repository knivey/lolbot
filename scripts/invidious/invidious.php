<?php
namespace scripts\invidious;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Psr\EventDispatcher\ListenerProviderInterface;
use scripts\linktitles\UrlEvent;
use scripts\script_base;
use simplehtmldom\HtmlDocument;

class invidious extends script_base
{
    public function setEventProvider(ListenerProviderInterface $eventProvider): void
    {
        $eventProvider->addListener($this->handleEvents(...));
    }

    function handleEvents(UrlEvent $event): void
    {
        if ($event->handled)
            return;
        if (!preg_match("@^https?://[^/]+/watch\?v=.*$@i", $event->url, $m))
            return;
        $URL = '@^((?:https?:)?//)?((?:www|m)\.)?((?:youtube\.com|youtu.be))(/(?:[\w\-]+\?v=|shorts/|embed/|v/)?)([\w\-]+)(\S+)?$@i';
        if (preg_match($URL, $event->url, $m)) {
            return;
        }

        $event->promises[] = \Amp\call(function () use ($event) {
            try {
                $client = HttpClientBuilder::buildDefault();
                $req = new Request($event->url);
                $response = yield $client->request($req);
                $body = yield $response->getBody()->buffer();
                if ($response->getStatus() != 200) {
                    echo "invidious lookup failed with code {$response->getStatus()}\n";
                    var_dump($body);
                    return;
                }

                $html = new HtmlDocument();
                $html->load($body);
                $check = $html->find("meta [property=\"og:site_name\"]", 0)?->attr['content'] ?? "";
                if (!preg_match("/^.* \| Invidious$/", $check)) {
                    echo "url didnt pass invidious check\n";
                    return;
                }
                $title = $html->find("meta [property=\"og:title\"]", 0)?->attr['content'] ?? "?";
                //$views = $html->find("p#views", 0)?->plaintext ?? "?";
                $channel = $html->find("span#channel-name", 0)?->plaintext ?? "?";
                $date = $html->find("p#published-date", 0)?->plaintext ?? "?";
                $vd = $html->find("script#video_data", 0)?->innertext;
                if (!is_string($vd)) {
                    echo "invidious didnt get video data json, aborting\n";
                    return;
                }
                $json = json_decode($vd, flags: JSON_THROW_ON_ERROR);
                $length = Duration_toString($json?->length_seconds ?? 0);
                $rpl = "\x0315,01[\x0300,01I\x0315ꞐꝞI\x0314D\x0300IꝊU\x0315Ꞩ\x0300,01]\x03 $title | $channel | $date | $length";
                $event->reply($rpl);
            } catch (\Exception $e) {
                echo "invidious exception {$e->getMessage()}\n";
                return;
            }
        });
    }
}
