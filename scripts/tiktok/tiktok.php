<?php
namespace scripts\tiktok;

global $config;

use Carbon\Carbon;
use Irc\Exception;
use Crell\Tukio\OrderedProviderInterface;
use scripts\linktitles\UrlEvent;
use scripts\script_base;

class tiktok extends script_base
{
    public function setEventProvider(OrderedProviderInterface $eventProvider): void
    {
        $eventProvider->addListener($this->handleEvents(...));
    }

    function handleEvents(UrlEvent $event)
    {
        global $config;
        if ($event->handled)
            return;

        if (!preg_match("@^https?://(?:www\.)?tiktok\.com/\@[^/]+/[^/]+\??.*$@i", $event->url)) {
            return;
        }
        
        $event->addFuture(\Amp\async(function () use ($event): void {
            try {
                $d = async_get_contents("https://www.tiktok.com/oembed?url={$event->url}");
                $j = json_decode($d, flags: JSON_THROW_ON_ERROR);
                if(
                    !isset($j->author_unique_id) ||
                    !isset($j->title) ||
                    !isset($j->author_name)
                ) {
                    $this->logger->error("bad or imcomplete response from tiktok", ['d' => $d, 'j' => $j]);
                    return;
                }
                $reply = "[TikTok @$j->author_unique_id ($j->author_name)] $j->title";
                //not sure if needed yet
                //$reply = html_entity_decode($reply, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $event->reply(str_replace(["\r", "\n"], "  ", $reply));
            } catch (\Exception $e) {
                echo "tiktok exception {$e->getMessage()}\n";
                return;
            }
        }));
    }
}























