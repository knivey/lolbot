<?php
namespace scripts\reddit;

global $config;

use Carbon\Carbon;
use Irc\Exception;
use Psr\EventDispatcher\ListenerProviderInterface;
use scripts\linktitles\UrlEvent;
use scripts\script_base;

class reddit extends script_base
{
    public \knivey\reddit\reddit $reddit;
    public function init(): void
    {
        global $config;
        if(!isset($config['reddit']))
            return;
        $this->reddit = new \knivey\reddit\reddit($config['reddit'], "linux:lolbot:v1 (by /u/lolb0tlol)");
    }

    public function setEventProvider(ListenerProviderInterface $eventProvider): void
    {
        $eventProvider->addListener($this->handleEvents(...));
    }

    function handleEvents(UrlEvent $event)
    {
        global $config;
        if ($event->handled)
            return;
        if(!isset($config['reddit']))
            return;

        // in the future we can probably handle all types from the info call and switch based on kind
        $SKIP_URLS = [
            "@^https?://(?:www\.|old\.|pay\.|ssl\.|[a-z]{2}\.)?reddit\.com/r/([\w-]+)/?$@i", //subreddit
            "@^https?://(?:www\.|old\.|pay\.|ssl\.|[a-z]{2}\.)?reddit\.com/u(?:ser)?/([\w-]+)$@i", //user
        ];
        $URLS = [
            "@^https?://(redd\.it|reddit\.com)/(?P<submission>[\w-]+)/?$@i", //short post
            "@^https?://(?P<subdomain>i|preview)\.redd\.it/(?P<image>[^?\s]+)$@i", //image
            "@^https?://v\.redd\.it/([\w-]+)$@i", // video
            "@^https?://(?:www\.)?reddit\.com/gallery/([\w-]+)$@i" //gallery
        ];
        foreach ($SKIP_URLS as $re) {
            if (preg_match($re, $event->url))
                return;
        }
        $matched = false;
        foreach ($URLS as $re) {
            if (preg_match($re, $event->url)) {
                $matched = true;
                break;
            }
        }
        if (!$matched)
            return;

        $event->promises[] = \Amp\call(function () use ($event) {
            try {
                $info = yield $this->reddit->info($event->url);
                if ($info->kind != 'Listing') {
                    echo "reddit->info return was not kind: Listing\n";
                    return;
                }
                if (!isset($info->data->children) || !is_array($info->data->children)) {
                    echo "reddit->info return didnt have data->children array\n";
                    return;
                }
                /*
                 *  t1_	Comment
                    t2_	Account
                    t3_	Link
                    t4_	Message
                    t5_	Subreddit
                    t6_	Award
                 */
                $post = null;
                foreach ($info->data->children as $child) {
                    if ($child->kind == "t3") {
                        $post = $child->data ?? null;
                        break;
                    }
                }
                if ($post === null) {
                    echo "reddit: no link kinds found in children\n";
                    return;
                }
                $date = Carbon::createFromTimestamp($post->created);
                $ago = $date->shortRelativeToNowDiffForHumans(null, 3);
                $ups = number_format($post->ups);
                $downs = number_format($post->downs);
                $reply = "[Reddit $post->subreddit_name_prefixed] $post->title (Posted $ago [+]{$ups} [-]$downs)";
                $reply = html_entity_decode($reply, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $event->reply(str_replace(["\r", "\n"], "  ", $reply));


            } catch (\Exception $e) {
                echo "reddit exception {$e->getMessage()}\n";
                return;
            }
        });
    }
}























