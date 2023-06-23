<?php
namespace artbot_scripts;

use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use simplehtmldom\HtmlDocument;

$bashdb = [];

#[Cmd("bash")]
#[CallWrap("Amp\asyncCall")]
function bash($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs) {
    global $bashdb;
    try {
        yield populateBash();
    } catch( \Exception $e) {
        echo $e->getMessage();
        $bot->pm($args->chan, "bash.org error: {$e->getMessage()}");
        return;
    }
    if(empty($bashdb))
        return;
    $id = array_rand($bashdb);
    $quote = $bashdb[$id];
    unset($bashdb[$id]);
    $head = "\2Bash.org (\2$id\2):";
    $quote = array_map(fn($it) => "  $it", $quote);
    pumpToChan($args->chan, [$head, ...$quote]);
}

function populateBash() {
    return \Amp\call(function () {
        global $bashdb;
        if(!empty($bashdb))
            return;
        $body = yield async_get_contents("http://www.bash.org/?random");
        $html = new HtmlDocument($body);

        $ids = [];
        foreach($html->find("p.quote") as $i) {
            $ids[] = $i->find("a", 0)->plaintext;
        }

        $quotes = [];
        foreach($html->find("p.qt") as $i) {
            $quote = $i->innertext;
            $quote = preg_split('@<br ?/?>@i', $quote);
            $quotes[] = array_filter(array_map(function ($quote) {
                $quote = htmlspecialchars_decode($quote, ENT_QUOTES | ENT_HTML5);
                return trim(str_replace(["\r", "\n"], '', $quote));
            }, $quote));
        }
        if(count($ids) != count($quotes))
            throw new \Exception("Weird data trying to extract quotes.");
        $bashdb = array_combine($ids, $quotes);
        if (count($bashdb) == 0)
            throw new \Exception("Couldn't extract any quotes from site.");
    });
}