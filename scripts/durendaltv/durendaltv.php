<?php
namespace scripts\durendaltv;

use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Desc;

#[Cmd("live")]
#[Desc("Show status of durendal's live stream")]
function durendaltv($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    try {
        async_get_contents("https://live.internetrelaychat.net/live/stream.m3u8");
    } catch (\Exception $e) {
        $bot->pm($args->chan, "https://live.internetrelaychat.net - stream is offline");
        return;
    }

    try {
        $kvdb_url = "http://live-kvdb.florp.us";
        //$data = async_get_contents("https://kvdb.io/4gam6KTdmSsvkDFZxFaUHz/now_playing");
        $data = async_get_contents($kvdb_url);
        $bot->pm($args->chan, "https://live.internetrelaychat.net - $data (mpv https://live.internetrelaychat.net/live/stream.m3u8)");
    } catch (\Exception $e) {
        $bot->pm($args->chan, "Error fetching data from $kvdb_url");
        echo $e->getMessage();
        $bot->pm($args->chan, "https://live.internetrelaychat.net - stream up but error getting now playing (mpv https://live.internetrelaychat.net/live/stream.m3u8)");
    }
}