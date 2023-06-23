<?php
namespace scripts\durendaltv;
use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Options;
use knivey\cmdr\attributes\Syntax;

#[Cmd("live")]
#[CallWrap("Amp\asyncCall")]
function durendaltv($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    try {
        yield async_get_contents("https://live.internetrelaychat.net/live/stream.m3u8");
    } catch (\Exception $e) {
        $bot->pm($args->chan, "https://live.internetrelaychat.net - stream is offline");
        return;
    }

    try {
        $data = yield async_get_contents("https://kvdb.io/4gam6KTdmSsvkDFZxFaUHz/now_playing");
        $bot->pm($args->chan, "https://live.internetrelaychat.net - $data (mpv https://live.internetrelaychat.net/live/stream.m3u8)");
    } catch (\Exception $e) {
        $bot->pm($args->chan, "Error fetching data from https://kvdb.io/4gam6KTdmSsvkDFZxFaUHz/now_playing");
        echo $e->getMessage();
        $bot->pm($args->chan, "https://live.internetrelaychat.net - stream up but error getting now playing (mpv https://live.internetrelaychat.net/live/stream.m3u8)");
    }
}